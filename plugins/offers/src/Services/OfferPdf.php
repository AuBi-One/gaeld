<?php

namespace Plugins\Offers\Services;

use App\Domains\Organizations\Models\Organization;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Plugins\Offers\Models\Offer;
use Plugins\Offers\Support\ImageBox;
use Plugins\Offers\Support\Layout;

/**
 * The offer document: a Blade view rendered by dompdf, laid out after the Word offer model.
 */
class OfferPdf
{
    public function render(Offer $offer): string
    {
        // Subsetting keeps the embedded font small. Only data: URIs (the logo) and the
        // plugin's font files may be loaded: file:// is confined (chroot) to the fonts
        // folder, so no text of the offer can make dompdf read another file or a URL.
        // dompdf keeps the metrics of the installed @font-face fonts there (created on first use).
        File::ensureDirectoryExists(storage_path('fonts'));

        return Pdf::loadHTML($this->html($offer))->setPaper('A4', 'portrait')
            ->setOption([
                'isFontSubsettingEnabled' => true,
                'isRemoteEnabled' => false,
                'allowedProtocols' => ['data://' => [], 'file://' => []],
                'chroot' => [self::fontsPath()],
            ])
            ->output();
    }

    /** Carlito (SIL OFL 1.1), metric-compatible with Calibri, the font of the Word model. */
    public static function fontsPath(): string
    {
        $path = realpath(__DIR__.'/../../resources/fonts');
        if ($path === false) {
            throw new \RuntimeException('Offer fonts folder missing: plugins/offers/resources/fonts');
        }

        return $path;
    }

    public function html(Offer $offer): string
    {
        $offer->loadMissing('lines');
        $organization = Organization::query()->findOrFail($offer->organization_id);
        $lang = in_array($offer->language, ['fr', 'de', 'it', 'en'], true) ? $offer->language : 'fr';
        $recipient = $offer->recipient ?? [];

        $placeholders = array_map(e(...), [
            '{company}' => (string) ($recipient['company'] ?? ''),
            '{attention}' => (string) ($recipient['attention'] ?? ''),
            '{number}' => $offer->number,
            '{date}' => $offer->offer_date->format('d.m.Y'),
            '{valid_until}' => $offer->valid_until?->format('d.m.Y') ?? '',
            '{total}' => $offer->currency.' '.self::money((string) $offer->total),
        ]);

        return view('offers::pdf', [
            'offer' => $offer,
            'organization' => $organization,
            'recipient' => $recipient,
            'layout' => $layout = Layout::normalize($offer->layout),
            'logo' => $layout['from']['logo'] ? $this->logo($organization) : null,
            'logoSize' => $this->logoSize($organization),
            'intro' => $this->markdown($offer->intro, $placeholders),
            'closing' => $this->markdown($offer->closing, $placeholders),
            't' => fn (string $key, array $replace = []): string => (string) trans('offers::of.'.$key, $replace, $lang),
            'money' => fn (string $value): string => self::money($value),
            'fonts' => 'file://'.self::fontsPath(),
        ])->render();
    }

    /** Swiss format: 1'234.50 */
    public static function money(string $value): string
    {
        return number_format((float) $value, 2, '.', "'");
    }

    public static function quantity(string $value): string
    {
        $formatted = number_format((float) $value, 2, '.', "'");

        return str_ends_with($formatted, '.00') ? substr($formatted, 0, -3) : $formatted;
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function markdown(?string $text, array $placeholders): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        // Placeholders become inert tokens for the markdown pass, then their HTML-escaped
        // values, so a company named "A_B*" is text, not markdown.
        $tokens = [];
        foreach (array_keys($placeholders) as $i => $key) {
            $tokens[$key] = "\u{E000}{$i}\u{E001}";
        }
        // A line break in the text is a line break in the document (as in Word).
        $html = Str::markdown(strtr($text, $tokens), ['html_input' => 'escape', 'allow_unsafe_links' => false, 'renderer' => ['soft_break' => "<br>\n"]]);
        $html = strtr($html, array_combine(array_values($tokens), array_values($placeholders)));

        return (string) preg_replace('/<img\b[^>]*>/i', '', $html);
    }

    /**
     * Width and height (mm) of the logo inside 60 × 24 mm, keeping its aspect ratio
     * (a fixed height alone would let a wide logo overflow the header).
     *
     * @return array{width: float, height: float}
     */
    private function logoSize(Organization $organization): array
    {
        $path = $organization->logo_path;

        return $path && Storage::disk('local')->exists($path)
            ? ImageBox::fit(Storage::disk('local')->path($path), 60, 24)
            : ['width' => 24.0, 'height' => 24.0];
    }

    /** The logo as a data URI, so dompdf never reads files or URLs on its own. */
    private function logo(Organization $organization): ?string
    {
        $path = $organization->logo_path;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }
        $mime = Storage::disk('local')->mimeType($path) ?: '';
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode((string) Storage::disk('local')->get($path));
    }
}

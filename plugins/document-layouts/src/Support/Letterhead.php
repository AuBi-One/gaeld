<?php

namespace Plugins\DocumentLayouts\Support;

use App\Domains\Organizations\Models\Organization;
use Illuminate\Support\Facades\Storage;
use Plugins\Offers\Support\ImageBox;

/**
 * The sender block of the Word models: the organisation's name, address,
 * invoice phone/e-mail and VAT number, so offers, invoices and salary slips
 * share one letterhead.
 */
final class Letterhead
{
    /**
     * @param  list<string>  $addressLines
     */
    private function __construct(
        public readonly string $name,
        public readonly array $addressLines,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?string $vatNumber,
        private readonly ?string $logoPath,
    ) {}

    public static function for(Organization $organization): self
    {
        $country = $organization->country ?? 'CH';

        return new self(
            name: (string) ($organization->legal_name ?: $organization->name),
            addressLines: array_values(array_filter([
                $organization->address,
                trim(($organization->postal_code ?? '').' '.($organization->city ?? '')),
                $country !== 'CH' ? $country : null,
            ], fn (?string $line): bool => $line !== null && trim($line) !== '')),
            phone: self::blankToNull($organization->invoice_phone),
            email: self::blankToNull($organization->invoice_email),
            vatNumber: self::blankToNull($organization->vat_number),
            logoPath: $organization->logo_path,
        );
    }

    /** Absolute path of the logo (PNG or JPEG), or null. */
    public function logoFile(): ?string
    {
        if (! $this->logoPath || ! Storage::disk('local')->exists($this->logoPath)) {
            return null;
        }
        $mime = Storage::disk('local')->mimeType($this->logoPath) ?: '';

        return in_array($mime, ['image/png', 'image/jpeg'], true) ? Storage::disk('local')->path($this->logoPath) : null;
    }

    /**
     * Width and height (mm) of the logo scaled to fit a box, keeping its aspect ratio
     * (a wide logo would otherwise overflow a fixed height in dompdf).
     *
     * @return array{width: float, height: float}|null
     */
    public function logoSize(float $maxWidth, float $maxHeight): ?array
    {
        $file = $this->logoFile();

        return $file === null ? null : ImageBox::fit($file, $maxWidth, $maxHeight);
    }

    /** The logo as a data URI, so dompdf never reads files or URLs on its own. */
    public function logoDataUri(): ?string
    {
        $file = $this->logoFile();
        if ($file === null) {
            return null;
        }

        return 'data:'.(Storage::disk('local')->mimeType((string) $this->logoPath) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($file));
    }

    /** Swiss VAT number with its suffix (CHE-123.456.789 TVA / MWST / IVA). */
    public function vatNumberWithSuffix(string $language): ?string
    {
        if ($this->vatNumber === null) {
            return null;
        }
        if (preg_match('/\b(TVA|MWST|IVA|VAT)\s*$/i', $this->vatNumber) === 1) {
            return $this->vatNumber;
        }

        return $this->vatNumber.' '.(['de' => 'MWST', 'it' => 'IVA', 'en' => 'VAT'][$language] ?? 'TVA');
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}

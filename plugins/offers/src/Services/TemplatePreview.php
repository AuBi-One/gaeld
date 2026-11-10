<?php

namespace Plugins\Offers\Services;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Organizations\Models\Organization;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Plugins\Offers\Models\Offer;
use Plugins\Offers\Models\OfferLine;
use Plugins\Offers\Models\OfferSetting;
use Plugins\Offers\Support\Layout;

/**
 * The PDF a template would give: an unsaved offer to a sample recipient, dated today,
 * with the organisation's defaults (language, currency, default VAT rate, validity).
 * Nothing is stored and no offer number is taken.
 */
class TemplatePreview
{
    public function __construct(private OfferPdf $pdf) {}

    /** @param  array<string, mixed>  $data  validated template fields */
    public function render(string $orgId, array $data): string
    {
        $organization = Organization::query()->findOrFail($orgId);
        $language = in_array($organization->locale, ['fr', 'de', 'it', 'en'], true) ? $organization->locale : 'fr';
        $vat = VatRate::query()->where('organization_id', $orgId)->where('is_active', true)->where('is_default', true)->first();
        $today = Carbon::today();
        $t = fn (string $key): string => (string) trans('offers::of.'.$key, [], $language);

        $offer = new Offer([
            'organization_id' => $orgId,
            'recipient' => [
                'company' => $t('preview_company'),
                'attention' => $t('preview_attention'),
                'email' => 'contact@example.com',
                'address' => $t('preview_address'),
                'postal_code' => '1000',
                'city' => 'Lausanne',
                'country' => 'CH',
                'phone' => null,
            ],
            'title' => $data['title'] ?? null,
            'intro' => $data['intro'] ?? null,
            'closing' => $data['closing'] ?? null,
            'layout' => Layout::normalize($data['layout'] ?? null),
            'offer_date' => $today,
            'valid_until' => $today->copy()->addMonthsNoOverflow(OfferSetting::for($orgId)->validity_months),
            'language' => $language,
            'currency' => $organization->currency ?: 'CHF',
            'vat_rate' => $vat !== null ? (string) $vat->rate : null,
        ]);
        $offer->number = config('offers.number_prefix', 'OF').'-'.$today->year.'-NNN';

        // Same arithmetic as Offers::syncLines: amount = qty × price, VAT per line.
        $subtotal = '0.00';
        $vatTotal = '0.00';
        $lines = collect(array_values($data['lines'] ?? []))->map(function (array $line, int $i) use ($vat, &$subtotal, &$vatTotal): OfferLine {
            $isItem = $line['type'] === OfferLine::TYPE_ITEM;
            $quantity = $isItem ? Money::round((string) $line['quantity']) : '0.00';
            $price = $isItem ? Money::round((string) $line['unit_price']) : '0.00';
            $amount = Money::multiply2($quantity, $price);
            $lineVat = $isItem && $vat !== null ? Money::percentage($amount, (string) $vat->rate) : '0.00';
            $subtotal = Money::add($subtotal, $amount);
            $vatTotal = Money::add($vatTotal, $lineVat);

            return new OfferLine([
                'sort' => $i,
                'type' => $isItem ? OfferLine::TYPE_ITEM : OfferLine::TYPE_TEXT,
                'label' => $line['label'] ?? null,
                'description' => (string) $line['description'],
                'quantity' => $quantity,
                'unit' => $isItem ? ($line['unit'] ?? null) : null,
                'unit_price' => $price,
                'amount' => $amount,
                'vat_amount' => $lineVat,
            ]);
        });
        $offer->setRelation('lines', $lines);
        $offer->subtotal = $subtotal;
        $offer->vat_amount = $vatTotal;
        $offer->total = Money::add($subtotal, $vatTotal);

        return $this->pdf->render($offer);
    }
}

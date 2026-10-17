@php
    /** @var \Plugins\Offers\Models\Offer $offer */
    // Layout after AuBi-One's Word offer model (MODELE_OFFRE.docx): Calibri-compatible
    // Carlito, logo in the header, sender left / recipient right, title and date,
    // subject, description box, one bordered table with VAT and total, closing, signature.
    $orgName = $organization->legal_name ?? $organization->name;
    $senderLines = array_filter([
        ...($layout['from']['address'] ? [
            $organization->address,
            trim(($organization->postal_code ?? '').' '.($organization->city ?? '')),
        ] : []),
        $layout['from']['phone'] ? $organization->invoice_phone : null,
    ]);
    $senderEmail = $layout['from']['email'] ? $organization->invoice_email : null;
    $vatNumber = \App\Support\Pdf\VatNumber::withSuffix($organization->vat_number, $offer->language);
    $recipientLines = array_filter([
        $recipient['attention'] ?? null,
        ...($layout['to']['address'] ? [
            $recipient['address'] ?? null,
            trim(($recipient['postal_code'] ?? '').' '.($recipient['city'] ?? '')),
            ($recipient['country'] ?? 'CH') !== 'CH' ? ($recipient['country'] ?? null) : null,
        ] : []),
        $layout['to']['phone'] ? ($recipient['phone'] ?? null) : null,
        $layout['to']['email'] ? ($recipient['email'] ?? null) : null,
    ]);
    $vatRate = $offer->vat_rate !== null ? rtrim(rtrim((string) $offer->vat_rate, '0'), '.') : null;
@endphp
<!DOCTYPE html>
<html lang="{{ $offer->language }}">
<head>
<meta charset="utf-8">
<title>{{ $t('pdf_offer') }} {{ $offer->number }}</title>
<style>
    @font-face { font-family: 'Carlito'; font-style: normal; font-weight: normal; src: url('{{ $fonts }}/Carlito-Regular.ttf') format('truetype'); }
    @font-face { font-family: 'Carlito'; font-style: normal; font-weight: bold; src: url('{{ $fonts }}/Carlito-Bold.ttf') format('truetype'); }
    @font-face { font-family: 'Carlito'; font-style: italic; font-weight: normal; src: url('{{ $fonts }}/Carlito-Italic.ttf') format('truetype'); }
    @font-face { font-family: 'Carlito'; font-style: italic; font-weight: bold; src: url('{{ $fonts }}/Carlito-BoldItalic.ttf') format('truetype'); }
    @page { margin: 12.7mm 19mm 20mm 19mm; }
    body { font-family: 'Carlito', 'DejaVu Sans', sans-serif; font-size: 11pt; color: #000; line-height: 0.91; }
    /* dompdf multiplies a unitless line-height by the font height (1.34 em for Carlito): 0.91 ≈ Word single spacing (1.22 em). */
    p { margin: 0; }
    .header { height: 31mm; }
    table.addresses { width: 100%; border-collapse: collapse; }
    table.addresses td { vertical-align: top; padding: 0; }
    td.recipient { width: 71mm; }
    a.mail { color: #1155CC; text-decoration: underline; }
    table.title { margin-top: 17.5mm; border-collapse: collapse; }
    table.title td { padding: 0 2mm 0 0; vertical-align: baseline; }
    .offer-number { font-size: 14pt; font-weight: bold; width: 49mm; }
    table.subject { margin-top: 7mm; border-collapse: collapse; font-weight: bold; }
    table.subject td { padding: 0; vertical-align: top; }
    table.subject td.label { width: 36mm; }
    .description { margin-top: 1mm; background: #EFEFEF; font-style: italic; font-size: 10pt; padding: 0.8mm 2mm; }
    .description p + p { margin-top: 2mm; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 8mm; margin-bottom: 3mm; font-size: 11pt; }
    table.items td, table.items th { border: 0.35mm solid #D9D9D9; padding: 0.9mm 2mm; vertical-align: top; }
    table.items th { font-size: 11pt; font-weight: bold; text-align: left; background: #EFEFEF; }
    table.items .num { text-align: right; }
    table.items tr.total td { font-size: 11pt; font-weight: bold; }
    table.items tr.text td.text { font-style: italic; }
    .num { text-align: right; white-space: nowrap; }
    .closing { margin-top: 6mm; font-size: 10pt; }
    .closing p + p { margin-top: 4mm; }
    .signature { margin-top: 7mm; font-size: 13pt; font-weight: bold; }
</style>
</head>
<body>
<div class="header">
    @if($logo)<img src="{{ $logo }}" alt="" style="width: {{ $logoSize['width'] }}mm; height: {{ $logoSize['height'] }}mm">@endif
</div>

<table class="addresses">
    <tr>
        <td>
            <div>{{ $orgName }}</div>
            @foreach($senderLines as $line)<div>{{ $line }}</div>@endforeach
            @if($senderEmail)<div><a class="mail" href="mailto:{{ $senderEmail }}">{{ $senderEmail }}</a></div>@endif
            @if($vatNumber)<div>{{ $t('pdf_vat_no') }} : {{ $vatNumber }}</div>@endif
        </td>
        <td class="recipient">
            <div>{{ $recipient['company'] ?? '' }}</div>
            @foreach($recipientLines as $line)<div>{{ $line }}</div>@endforeach
        </td>
    </tr>
</table>

<table class="title">
    <tr>
        <td class="offer-number">{{ $t('pdf_offer') }} {{ $offer->number }}</td>
        <td>{{ $t('pdf_date') }} {{ $offer->offer_date->format('d.m.Y') }}</td>
    </tr>
</table>

<table class="subject"><tr><td class="label">{{ $t('pdf_subject') }} :</td><td>{{ $offer->title }}</td></tr></table>
@if($intro)<div class="description">{!! $intro !!}</div>@endif

<table class="items">
    <thead>
        <tr>
            <th style="width: 13.9mm">{{ $t('pdf_pos') }}</th>
            <th>{{ $t('pdf_description') }}</th>
            <th class="num" style="width: 20.6mm">{{ $t('pdf_quantity') }}</th>
            <th class="num" style="width: 31.4mm">{{ $t('pdf_unit_price') }}</th>
            <th class="num" style="width: 33.9mm">{{ $t('pdf_amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($offer->lines as $line)
            @if($line->isItem())
                <tr>
                    <td>{{ $line->label }}</td>
                    <td>{!! nl2br(e($line->description)) !!}</td>
                    <td class="num">{{ \Plugins\Offers\Services\OfferPdf::quantity((string) $line->quantity) }}@if($line->unit) {{ $line->unit }}@endif</td>
                    <td class="num">{{ $money((string) $line->unit_price) }}</td>
                    <td class="num">{{ $money((string) $line->amount) }}</td>
                </tr>
            @else
                <tr class="text">
                    <td>{{ $line->label }}</td>
                    <td class="text" colspan="4">{!! nl2br(e($line->description)) !!}</td>
                </tr>
            @endif
        @endforeach
        @if($vatRate !== null)
            <tr>
                <td></td>
                <td>{{ $t('pdf_vat_label') }}</td>
                <td class="num">{{ $vatRate }} %</td>
                <td></td>
                <td class="num">{{ $money((string) $offer->vat_amount) }}</td>
            </tr>
        @endif
        <tr class="total">
            <td></td>
            <td>{{ $vatRate !== null ? $t('pdf_total_incl_vat') : $t('pdf_total') }}</td>
            <td></td>
            <td></td>
            <td class="num">{{ $offer->currency }} {{ $money((string) $offer->total) }}</td>
        </tr>
    </tbody>
</table>

@if($closing)<div class="closing">{!! $closing !!}</div>@endif

<div class="signature">{{ $orgName }}</div>
</body>
</html>

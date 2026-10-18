@php
    /** @var \Plugins\DocumentLayouts\Support\Letterhead $letterhead */
    // Layout after AuBi-One's Word salary slip model (MODELE_SALAIRE.docx): Carlito (Calibri
    // metrics), logo in the header, sender left / employee right, title with the month,
    // date and AVS number, one bordered table, payment details.
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
<meta charset="utf-8">
<title>{{ $t('slip_title') }} {{ $month }} — {{ $employeeName }}</title>
<style>
    @font-face { font-family: 'Carlito'; font-style: normal; font-weight: normal; src: url('{{ $fonts }}/Carlito-Regular.ttf') format('truetype'); }
    @font-face { font-family: 'Carlito'; font-style: normal; font-weight: bold; src: url('{{ $fonts }}/Carlito-Bold.ttf') format('truetype'); }
    @font-face { font-family: 'Carlito'; font-style: italic; font-weight: normal; src: url('{{ $fonts }}/Carlito-Italic.ttf') format('truetype'); }
    @page { margin: 12.7mm 19.05mm 25.4mm 19.05mm; }
    body { font-family: 'Carlito', 'DejaVu Sans', sans-serif; font-size: 11pt; color: #000; line-height: 0.91; }
    /* dompdf multiplies a unitless line-height by the font height (1.34 em for Carlito): 0.91 ≈ Word single spacing. */
    .header { height: 29mm; }
    .footer { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 8pt; color: #646464; }
    table { border-collapse: collapse; }
    table.addresses { width: 100%; }
    table.addresses td { vertical-align: top; padding: 0; }
    /* The model places the employee with tabs (12.49 mm stops): 106.5 mm from the page edge. */
    td.employee { width: 84.45mm; }
    .employee-name { padding-left: 23mm; font-size: 14pt; font-weight: bold; }
    a.mail { color: #1155CC; text-decoration: underline; }
    table.title { margin-top: 18.5mm; }
    table.title td { padding: 0; vertical-align: baseline; }
    table.title td.label { width: 49.96mm; }   /* the model tabs to the 4th stop (No AVS row); all rows aligned there */
    table.title .big { font-size: 14pt; font-weight: bold; }
    table.slip { width: 172.5mm; margin-top: 8mm; margin-bottom: 3mm; font-size: 12pt; }
    table.slip td { border: 0.35mm solid #CCCCCC; padding: 0.9mm 1.76mm; height: 3.5mm; vertical-align: top; }
    table.slip td.num { text-align: right; white-space: nowrap; }
    table.slip tr.head td { background: #EFEFEF; font-weight: bold; }
    table.slip tr.head td.small { font-size: 9pt; }
    table.slip tr.bold td { font-weight: bold; }
    table.slip tr.net td { background: #EFEFEF; font-weight: bold; font-size: 13pt; }
    table.slip td.insurance { font-size: 11pt; }
    table.payment { margin-top: 5mm; font-size: 13pt; }
    table.payment td { padding: 0; vertical-align: top; }
    table.payment td.label { width: 49.96mm; font-weight: bold; }
</style>
</head>
<body>
<div class="header">
    @if($logo)<img src="{{ $logo }}" alt="" style="width: {{ $logoSize['width'] }}mm; height: {{ $logoSize['height'] }}mm">@endif
</div>

<table class="addresses">
    <tr>
        <td>
            <div>{{ $letterhead->name }}</div>
            @foreach($letterhead->addressLines as $line)<div>{{ $line }}</div>@endforeach
            @if($letterhead->phone)<div>{{ $letterhead->phone }}</div>@endif
            @if($letterhead->email)<div><a class="mail" href="mailto:{{ $letterhead->email }}">{{ $letterhead->email }}</a></div>@endif
        </td>
        <td class="employee">
            <div class="employee-name">{{ $employeeName }}</div>
        </td>
    </tr>
</table>

<table class="title">
    <tr><td class="label big">{{ $t('slip_title') }}</td><td class="big">{{ $month }}</td></tr>
    <tr><td class="label">{{ $t('date') }}</td><td>{{ $date }}</td></tr>
    @if($ahvNumber)<tr><td class="label">{{ $t('ahv_number') }}</td><td>{{ $ahvNumber }}</td></tr>@endif
</table>

<table class="slip">
    <tr class="head">
        <td style="width: 64.88mm">{{ $t('position') }}</td>
        <td class="num small" style="width: 24.98mm">{{ $t('employer_rate') }}</td>
        <td class="num small" style="width: 24.18mm">{{ $t('employee_rate') }}</td>
        <td class="num" style="width: 44.38mm">{{ $t('amount') }}</td>
    </tr>
    @foreach($earnings as $row)
        <tr><td>{{ $row['label'] }}</td><td></td><td></td><td class="num">{{ $row['amount'] }}</td></tr>
    @endforeach
    <tr class="bold"><td>{{ $t('gross_salary') }}</td><td></td><td></td><td class="num">{{ $gross }}</td></tr>
    <tr><td></td><td></td><td></td><td></td></tr>

    <tr class="bold"><td>{{ $t('social_charges') }}</td><td></td><td></td><td></td></tr>
    @foreach($charges as $row)
        <tr>
            <td class="insurance">{{ $row['label'] }}</td>
            <td class="num">{{ $row['employer_rate'] }}</td>
            <td class="num">{{ $row['employee_rate'] }}</td>
            <td class="num">{{ $row['amount'] }}</td>
        </tr>
    @endforeach
    <tr class="bold">
        <td>{{ $t('total_charges') }}</td>
        <td class="num">{{ $totalCharges['employer_rate'] }}</td>
        <td class="num">{{ $totalCharges['employee_rate'] }}</td>
        <td class="num">{{ $totalCharges['amount'] }}</td>
    </tr>
    @if($sourceTax)
        <tr><td>{{ $t('source_tax') }}</td><td></td><td class="num">{{ $sourceTax['rate'] }}</td><td class="num">{{ $sourceTax['amount'] }}</td></tr>
    @endif

    @if($other !== [])
        <tr><td></td><td></td><td></td><td></td></tr>
        <tr class="bold"><td>{{ $t('other') }}</td><td></td><td></td><td></td></tr>
        @foreach($other as $row)
            <tr><td>{{ $row['label'] }}</td><td></td><td></td><td class="num">{{ $row['amount'] }}</td></tr>
        @endforeach
    @endif
    <tr><td></td><td></td><td></td><td></td></tr>
    <tr class="net"><td>{{ $t('net_salary') }}</td><td></td><td></td><td class="num">{{ $net }}</td></tr>
</table>

@if($iban)
<table class="payment">
    <tr><td class="label">{{ $t('payment') }} :</td><td>IBAN {{ $iban }}</td></tr>
</table>
@endif
@if($footer)<div class="footer">{{ $footer }}</div>@endif
</body>
</html>

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('exports.salary_certificate.title') }} — {{ $certificate['employee']->fullName() }}</title>
    @include('exports._styles')
</head>
<body>
    @include('exports._header', [
        'docTitle' => __('exports.salary_certificate.title'),
        'docPeriod' => (string) $certificate['year'],
        'docRef' => $certificate['employee']->fullName(),
    ])

    <div class="notice">{{ __('exports.salary_certificate.disclaimer') }}</div>

    <div class="section">
        <div class="section-title">{{ __('exports.salary_certificate.employee') }}</div>
        <table>
            <tr><td style="width:30%;">{{ __('exports.salary_certificate.name') }}</td><td>{{ $certificate['employee']->fullName() }}</td></tr>
            <tr><td>{{ __('exports.salary_certificate.address') }}</td><td>{{ $certificate['employee']->address }}, {{ trim($certificate['employee']->postal_code.' '.$certificate['employee']->city) }}@if($certificate['employee']->country && $certificate['employee']->country !== 'CH'), {{ $certificate['employee']->country }}@endif</td></tr>
            <tr><td>{{ __('exports.salary_certificate.date_of_birth') }}</td><td>{{ $certificate['employee']->date_of_birth?->format('d.m.Y') }}</td></tr>
            <tr><td>{{ __('exports.salary_certificate.ahv_number') }}</td><td>{{ $certificate['employee']->ahv_number }}</td></tr>
            <tr><td>{{ __('exports.salary_certificate.period') }}</td><td>{{ __('exports.salary_certificate.period_value', ['from' => \Illuminate\Support\Carbon::parse($certificate['period_from'])->format('d.m.Y'), 'to' => \Illuminate\Support\Carbon::parse($certificate['period_to'])->format('d.m.Y')]) }}</td></tr>
            <tr><td>{{ __('exports.salary_certificate.months_covered') }}</td><td>{{ $certificate['months_covered'] }}</td></tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">{{ __('exports.salary_certificate.employee_totals') }}</div>
        <table>
            <tr><td>{{ __('exports.salary_certificate.gross_salary') }}</td><td class="right">{{ number_format((float) $certificate['gross_salary'], 2, '.', "'") }}</td></tr>
            <tr><td>{{ __('exports.salary_certificate.social_contributions') }}</td><td class="right">-{{ number_format((float) $certificate['social_contributions'], 2, '.', "'") }}</td></tr>
            <tr><td>{{ __('exports.salary_certificate.pension_contributions') }}</td><td class="right">-{{ number_format((float) $certificate['pension_contributions'], 2, '.', "'") }}</td></tr>
            <tr class="total-row"><td>{{ __('exports.salary_certificate.certificate_net_salary') }}</td><td class="right">{{ number_format((float) $certificate['certificate_net_salary'], 2, '.', "'") }}</td></tr>
            @foreach($certificate['remarks'] as $remark)
                <tr><td>{{ $remark['name'] }}</td><td class="right">-{{ number_format((float) $remark['amount'], 2, '.', "'") }}</td></tr>
            @endforeach
            @if(bccomp($certificate['other_deductions'], '0', 2) !== 0)
                <tr><td>{{ __('exports.salary_certificate.other_deductions') }}</td><td class="right">-{{ number_format((float) $certificate['other_deductions'], 2, '.', "'") }}</td></tr>
            @endif
            <tr><td>{{ __('exports.salary_certificate.source_tax') }}</td><td class="right">-{{ number_format((float) $certificate['source_tax'], 2, '.', "'") }}</td></tr>
            <tr class="total-row"><td>{{ __('exports.salary_certificate.net_salary') }}</td><td class="right">{{ number_format((float) $certificate['net_salary'], 2, '.', "'") }}</td></tr>
        </table>
    </div>

    @if($certificate['remarks'] !== [])
        <div class="section">
            <div class="section-title">{{ __('exports.salary_certificate.remarks') }}</div>
            <table>
                @foreach($certificate['remarks'] as $remark)
                    <tr><td>{{ __('exports.salary_certificate.remark_employee_contribution', ['name' => $remark['name']]) }}</td><td class="right">CHF {{ number_format((float) $remark['amount'], 2, '.', "'") }}</td></tr>
                @endforeach
            </table>
        </div>
    @endif

    <div class="section">
        <div class="section-title">{{ __('exports.salary_certificate.additional_payments') }}</div>
        <table>
            <tr><td>{{ __('exports.salary_certificate.reimbursements') }}</td><td class="right">{{ number_format((float) $certificate['reimbursements'], 2, '.', "'") }}</td></tr>
            <tr class="net-row"><td>{{ __('exports.salary_certificate.total_paid') }}</td><td class="right">{{ number_format((float) $certificate['total_paid'], 2, '.', "'") }}</td></tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">{{ __('exports.salary_certificate.employer_charges') }}</div>
        <table>
            <tr><td>{{ __('exports.salary_certificate.total_employer_charges') }}</td><td class="right">{{ number_format((float) $certificate['employer_charges'], 2, '.', "'") }}</td></tr>
        </table>
    </div>

    @include('exports._footer')
</body>
</html>
@php
    $employeeData = $employeeData ?? $slip->employeeDocumentData();
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('exports.salary_slip.title') }} — {{ $employeeData['first_name'] }} {{ $employeeData['last_name'] }}</title>
    @include('exports._styles')
</head>
<body>
    @include('exports._header', [
        'docTitle' => __('exports.salary_slip.title'),
        'docPeriod' => str_pad($slip->period_month, 2, '0', STR_PAD_LEFT).'/'.$slip->period_year,
        'docRef' => $employeeData['first_name'].' '.$employeeData['last_name'],
    ])

    <div class="section">
        <div class="section-title">{{ __('exports.salary_slip.employee') }}</div>
        <table class="employee-info">
            <tr>
                <td style="width:34%;">
                    <span class="info-label">{{ __('exports.salary_slip.name') }}</span>
                    {{ $employeeData['first_name'] }} {{ $employeeData['last_name'] }}
                    @foreach(\App\Domains\Payroll\Models\SalarySlip::addressLines($employeeData) as $addressLine)
                        <br>{{ $addressLine }}
                    @endforeach
                </td>
                <td style="width:33%;">
                    <span class="info-label">{{ __('exports.salary_slip.ahv_number') }}</span>
                    {{ $employeeData['ahv_number'] ?: '—' }}
                </td>
                <td style="width:33%;">
                    <span class="info-label">{{ __('exports.salary_slip.iban') }}</span>
                    {{ $employeeData['iban'] ?: '—' }}
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">{{ __('exports.salary_slip.salary') }}</div>
        <table>
            @if(isset($slip->adjustments['base_salary']) && bccomp($slip->adjustments['base_salary'], $slip->gross_salary, 2) !== 0)
                <tr><td>{{ __('exports.salary_slip.base_salary') }}</td><td class="right">{{ number_format((float) $slip->adjustments['base_salary'], 2, '.', "'") }}</td></tr>
            @endif
            @if(isset($slip->adjustments['thirteenth_salary']) && bccomp($slip->adjustments['thirteenth_salary'], '0', 2) > 0)
                <tr><td>{{ __('exports.salary_slip.thirteenth_salary') }}</td><td class="right">{{ number_format((float) $slip->adjustments['thirteenth_salary'], 2, '.', "'") }}</td></tr>
            @endif
            @if(isset($slip->adjustments['unpaid_leave_amount']) && bccomp($slip->adjustments['unpaid_leave_amount'], '0', 2) > 0)
                <tr><td>{{ __('exports.salary_slip.unpaid_leave') }}</td><td class="right">-{{ number_format((float) $slip->adjustments['unpaid_leave_amount'], 2, '.', "'") }}</td></tr>
            @endif
            <tr>
                <td>{{ __('exports.salary_slip.gross_salary') }}</td>
                <td class="right">{{ number_format((float) $slip->gross_salary, 2, '.', "'") }}</td>
            </tr>
        </table>
    </div>

    @if(isset($slip->adjustments['reimbursement_amount']) && bccomp($slip->adjustments['reimbursement_amount'], '0', 2) > 0)
        <div class="section">
            <table>
                <tr>
                    <td>{{ __('exports.salary_slip.expense_reimbursement') }}</td>
                    <td class="right">{{ number_format((float) $slip->adjustments['reimbursement_amount'], 2, '.', "'") }}</td>
                </tr>
            </table>
        </div>
    @endif

    <div class="section">
        <div class="section-title">{{ __('exports.salary_slip.social_charges') }}</div>
        <table>
            @php $deductions = $slip->deductions; @endphp
            <tr>
                <th>{{ __('exports.salary_slip.name') }}</th>
                <th class="r">{{ __('exports.salary_slip.employer_share') }}</th>
                <th class="r">{{ __('exports.salary_slip.employee_share') }}</th>
            </tr>
            @foreach($slip->deductionRows() as $row)
                @if(bccomp($row['employer'], '0', 2) > 0 || bccomp($row['employee'], '0', 2) > 0)
                    <tr>
                        <td>{{ $row['name'] }}</td>
                        <td class="right">{{ bccomp($row['employer'], '0', 2) > 0 ? number_format((float) $row['employer'], 2, '.', "'") : '—' }}</td>
                        <td class="right">{{ bccomp($row['employee'], '0', 2) > 0 ? '-'.number_format((float) $row['employee'], 2, '.', "'") : '—' }}</td>
                    </tr>
                @endif
            @endforeach
            @php $sourceTax = $deductions['source_tax'] ?? $slip->source_tax_amount ?? '0.00'; @endphp
            @if(bccomp((string) $sourceTax, '0', 2) > 0)
                <tr>
                    <td>{{ __('exports.salary_slip.source_tax') }}</td>
                    <td class="right">—</td>
                    <td class="right">-{{ number_format((float) $sourceTax, 2, '.', "'") }}</td>
                </tr>
            @endif
            <tr class="total-row">
                <td>{{ __('exports.salary_slip.total_social_charges') }}</td>
                <td class="right">{{ number_format((float) ($deductions['total_employer'] ?? '0'), 2, '.', "'") }}</td>
                <td class="right">-{{ number_format((float) ($deductions['total_employee'] ?? '0'), 2, '.', "'") }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <table>
            <tr class="net-row">
                <td>{{ __('exports.salary_slip.net_salary') }}</td>
                <td class="right">{{ number_format((float) $slip->net_salary, 2, '.', "'") }}</td>
            </tr>
        </table>
    </div>

    @include('exports._footer')
</body>
</html>

<?php

namespace App\Domains\Payroll\Requests;

use App\Domains\Payroll\Models\SalarySlip;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PayrollAdjustmentRules
{
    /**
     * @return array<int, mixed>
     */
    public static function unpaidLeaveDays(Request $request): array
    {
        return [
            'nullable',
            'integer',
            'min:0',
            function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                $month = (int) $request->input('month');
                $year = (int) $request->input('year');

                if ($month < 1 || $month > 12 || $year < 1) {
                    return;
                }

                $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;
                if ((int) $value > $daysInMonth) {
                    $fail(__('validation.max.numeric', [
                        'attribute' => __('app.unpaid_leave_days'),
                        'max' => $daysInMonth,
                    ]));
                }
            },
        ];
    }

    /**
     * The date a run's entries are posted on: within the payroll month (a
     * typo would otherwise land the salary in an already settled VAT period,
     * which the ledger does not refuse).
     *
     * @return array<int, mixed>
     */
    public static function postingDate(Request $request): array
    {
        return [
            // bail: the closure parses the date, so it must not see a malformed one.
            'bail',
            'nullable',
            'date_format:Y-m-d',
            function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                $month = (int) $request->input('month');
                $year = (int) $request->input('year');

                if ($month >= 1 && $month <= 12 && $year >= 1 && ! self::withinMonth((string) $value, $month, $year)) {
                    $fail(__('app.payroll_posting_date_outside_period'));
                }
            },
        ];
    }

    /**
     * The same bound for a slip that knows its own period.
     *
     * @return array<int, mixed>
     */
    public static function postingDateForSlip(SalarySlip $slip): array
    {
        return [
            // bail: the closure parses the date, so it must not see a malformed one.
            'bail',
            'nullable',
            'date_format:Y-m-d',
            function (string $attribute, mixed $value, Closure $fail) use ($slip): void {
                if (! self::withinMonth((string) $value, $slip->period_month, $slip->period_year)) {
                    $fail(__('app.payroll_posting_date_outside_period'));
                }
            },
        ];
    }

    public static function withinMonth(string $date, int $month, int $year): bool
    {
        $start = Carbon::create($year, $month, 1)->toDateString();
        $end = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $day = Carbon::parse($date)->toDateString();

        return $day >= $start && $day <= $end;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function for(Request $request, string $organizationId): array
    {
        $selectedEmployeeIds = $request->input('employee_ids', []);
        $adjustmentEmployeeRules = [
            'required',
            'uuid',
            Rule::exists('employees', 'id')->where('organization_id', $organizationId),
        ];

        if (is_array($selectedEmployeeIds) && $selectedEmployeeIds !== []) {
            $adjustmentEmployeeRules[] = Rule::in($selectedEmployeeIds);
        }

        return [
            'adjustments' => ['nullable', 'array', 'max:500'],
            'adjustments.*.employee_id' => $adjustmentEmployeeRules,
            'adjustments.*.unpaid_leave_days' => self::unpaidLeaveDays($request),
            'adjustments.*.reimbursement_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'adjustments.*.reimbursement_item_ids' => ['nullable', 'array', 'max:200'],
            'adjustments.*.reimbursement_item_ids.*' => ['string', 'max:64', 'distinct'],
        ];
    }
}

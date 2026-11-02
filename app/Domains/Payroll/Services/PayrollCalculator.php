<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Payroll\Contracts\SourceTaxServiceInterface;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Queries\DeductionRateSetQuery;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Calculates employee salary slips including gross-to-net computation,
 * Swiss social deductions, and pro-rata handling for partial months.
 */
class PayrollCalculator
{
    public function __construct(
        private SwissDeductionService $deductionService,
        private SourceTaxServiceInterface $sourceTax,
    ) {}

    // ──────────────────────────────────────────────────────────────
    //  Calculation
    // ──────────────────────────────────────────────────────────────

    /**
     * Calculate salary for a given employee and period.
     * Handles pro-rata for partial months (entry/exit mid-month).
     *
     * $reimbursementAmount is the manual amount (booked on the general expense
     * account); $reimbursementItems are items already resolved by the
     * ReimbursementSourceInterface and are added to it.
     *
     * @param  list<array{id: string, date: string, label: string, amount: string, account_code: string}>  $reimbursementItems
     */
    public function calculate(
        Employee $employee,
        int $month,
        int $year,
        int $unpaidLeaveDays = 0,
        string $reimbursementAmount = '0.00',
        array $reimbursementItems = [],
    ): SalarySlip {
        $period = Carbon::create($year, $month, 1);
        $baseSalary = $this->proRataGross($employee, $month, $year);
        if ($unpaidLeaveDays < 0 || $unpaidLeaveDays > $period->daysInMonth) {
            throw new \InvalidArgumentException('Unpaid leave days must be within the selected calendar month.');
        }

        $unpaidLeaveAmount = $unpaidLeaveDays > 0
            ? Money::divideRounded(
                Money::multiply($baseSalary, (string) $unpaidLeaveDays),
                (string) $period->daysInMonth,
            )
            : '0.00';
        $thirteenthSalary = $employee->has_thirteenth_salary && $month === 12
            ? $baseSalary
            : '0.00';

        if (! is_numeric($reimbursementAmount) || (float) $reimbursementAmount < 0) {
            throw new \InvalidArgumentException('Reimbursement amount must be a non-negative number.');
        }

        $reimbursementAmount = Money::normalize($reimbursementAmount);
        foreach ($reimbursementItems as $item) {
            $reimbursementAmount = Money::add($reimbursementAmount, $item['amount']);
        }
        $grossSalary = Money::subtract(
            Money::add($baseSalary, $thirteenthSalary),
            $unpaidLeaveAmount,
        );

        // The set whose code matches the employee's and whose date range
        // covers this payroll period is resolved automatically — the same
        // code can point to a different set (different rates) each year
        // without anything changing on the employee record. Throws when the
        // organization has sets but none applies; null (built-in defaults)
        // only when it has no set at all.
        $set = DeductionRateSetQuery::forEmployee($employee, $period->toDateString());
        $rates = $set?->rates()->where('is_active', true)->get();

        $deductions = $this->deductionService->calculateDeductions($grossSalary, $rates);
        $deductions['base_salary'] = $baseSalary;
        $deductions['thirteenth_salary'] = $thirteenthSalary;
        $deductions['unpaid_leave_days'] = $unpaidLeaveDays;
        $deductions['unpaid_leave_amount'] = $unpaidLeaveAmount;
        $deductions['reimbursement_amount'] = $reimbursementAmount;
        $deductions['net_salary'] = Money::add($deductions['net_salary'], $reimbursementAmount);

        $slip = new SalarySlip([
            'employee_id' => $employee->id,
            'organization_id' => $employee->organization_id,
            'period_month' => $month,
            'period_year' => $year,
            'gross_salary' => $grossSalary,
            'net_salary' => $deductions['net_salary'],
            'deductions' => $deductions,
            'deduction_snapshot' => [
                'set_id' => $set?->id,
                'set_code' => $set?->code,
                'lines' => DeductionRateSetQuery::snapshotLines($rates),
            ],
            'adjustments' => [
                'base_salary' => $baseSalary,
                'thirteenth_salary' => $thirteenthSalary,
                'unpaid_leave_days' => $unpaidLeaveDays,
                'unpaid_leave_amount' => $unpaidLeaveAmount,
                'reimbursement_amount' => $reimbursementAmount,
                ...($reimbursementItems !== [] ? ['reimbursement_items' => $reimbursementItems] : []),
            ],
            'employee_snapshot' => [
                'first_name' => (string) $employee->first_name,
                'last_name' => (string) $employee->last_name,
                'email' => $employee->email,
                'ahv_number' => $employee->ahv_number
                    ? Crypt::encryptString($employee->ahv_number)
                    : null,
                'ahv_number_encrypted' => true,
                'iban' => $employee->iban
                    ? Crypt::encryptString($employee->iban)
                    : null,
                'iban_encrypted' => true,
                'address' => $employee->address,
                'postal_code' => $employee->postal_code,
                'city' => $employee->city,
                'country' => $employee->country,
            ],
        ]);

        $this->sourceTax->applyToSlip($slip, $employee);
        $sourceTaxAmount = Money::normalize((string) ($slip->source_tax_amount ?? '0.00'));
        $deductions['source_tax'] = $sourceTaxAmount;
        $deductions['net_salary'] = Money::subtract($deductions['net_salary'], $sourceTaxAmount);

        $slip->forceFill([
            'net_salary' => $deductions['net_salary'],
            'deductions' => $deductions,
        ]);

        return $slip;
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Calculate pro-rata gross salary for partial months.
     */
    private function proRataGross(Employee $employee, int $month, int $year): string
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth()->startOfDay();
        $totalDays = $periodStart->daysInMonth;

        $effectiveStart = $periodStart->copy();
        $effectiveEnd = $periodEnd->copy();

        if ($employee->entry_date->greaterThan($periodEnd)
            || ($employee->exit_date && $employee->exit_date->lessThan($periodStart))) {
            return Money::zero();
        }

        // Employee started mid-month
        if ($employee->entry_date->greaterThan($periodStart) && $employee->entry_date->lessThanOrEqualTo($periodEnd)) {
            $effectiveStart = $employee->entry_date->copy();
        }

        // Employee exited mid-month
        if ($employee->exit_date && $employee->exit_date->greaterThanOrEqualTo($periodStart) && $employee->exit_date->lessThan($periodEnd)) {
            $effectiveEnd = $employee->exit_date->copy();
        }

        $workedDays = $effectiveStart->diffInDays($effectiveEnd) + 1;

        if ($workedDays >= $totalDays) {
            return $employee->gross_salary;
        }

        // Pro-rata: gross * workedDays / totalDays
        return Money::divideRounded(
            Money::multiply($employee->gross_salary, (string) $workedDays),
            (string) $totalDays,
        );
    }
}

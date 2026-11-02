<?php

namespace App\Domains\Payroll\Actions;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Support\Money;
use Illuminate\Support\Collection;

final class GenerateSalaryCertificateAction
{
    /**
     * @return array{
     *     employee: Employee,
     *     organization: Organization,
     *     year: int,
     *     months_covered: int,
     *     gross_salary: string,
     *     social_contributions: string,
     *     pension_contributions: string,
     *     other_deductions: string,
     *     source_tax: string,
     *     reimbursements: string,
     *     net_salary: string,
     *     total_paid: string,
     *     employer_charges: string,
     * }
     */
    public function execute(Employee $employee, int $year): array
    {
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException('Salary certificate year is outside the supported range.');
        }

        $employee->loadMissing('organization');

        $slips = SalarySlip::query()
            ->where('employee_id', $employee->id)
            ->where('organization_id', $employee->organization_id)
            ->where('period_year', $year)
            ->whereNotNull('posted_at')
            ->orderBy('period_month')
            ->get();

        $grossSalary = $this->sumSlipValues($slips, 'gross_salary');
        $sourceTax = $this->sumDeduction($slips, 'source_tax');
        $reimbursements = $this->sumAdjustment($slips, 'reimbursement_amount');
        $employeeDeductions = $this->sumDeduction($slips, 'total_employee');
        $employeeDeductions = Money::add($employeeDeductions, $sourceTax);
        $netSalary = Money::subtract($grossSalary, $employeeDeductions);
        $totalPaid = Money::add($netSalary, $reimbursements);
        $boxes = $this->sumEmployeeChargesByBox($slips);

        return [
            'employee' => $employee,
            'organization' => $employee->organization,
            'year' => $year,
            'months_covered' => $slips->pluck('period_month')->unique()->count(),
            'gross_salary' => $grossSalary,
            'social_contributions' => $boxes['9'],
            'pension_contributions' => $boxes['10'],
            'other_deductions' => $boxes['other'],
            'source_tax' => $sourceTax,
            'reimbursements' => $reimbursements,
            'net_salary' => $netSalary,
            'total_paid' => $totalPaid,
            'employer_charges' => $this->sumDeduction($slips, 'total_employer'),
        ];
    }

    /**
     * Every employee charge of the slips, summed by the certificate box its
     * rate line counts in (9: AVS/AI/APG/AC/AANP, 10: LPP), whatever its code;
     * charges without a box are "other". The box comes from the slip's
     * snapshot; codes without a line (e.g. imported slips) use the box of
     * their legacy prefix.
     *
     * @param  Collection<int, SalarySlip>  $slips
     * @return array{9: string, 10: string, other: string}
     */
    private function sumEmployeeChargesByBox(Collection $slips): array
    {
        $sums = ['9' => Money::zero(), '10' => Money::zero(), 'other' => Money::zero()];
        foreach ($slips as $slip) {
            $lines = $slip->rateLines();
            foreach ($slip->chargeAmounts() as $code => $amount) {
                $line = $lines[$code] ?? null;
                $type = $line['type'] ?? (str_ends_with($code, '_employer') ? 'employer' : 'employee');
                if ($type !== 'employee') {
                    continue;
                }
                $box = $line !== null ? $line['certificate_box'] : DeductionRate::defaultCertificateBox($code, 'employee');
                $key = in_array($box, DeductionRate::CERTIFICATE_BOXES, true) ? $box : 'other';
                $sums[$key] = Money::add($sums[$key], $amount);
            }
        }

        return $sums;
    }

    /**
     * @param  Collection<int, SalarySlip>  $slips
     */
    private function sumSlipValues(Collection $slips, string $attribute): string
    {
        return $slips->reduce(
            fn (string $total, SalarySlip $slip): string => Money::add($total, (string) $slip->{$attribute}),
            Money::zero(),
        );
    }

    /**
     * @param  Collection<int, SalarySlip>  $slips
     */
    private function sumDeduction(Collection $slips, string $key): string
    {
        return $slips->reduce(
            fn (string $total, SalarySlip $slip): string => Money::add(
                $total,
                (string) ($slip->deductions[$key]
                    ?? ($key === 'source_tax' ? $slip->getAttribute('source_tax_amount') : null)
                    ?? '0.00'),
            ),
            Money::zero(),
        );
    }

    /**
     * @param  Collection<int, SalarySlip>  $slips
     */
    private function sumAdjustment(Collection $slips, string $key): string
    {
        return $slips->reduce(
            fn (string $total, SalarySlip $slip): string => Money::add(
                $total,
                (string) ($slip->adjustments[$key] ?? $slip->deductions[$key] ?? '0.00'),
            ),
            Money::zero(),
        );
    }
}

<?php

namespace App\Domains\Payroll\Actions;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Exceptions\IncompleteSalaryCertificateException;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class GenerateSalaryCertificateAction
{
    /**
     * @return array{
     *     employee: Employee,
     *     organization: Organization,
     *     year: int,
     *     period_from: string,
     *     period_to: string,
     *     months_covered: int,
     *     gross_salary: string,
     *     social_contributions: string,
     *     pension_contributions: string,
     *     certificate_net_salary: string,
     *     other_deductions: string,
     *     remarks: list<array{name: string, amount: string}>,
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

        $missing = self::missingData($employee);
        if ($missing !== []) {
            throw new IncompleteSalaryCertificateException($missing);
        }

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
            ...$this->period($employee, $year),
            'months_covered' => $slips->pluck('period_month')->unique()->count(),
            'gross_salary' => $grossSalary,
            'social_contributions' => $boxes['9'],
            'pension_contributions' => $boxes['10'],
            // Box 11: gross (box 8) minus boxes 9 and 10.
            'certificate_net_salary' => Money::subtract(Money::subtract($grossSalary, $boxes['9']), $boxes['10']),
            'other_deductions' => $boxes['other'],
            'remarks' => $boxes['15'],
            'source_tax' => $sourceTax,
            'reimbursements' => $reimbursements,
            'net_salary' => $netSalary,
            'total_paid' => $totalPaid,
            'employer_charges' => $this->sumDeduction($slips, 'total_employer'),
        ];
    }

    /**
     * Labels of the data a certificate must show that the employee or the
     * employer record still lacks (empty when it can be issued): the
     * employee's AHV number, date of birth and address, the employer's
     * address.
     *
     * @return list<string>
     */
    public static function missingData(Employee $employee): array
    {
        $filled = fn (mixed $value): bool => $value !== null && trim((string) $value) !== '';
        $organization = $employee->organization;
        $present = [
            'app.ahv_number' => $filled($employee->ahv_number),
            'app.date_of_birth' => $employee->date_of_birth !== null,
            'app.address' => $filled($employee->address),
            'app.postal_code' => $filled($employee->postal_code),
            'app.city' => $filled($employee->city),
            'app.salary_certificate_employer_address' => $organization !== null
                && $filled($organization->address) && $filled($organization->postal_code) && $filled($organization->city),
        ];

        $missing = [];
        foreach ($present as $label => $isPresent) {
            if (! $isPresent) {
                $missing[] = (string) __($label);
            }
        }

        return $missing;
    }

    /**
     * The employment period within the year (certificate box "from/to"): from
     * the entry date if the employee joined during the year, to the exit date
     * if they left during it.
     *
     * @return array{period_from: string, period_to: string}
     */
    private function period(Employee $employee, int $year): array
    {
        $yearStart = CarbonImmutable::create($year, 1, 1);
        $yearEnd = CarbonImmutable::create($year, 12, 31);
        $entry = CarbonImmutable::parse($employee->entry_date);
        $exit = $employee->exit_date !== null ? CarbonImmutable::parse($employee->exit_date) : null;

        return [
            'period_from' => ($entry->greaterThan($yearStart) ? $entry : $yearStart)->toDateString(),
            'period_to' => ($exit !== null && $exit->lessThan($yearEnd) ? $exit : $yearEnd)->toDateString(),
        ];
    }

    /**
     * Every employee charge of the slips, summed by the certificate box its
     * rate line counts in (9: AVS/AI/APG/AC/AANP, 10: LPP), whatever its code;
     * box 15 (remarks) charges are listed one per code, under the name of its
     * latest slip (sorted by name), with their amount;
     * charges without a box are "other". The box comes from the slip's
     * snapshot; codes without a line (e.g. imported slips) use the box of
     * their legacy prefix.
     *
     * @param  Collection<int, SalarySlip>  $slips
     * @return array{9: string, 10: string, 15: list<array{name: string, amount: string}>, other: string}
     */
    private function sumEmployeeChargesByBox(Collection $slips): array
    {
        $sums = ['9' => Money::zero(), '10' => Money::zero(), 'other' => Money::zero()];
        /** @var array<string, array{name: string, amount: string}> $remarks */
        $remarks = [];
        foreach ($slips as $slip) {
            $lines = $slip->rateLines();
            foreach ($slip->chargeAmounts() as $code => $amount) {
                $line = $lines[$code] ?? null;
                $type = $line['type'] ?? (str_ends_with($code, '_employer') ? 'employer' : 'employee');
                if ($type !== 'employee') {
                    continue;
                }
                $box = $line !== null ? $line['certificate_box'] : DeductionRate::defaultCertificateBox($code, 'employee');
                if ($box === '15') {
                    // Slips come in period order: the last name seen is the most recent.
                    $remarks[$code] = [
                        'name' => $line['name'] ?? $code,
                        'amount' => Money::add($remarks[$code]['amount'] ?? Money::zero(), $amount),
                    ];

                    continue;
                }
                $key = $box === '9' || $box === '10' ? $box : 'other';
                $sums[$key] = Money::add($sums[$key], $amount);
            }
        }

        $listed = array_values($remarks);
        usort($listed, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return ['9' => $sums['9'], '10' => $sums['10'], '15' => $listed, 'other' => $sums['other']];
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

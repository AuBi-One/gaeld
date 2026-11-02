<?php

namespace App\Domains\Payroll\Actions;

use App\Domains\Payroll\Contracts\ReimbursementSourceInterface;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Queries\DeductionRateSetQuery;
use App\Domains\Payroll\Services\NullReimbursementSource;
use App\Domains\Payroll\Services\PayrollCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generates salary slips for all active employees in a given payroll period.
 */
class GeneratePayrollRunAction
{
    public function __construct(
        private PayrollCalculator $calculator,
        private PostPayrollAction $postAction,
        private ReimbursementSourceInterface $reimbursements = new NullReimbursementSource,
    ) {}

    /**
     * @param  array<int, string>  $employeeIds  Optional subset of employee UUIDs to process. Empty array = all active employees.
     * @param  array<int, array{employee_id: string, unpaid_leave_days?: int|string, reimbursement_amount?: string|int|float, reimbursement_item_ids?: array<int, string>}>  $adjustments
     * @return Collection<int, SalarySlip>
     */
    public function execute(string $orgId, int $month, int $year, bool $shouldPost = false, array $employeeIds = [], array $adjustments = []): Collection
    {
        $employees = $this->employees($orgId, $month, $year, $employeeIds);
        $adjustmentsByEmployee = collect($adjustments)->keyBy('employee_id');

        // Employees who already have a slip for the period are skipped.
        $employees = $employees->reject(fn (Employee $employee): bool => SalarySlip::where('employee_id', $employee->id)
            ->where('period_month', $month)
            ->where('period_year', $year)
            ->exists());

        // Resolve every employee's rate set and items first, so a missing set
        // or an invalid item creates no slip at all.
        $periodDate = Carbon::create($year, $month, 1)->toDateString();
        $items = [];
        foreach ($employees as $employee) {
            DeductionRateSetQuery::forEmployee($employee, $periodDate);
            $items[$employee->id] = $this->resolveItems($employee, $adjustmentsByEmployee->get($employee->id, []), $month, $year);
        }

        // One transaction for the whole run: a calculation or posting error
        // for any employee leaves no slip and no entry of this run behind.
        return DB::transaction(function () use ($employees, $month, $year, $shouldPost, $adjustmentsByEmployee, $items): Collection {
            $slips = collect();
            foreach ($employees as $employee) {
                $exists = SalarySlip::where('employee_id', $employee->id)
                    ->where('period_month', $month)
                    ->where('period_year', $year)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $adjustment = $adjustmentsByEmployee->get($employee->id, []);
                $resolved = $items[$employee->id];
                $slip = DB::transaction(function () use ($employee, $month, $year, $shouldPost, $adjustment, $resolved): SalarySlip {
                    $slip = $this->calculator->calculate(
                        $employee,
                        $month,
                        $year,
                        (int) ($adjustment['unpaid_leave_days'] ?? 0),
                        (string) ($adjustment['reimbursement_amount'] ?? '0.00'),
                        $resolved,
                    );
                    $slip->save();

                    if ($shouldPost) {
                        $this->postAction->execute($slip);
                    }

                    return $slip;
                });

                $slips->push($slip);
            }

            return $slips;
        });
    }

    /**
     * Calculate a payroll preview without persisting salary slips.
     *
     * @param  array<int, string>  $employeeIds
     * @param  array<int, array{employee_id: string, unpaid_leave_days?: int|string, reimbursement_amount?: string|int|float, reimbursement_item_ids?: array<int, string>}>  $adjustments
     * @return Collection<int, SalarySlip>
     */
    public function preview(string $orgId, int $month, int $year, array $employeeIds = [], array $adjustments = []): Collection
    {
        $adjustmentsByEmployee = collect($adjustments)->keyBy('employee_id');

        return $this->employees($orgId, $month, $year, $employeeIds)
            ->map(function (Employee $employee) use ($adjustmentsByEmployee, $month, $year): SalarySlip {
                $adjustment = $adjustmentsByEmployee->get($employee->id, []);

                return $this->calculator->calculate(
                    $employee,
                    $month,
                    $year,
                    (int) ($adjustment['unpaid_leave_days'] ?? 0),
                    (string) ($adjustment['reimbursement_amount'] ?? '0.00'),
                    $this->resolveItems($employee, $adjustment, $month, $year),
                );
            })
            ->values();
    }

    /**
     * Resolve the ticked items; an item dated after the period cannot be paid
     * with this salary (the run screen does not offer it).
     *
     * @param  array<string, mixed>  $adjustment
     * @return list<array{id: string, date: string, label: string, amount: string, account_code: string}>
     */
    private function resolveItems(Employee $employee, array $adjustment, int $month, int $year): array
    {
        $ids = array_values(array_map('strval', (array) ($adjustment['reimbursement_item_ids'] ?? [])));
        if ($ids === []) {
            return [];
        }

        $items = $this->reimbursements->resolve((string) $employee->organization_id, (string) $employee->id, $ids);
        $periodEnd = Carbon::create($year, $month)->endOfMonth()->toDateString();
        foreach ($items as $item) {
            if ($item['date'] > $periodEnd) {
                throw new \DomainException(__('app.reimbursement_item_after_period', ['label' => $item['label'], 'date' => $periodEnd]));
            }
        }

        return $items;
    }

    /**
     * @param  array<int, string>  $employeeIds
     * @return Collection<int, Employee>
     */
    private function employees(string $orgId, int $month, int $year, array $employeeIds): Collection
    {
        $periodStart = Carbon::create($year, $month, 1);
        $periodEnd = $periodStart->copy()->endOfMonth();

        return Employee::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->whereDate('entry_date', '<=', $periodEnd)
            ->where(function ($query) use ($periodStart): void {
                $query->whereNull('exit_date')
                    ->orWhereDate('exit_date', '>=', $periodStart);
            })
            ->when($employeeIds !== [], fn ($query) => $query->whereIn('id', $employeeIds))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();
    }
}

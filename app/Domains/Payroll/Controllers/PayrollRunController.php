<?php

namespace App\Domains\Payroll\Controllers;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Payroll\Actions\GeneratePayrollRunAction;
use App\Domains\Payroll\Contracts\ReimbursementSourceInterface;
use App\Domains\Payroll\Controllers\Concerns\EnsuresPayrollWritable;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Requests\PayrollAdjustmentRules;
use App\Http\Controllers\Controller;
use App\Support\FeatureFlag;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Triggers payroll calculation runs for a given period.
 *
 * Authorizes against Employee::class because no PayrollRun model exists.
 * EmployeePolicy maps viewAny/create to payroll.view/payroll.create permissions.
 */
class PayrollRunController extends Controller
{
    use EnsuresPayrollWritable;

    public function index(Request $request, CurrentOrganization $currentOrg, ReimbursementSourceInterface $reimbursements): Response
    {
        $this->ensurePayrollWritable($currentOrg->get());
        $this->authorize('viewAny', Employee::class);

        $employees = Employee::query()
            ->where('is_active', true)
            ->orderBy('last_name')
            ->get();

        // Next month to pay: the one after the latest salary slip, else the current month. Slips more
        // than a month ahead (a typo such as 2099) are not looked at; one over a year old says nothing.
        $horizon = now()->year * 12 + now()->month + 1;
        $last = SalarySlip::query()
            ->whereRaw('period_year * 12 + period_month <= ?', [$horizon])
            ->orderByDesc('period_year')->orderByDesc('period_month')
            ->first(['period_year', 'period_month']);
        $next = $last !== null ? Carbon::create($last->period_year, $last->period_month, 1)->addMonth() : null;
        if ($next === null || $next->lt(now()->startOfMonth()->subMonths(12))) {
            $next = now()->startOfMonth();
        }

        $currentYear = now()->year;
        $fiscalYears = range(max($currentYear, $next->year), $currentYear - 4);

        return Inertia::render('Payroll/Run', [
            'employees' => $employees,
            'fiscalYears' => $fiscalYears,
            'defaultPeriod' => ['month' => $next->month, 'year' => $next->year],
            'defaultPayday' => $currentOrg->get()->payroll_payday,
            'withholdingTaxEnabled' => FeatureFlag::enabledForOrg('withholding_tax', $currentOrg->get()),
            'reimbursementItems' => $employees
                ->mapWithKeys(fn (Employee $employee): array => [
                    $employee->id => $reimbursements->openItems($currentOrg->id(), (string) $employee->id),
                ])
                ->filter()
                ->all(),
        ]);
    }

    public function preview(Request $request, CurrentOrganization $currentOrg, GeneratePayrollRunAction $action): JsonResponse
    {
        $this->ensurePayrollWritable($currentOrg->get());
        $this->authorize('viewAny', Employee::class);

        $validated = $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2000'],
            'employee_ids' => ['nullable', 'array', 'max:500'],
            'employee_ids.*' => [
                'uuid',
                'distinct',
                Rule::exists('employees', 'id')->where('organization_id', $currentOrg->id()),
            ],
            ...PayrollAdjustmentRules::for($request, $currentOrg->id()),
        ]);

        $slips = $action->preview(
            $currentOrg->id(),
            (int) $validated['month'],
            (int) $validated['year'],
            $validated['employee_ids'] ?? [],
            $validated['adjustments'] ?? [],
        );

        return response()->json([
            'data' => $slips->map(fn ($slip): array => [
                'id' => $slip->id,
                'employee_id' => $slip->employee_id,
                'gross_salary' => $slip->gross_salary,
                'net_salary' => $slip->net_salary,
                'deductions' => $slip->deductions,
            ])->values(),
        ]);
    }

    public function generate(Request $request, CurrentOrganization $currentOrg, GeneratePayrollRunAction $action): RedirectResponse|JsonResponse
    {
        $this->ensurePayrollWritable($currentOrg->get());
        $this->authorize('create', Employee::class);

        $validated = $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2000'],
            'post' => ['boolean'],
            'posting_date' => PayrollAdjustmentRules::postingDate($request),
            'employee_ids' => ['nullable', 'array', 'max:500'],
            'employee_ids.*' => [
                'uuid',
                'distinct',
                Rule::exists('employees', 'id')->where('organization_id', $currentOrg->id()),
            ],
            ...PayrollAdjustmentRules::for($request, $currentOrg->id()),
        ]);

        $slips = $action->execute(
            $currentOrg->id(),
            (int) $validated['month'],
            (int) $validated['year'],
            $validated['post'] ?? false,
            $validated['employee_ids'] ?? [],
            $validated['adjustments'] ?? [],
            $validated['posting_date'] ?? null,
        );

        if ($request->wantsJson()) {
            return response()->json(['count' => $slips->count(), 'slip_ids' => $slips->pluck('id')]);
        }

        return redirect()->route('payroll.salarySlips.index')
            ->with('success', __('app.payroll_run_completed', ['count' => $slips->count()]));
    }
}

<?php

namespace App\Domains\Payroll\Controllers;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Payroll\Controllers\Concerns\EnsuresPayrollWritable;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\DeductionRateSet;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Queries\DeductionRateSetQuery;
use App\Domains\Payroll\Requests\DuplicateDeductionRateSetRequest;
use App\Domains\Payroll\Requests\StoreDeductionRateSetRequest;
use App\Domains\Payroll\Requests\UpdateDeductionRateSetRequest;
use App\Domains\Payroll\Services\SwissDeductionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deduction rate sets ("barèmes") — a named, dated header an employee's
 * `deduction_rate_code` resolves to at payroll time. Own full-width page
 * under Payroll ("Salaires" → Charges sociales).
 */
class DeductionRateSetController extends Controller
{
    use EnsuresPayrollWritable;

    public function index(CurrentOrganization $currentOrg): Response
    {
        $this->ensurePayrollWritable($currentOrg->get());
        $this->authorize('viewAny', DeductionRateSet::class);

        return Inertia::render('Payroll/DeductionRates', [
            'deductionRateSets' => DeductionRateSetQuery::all(),
            // Only liabilities: the mapped account is credited, the employer
            // share being debited to the social charges expense.
            'deductionRateAccounts' => Account::where('is_active', true)
                ->where('type', AccountType::Liability->value)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name]),
            'canManage' => request()->user()?->can('manage', DeductionRateSet::class) ?? false,
        ]);
    }

    public function store(StoreDeductionRateSetRequest $request, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->ensurePayrollWritable($currentOrg->get());
        $this->authorize('manage', DeductionRateSet::class);

        $validated = $request->validated();
        DB::transaction(function () use ($validated, $currentOrg): void {
            $this->closeSuperseded($currentOrg->id(), $validated['code'], $validated['date_from'], $validated['date_to'] ?? null);
            DeductionRateSet::create([
                'organization_id' => $currentOrg->id(),
                ...$validated,
            ]);
        });

        return redirect()->route('payroll.deduction-rates')
            ->with('success', __('app.deduction_rate_set_created'));
    }

    /**
     * Create the STANDARD set from the built-in defaults (for an organization
     * without any set yet) and give it to every employee without a code, so
     * their payroll keeps calculating exactly as before.
     */
    public function seed(CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->ensurePayrollWritable($currentOrg->get());
        $this->authorize('manage', DeductionRateSet::class);

        $organizationId = $currentOrg->id();
        if (! DeductionRateSet::where('organization_id', $organizationId)->exists()) {
            DB::transaction(fn () => self::seedDefaults($organizationId));
        }

        return redirect()->route('payroll.deduction-rates')
            ->with('success', __('app.deduction_rate_set_created'));
    }

    public function update(UpdateDeductionRateSetRequest $request, DeductionRateSet $deductionRateSet, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->ensurePayrollWritable($currentOrg->get());
        $this->authorize('manage', DeductionRateSet::class);

        $deductionRateSet->update($request->validated());

        return redirect()->route('payroll.deduction-rates')
            ->with('success', __('app.deduction_rate_set_updated'));
    }

    /**
     * A set cannot be deleted while employees use its code unless another
     * set of that code covers today or the open-ended future: otherwise
     * their next payroll would have no rates.
     */
    public function destroy(DeductionRateSet $deductionRateSet, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->ensurePayrollWritable($currentOrg->get());
        $this->authorize('manage', DeductionRateSet::class);

        $today = Carbon::today()->toDateString();
        $stillCovered = DeductionRateSet::where('code', $deductionRateSet->code)
            ->whereKeyNot($deductionRateSet->id)
            ->where(fn ($query) => $query
                ->whereNull('date_to')
                ->orWhere(fn ($covers) => $covers->where('date_from', '<=', $today)->where('date_to', '>=', $today)))
            ->exists();
        $employees = $stillCovered
            ? 0
            : Employee::where('deduction_rate_code', $deductionRateSet->code)->count();
        if ($employees > 0) {
            return redirect()->route('payroll.deduction-rates')
                ->with('error', __('app.deduction_rate_set_in_use', ['code' => $deductionRateSet->code, 'count' => $employees]));
        }

        $deductionRateSet->delete();

        return redirect()->route('payroll.deduction-rates')
            ->with('success', __('app.deduction_rate_set_deleted'));
    }

    /**
     * Create a new period for the same code, copying all of the source
     * set's lines (rate, type, account, certificate box) — the quick way to
     * "roll over" a barème to a new year before adjusting the numbers that
     * changed. An open-ended set of the code is closed the day before.
     */
    public function duplicate(DuplicateDeductionRateSetRequest $request, DeductionRateSet $deductionRateSet, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->ensurePayrollWritable($currentOrg->get());
        $this->authorize('manage', DeductionRateSet::class);

        $validated = $request->validated();

        DB::transaction(function () use ($validated, $deductionRateSet, $currentOrg): void {
            $this->closeSuperseded($currentOrg->id(), $deductionRateSet->code, $validated['date_from'], $validated['date_to'] ?? null);

            $newSet = DeductionRateSet::create([
                'organization_id' => $currentOrg->id(),
                'code' => $deductionRateSet->code,
                'title' => $validated['title'],
                'date_from' => $validated['date_from'],
                'date_to' => $validated['date_to'] ?? null,
            ]);

            foreach ($deductionRateSet->rates as $rate) {
                $newSet->rates()->create([
                    'organization_id' => $currentOrg->id(),
                    'name' => $rate->name,
                    'code' => $rate->code,
                    'rate' => $rate->rate,
                    'type' => $rate->type,
                    'account_id' => $rate->account_id,
                    'certificate_box' => $rate->certificate_box,
                    'is_active' => $rate->is_active,
                ]);
            }
        });

        return redirect()->route('payroll.deduction-rates')
            ->with('success', __('app.deduction_rate_set_duplicated'));
    }

    /**
     * A new open-ended set starting on $from takes over from the open-ended
     * set of the same code, which then ends the day before.
     */
    private function closeSuperseded(string $organizationId, string $code, string $from, ?string $to): void
    {
        DeductionRateSetQuery::supersededBy($organizationId, $code, $from, $to)?->update([
            'date_to' => Carbon::parse($from)->subDay()->toDateString(),
        ]);
    }

    /**
     * Seed an open-ended "STANDARD" set from the built-in defaults, starting
     * with the organisation's earliest slip period (else the current year), and give it to the employees without a
     * code. Mirrors ExpenseCategoryController's seedDefaults.
     */
    public static function seedDefaults(string $organizationId): void
    {
        $set = DeductionRateSet::withoutGlobalScopes()->create([
            'organization_id' => $organizationId,
            'code' => 'STANDARD',
            'title' => 'Standard',
            'date_from' => DeductionRateSetQuery::earliestPayrollStart($organizationId),
            'date_to' => null,
        ]);

        foreach (SwissDeductionService::defaults() as $default) {
            $set->rates()->create([
                'organization_id' => $organizationId,
                'name' => $default['name'],
                'code' => $default['code'],
                'rate' => $default['rate'],
                'type' => $default['type'],
                'certificate_box' => DeductionRate::defaultCertificateBox($default['code'], $default['type']),
                'is_active' => true,
            ]);
        }

        Employee::withTrashed()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNull('deduction_rate_code')
            ->update(['deduction_rate_code' => 'STANDARD']);
    }
}

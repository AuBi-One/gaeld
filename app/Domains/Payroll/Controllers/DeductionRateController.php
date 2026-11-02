<?php

namespace App\Domains\Payroll\Controllers;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Payroll\Controllers\Concerns\EnsuresPayrollWritable;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\DeductionRateSet;
use App\Domains\Payroll\Requests\StoreDeductionRateRequest;
use App\Domains\Payroll\Requests\UpdateDeductionRateRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * Lines (AVS, LPP, LAA, APGM, allocations familiales, ...) of a deduction
 * rate set, each with its own employee/employer percentage and an optional
 * chart-of-accounts mapping. Shown and edited on the "Charges sociales" page under Payroll.
 */
class DeductionRateController extends Controller
{
    use EnsuresPayrollWritable;

    public function store(StoreDeductionRateRequest $request, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->ensurePayrollWritable($currentOrg->get());
        $this->authorize('manage', DeductionRateSet::class);

        $validated = $request->validated();

        DeductionRate::create([
            'organization_id' => $currentOrg->id(),
            'deduction_rate_set_id' => $validated['deduction_rate_set_id'],
            'name' => $validated['name'],
            'code' => $validated['code'],
            'rate' => $validated['rate'],
            'type' => $validated['type'],
            'account_id' => $validated['account_id'] ?? null,
            'certificate_box' => $validated['type'] === 'employee' ? ($validated['certificate_box'] ?? null) : null,
            'is_active' => true,
        ]);

        return redirect()->route('payroll.deduction-rates')
            ->with('success', __('app.deduction_rate_created'));
    }

    public function update(UpdateDeductionRateRequest $request, DeductionRate $deductionRate, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->ensurePayrollWritable($currentOrg->get());
        $this->authorize('manage', DeductionRateSet::class);

        $validated = $request->validated();
        if (($validated['type'] ?? $deductionRate->type) !== 'employee') {
            $validated['certificate_box'] = null;
        }
        $deductionRate->update($validated);

        return redirect()->route('payroll.deduction-rates')
            ->with('success', __('app.deduction_rate_updated'));
    }

    public function destroy(DeductionRate $deductionRate, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->ensurePayrollWritable($currentOrg->get());
        $this->authorize('manage', DeductionRateSet::class);

        $deductionRate->delete();

        return redirect()->route('payroll.deduction-rates')
            ->with('success', __('app.deduction_rate_deleted'));
    }
}

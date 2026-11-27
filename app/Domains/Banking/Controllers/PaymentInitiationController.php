<?php

namespace App\Domains\Banking\Controllers;

use App\Domains\Banking\DTOs\PaymentInstructionData;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Services\PaymentInitiationService;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Payroll\Models\SalarySlip;
use App\Http\Controllers\Concerns\HandlesFlashErrorResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\DownloadPaymentBatchRequest;
use App\Http\Requests\DownloadSalaryPaymentBatchRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Outbound payments: aggregates payable expenses and produces a pain.001
 * batch (download in CE, bLink push in EE) for the user's e-banking.
 */
class PaymentInitiationController extends Controller
{
    use HandlesFlashErrorResponses;

    public function index(CurrentOrganization $currentOrg, PaymentInitiationService $service): InertiaResponse
    {
        $this->authorize('viewAny', BankAccount::class);

        $expenses = $service->pendingExpenses($currentOrg->get())
            ->map(fn ($e) => [
                'id' => $e->id,
                'date' => $e->date->toDateString(),
                'description' => $e->description ?? $e->vendor ?? $e->category,
                'vendor' => $e->vendor,
                'supplier' => $e->supplier ? [
                    'id' => $e->supplier->id,
                    'name' => $e->supplier->name,
                    'account_holder' => $e->supplier->account_holder,
                    'iban' => $e->supplier->iban,
                    // SPS 2025: the bank rejects a creditor without town and country.
                    'has_address' => PaymentInstructionData::hasPostalAddress($e->supplier->city, $e->supplier->country),
                ] : null,
                'amount' => (string) $e->amount,
                'currency' => $e->currency,
                'status' => $e->status->value,
            ])
            ->values();

        $bankAccounts = BankAccount::query()
            ->where('organization_id', $currentOrg->id())
            ->where('is_active', true)
            ->whereNotNull('iban')
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'iban', 'currency'])
            ->map(fn (BankAccount $ba) => [
                'id' => $ba->id,
                'uuid' => $ba->uuid,
                'name' => $ba->name,
                'iban' => $ba->iban,
                'currency' => $ba->currency,
            ])
            ->values();

        // Salaries (every employee's net pay and IBAN) only for those who may see all of payroll.
        $canSeeSalaries = request()->user()?->hasPermissionTo(Permission::PayrollView) ?? false;
        $canPaySalaries = $canSeeSalaries && request()->user()->hasPermissionTo(Permission::PayrollEdit);
        $salary = fn (SalarySlip $slip): array => self::salaryRow($slip);

        return Inertia::render('Banking/PaymentsOutgoing', [
            'expenses' => $expenses,
            'bankAccounts' => $bankAccounts,
            'canPaySalaries' => $canPaySalaries,
            'salaryBankAccountIds' => $bankAccounts->where('currency', 'CHF')->pluck('id')->values(),
            'salarySlips' => $canSeeSalaries ? $service->pendingSalarySlips($currentOrg->get())->map($salary)->values() : [],
            'paidSalarySlips' => $canSeeSalaries ? $service->recentlyPaidSalarySlips($currentOrg->get())->map($salary)->values() : [],
        ]);
    }

    public function download(
        DownloadPaymentBatchRequest $request,
        PaymentInitiationService $service,
    ): RedirectResponse|Response {
        $validated = $request->validated();

        /** @var BankAccount $debtor */
        $debtor = BankAccount::query()->findOrFail($validated['bank_account_id']);
        $this->authorize('view', $debtor);

        try {
            $result = $service->prepareBatch(
                $debtor,
                $validated['expense_ids'],
                isset($validated['execution_date']) ? Carbon::parse($validated['execution_date']) : null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->backWithError($e);
        }

        if ($result->download !== null) {
            return $result->download;
        }

        return redirect()->route('payments.outgoing.index')
            ->with('success', __('app.payments_outgoing_submitted', [
                'count' => $result->count,
                'id' => $result->remoteBatchId,
            ]));
    }

    public function downloadSalaries(DownloadSalaryPaymentBatchRequest $request, PaymentInitiationService $service): RedirectResponse|Response
    {
        $validated = $request->validated();

        /** @var BankAccount $debtor */
        $debtor = BankAccount::query()->findOrFail($validated['bank_account_id']);
        $this->authorize('view', $debtor);

        try {
            $result = $service->prepareSalaryBatch($debtor, $validated['salary_slip_ids'], Carbon::parse($validated['execution_date']));
        } catch (\InvalidArgumentException $e) {
            // To the payments page (also from the payroll run): the salaries are listed there with what is missing.
            return redirect()->route('payments.outgoing.index')->with('error', $e->getMessage());
        }

        return $result->download ?? redirect()->route('payments.outgoing.index');
    }

    /**
     * Put a salary back on the list (e.g. the bank rejected the file).
     */
    public function reopenSalary(Request $request, SalarySlip $slip): RedirectResponse
    {
        $this->authorize('viewAny', BankAccount::class);
        abort_unless($request->user()->hasPermissionTo(Permission::PayrollEdit), 403);
        // Only a slip put into a file here: those paid before payment files existed stay paid.
        abort_if($slip->payment_file_ref === null, 422);
        $slip->forceFill(['payment_file_at' => null, 'payment_file_ref' => null])->save();

        return redirect()->route('payments.outgoing.index');
    }

    /**
     * @return array<string, mixed>
     */
    private static function salaryRow(SalarySlip $slip): array
    {
        $payee = $slip->payeeData();

        return [
            'id' => $slip->id,
            'period' => sprintf('%04d-%02d', $slip->period_year, $slip->period_month),
            'posting_date' => $slip->posting_date?->toDateString(),
            'employee' => $payee['name'],
            'iban' => $payee['iban'],
            'has_address' => PaymentInstructionData::hasPostalAddress($payee['city'], $payee['country']),
            'amount' => (string) $slip->net_salary,
            'payment_file_at' => $slip->payment_file_at?->toDateString(),
        ];
    }
}

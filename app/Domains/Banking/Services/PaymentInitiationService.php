<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Contracts\PaymentInitiationProviderInterface;
use App\Domains\Banking\DTOs\PaymentInitiationResult;
use App\Domains\Banking\DTOs\PaymentInstructionData;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Expenses\Enums\ExpenseStatus;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\SalarySlip;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Aggregates payable items (expenses with a supplier IBAN) and delegates
 * pain.001 generation / submission to the configured provider.
 *
 * Provider is selected per BankAccount via the bound implementation of
 * PaymentInitiationProviderInterface (FilePain001Provider in CE,
 * BlinkPaymentProvider in EE Phase 2).
 */
class PaymentInitiationService
{
    public function __construct(
        private readonly PaymentInitiationProviderInterface $provider,
    ) {}

    /**
     * Expenses ready to be paid: status pending/approved, supplier with IBAN,
     * not yet linked to a journal entry/payment.
     *
     * @return Collection<int, Expense>
     */
    public function pendingExpenses(Organization $organization): Collection
    {
        return Expense::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', [ExpenseStatus::Pending->value, ExpenseStatus::Approved->value])
            ->whereNull('journal_entry_id')
            ->whereHas('supplier', fn ($q) => $q->whereNotNull('iban'))
            ->with('supplier:id,name,account_holder,iban,bic,address,postal_code,city,country')
            ->orderBy('date')
            ->get();
    }

    /**
     * Build a payment batch from the selected expense ids and delegate.
     *
     * @param  string[]  $expenseIds
     */
    public function prepareBatch(
        BankAccount $debtor,
        array $expenseIds,
        ?Carbon $executionDate = null,
    ): PaymentInitiationResult {
        $expenses = Expense::query()
            ->where('organization_id', $debtor->organization_id)
            ->whereIn('id', $expenseIds)
            ->with('supplier:id,name,account_holder,iban,bic,address,postal_code,city,country')
            ->get();

        if ($expenses->isEmpty()) {
            throw new \InvalidArgumentException('No expenses selected for payment.');
        }

        $noAddress = $expenses
            ->filter(fn (Expense $e) => $e->supplier !== null
                && ! PaymentInstructionData::hasPostalAddress($e->supplier->city, $e->supplier->country))
            ->map(fn (Expense $e) => $e->supplier->name)
            ->unique();
        if ($noAddress->isNotEmpty()) {
            throw new \InvalidArgumentException(
                __('app.payment_supplier_address_missing', ['names' => $noAddress->implode(', ')])
            );
        }

        /** @var PaymentInstructionData[] $instructions */
        $instructions = $expenses
            ->map(fn (Expense $e) => PaymentInstructionData::fromExpense($e, $executionDate))
            ->all();

        return $this->provider->initiate($debtor, $instructions);
    }

    /**
     * Posted salary slips whose net pay is not yet in a payment file (posting the payroll already
     * booked the payment from the bank; only the transfer is missing).
     *
     * @return Collection<int, SalarySlip>
     */
    public function pendingSalarySlips(Organization $organization): Collection
    {
        return SalarySlip::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('posted_at')
            ->whereNull('payment_file_at')
            ->where('net_salary', '>', 0)
            ->orderBy('period_year')->orderBy('period_month')
            ->get();
    }

    /**
     * Slips put into a payment file in the last 60 days (they can be put back on the list).
     *
     * @return Collection<int, SalarySlip>
     */
    public function recentlyPaidSalarySlips(Organization $organization): Collection
    {
        return SalarySlip::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('payment_file_ref')
            ->where('payment_file_at', '>=', Carbon::now()->subDays(60))
            ->orderByDesc('payment_file_at')
            ->get();
    }

    /**
     * One salary batch (category purpose SALA, never mixed with supplier payments); the slips are
     * marked as being in a payment file.
     *
     * @param  string[]  $slipIds
     */
    public function prepareSalaryBatch(BankAccount $debtor, array $slipIds, Carbon $executionDate): PaymentInitiationResult
    {
        if ($debtor->currency !== 'CHF') {
            throw new \InvalidArgumentException(__('app.payments_salary_chf_only'));
        }

        // Locked so two clicks cannot put the same salary into two files.
        return DB::transaction(fn (): PaymentInitiationResult => $this->salaryBatch($debtor, $slipIds, $executionDate));
    }

    /**
     * @param  string[]  $slipIds
     */
    private function salaryBatch(BankAccount $debtor, array $slipIds, Carbon $executionDate): PaymentInitiationResult
    {
        $slips = SalarySlip::query()
            ->where('organization_id', $debtor->organization_id)
            ->whereIn('id', $slipIds)
            ->whereNotNull('posted_at')
            ->whereNull('payment_file_at')
            ->where('net_salary', '>', 0)
            ->lockForUpdate()
            ->get();

        if ($slips->count() !== count(array_unique($slipIds))) {
            throw new \InvalidArgumentException(__('app.payments_salaries_not_payable'));
        }

        $locale = $debtor->organization->locale ?: app()->getLocale();
        $noIban = $slips->filter(fn (SalarySlip $slip): bool => blank($slip->payeeData()['iban']))
            ->map(fn (SalarySlip $slip) => $slip->payeeData()['name']);
        if ($noIban->isNotEmpty()) {
            throw new \InvalidArgumentException(__('app.payments_salary_iban_missing', ['names' => $noIban->implode(', ')]));
        }
        $noAddress = $slips->filter(fn (SalarySlip $slip): bool => ! PaymentInstructionData::hasPostalAddress($slip->payeeData()['city'], $slip->payeeData()['country']))
            ->map(fn (SalarySlip $slip) => $slip->payeeData()['name']);
        if ($noAddress->isNotEmpty()) {
            throw new \InvalidArgumentException(__('app.payment_supplier_address_missing', ['names' => $noAddress->implode(', ')]));
        }

        $instructions = $slips->map(function (SalarySlip $slip) use ($executionDate, $locale): PaymentInstructionData {
            $month = Carbon::createFromDate($slip->period_year, $slip->period_month, 1);
            $month->locale($locale);

            return PaymentInstructionData::fromSalarySlip(
                $slip,
                $executionDate,
                __('app.salary_payment_remittance', ['month' => $month->translatedFormat('F Y')], $locale),
            );
        })->all();

        $result = $this->provider->initiate($debtor, $instructions);
        SalarySlip::query()->whereKey($slips->modelKeys())->update([
            'payment_file_at' => Carbon::now(),
            'payment_file_ref' => 'SAL-'.Carbon::now()->format('YmdHis').'-'.strtoupper(Str::random(6)),
        ]);

        return $result;
    }
}

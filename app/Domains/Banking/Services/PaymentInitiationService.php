<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Contracts\PaymentInitiationProviderInterface;
use App\Domains\Banking\DTOs\PaymentInitiationResult;
use App\Domains\Banking\DTOs\PaymentInstructionData;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Expenses\Enums\ExpenseStatus;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

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
            ->with('supplier:id,name,iban,bic,address,postal_code,city,country')
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
            ->with('supplier:id,name,iban,bic,address,postal_code,city,country')
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
}

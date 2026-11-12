<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a sent or overdue invoice without payments and reverses its journal entry.
 */
class CancelInvoiceAction
{
    public function __construct(
        private LedgerService $ledgerService,
    ) {}

    public function execute(Invoice $invoice): Invoice
    {
        if (! $invoice->status->canTransitionTo(InvoiceStatus::Cancelled)) {
            throw new InvalidInvoiceStateException("Cannot cancel an invoice with status: {$invoice->status->value}.");
        }

        // Money already received would stay on the receivable of a cancelled invoice: a credit
        // note settles such an invoice, or its payments are removed first.
        if ($invoice->payments()->exists()) {
            throw new InvalidInvoiceStateException(__('app.invoice_cancel_has_payments'));
        }

        return DB::transaction(function () use ($invoice) {
            if ($invoice->journal_entry_id) {
                $invoice->loadMissing('journalEntry.lines');
                $reversal = $this->ledgerService->reverseEntry(
                    $invoice->journalEntry,
                    "Cancellation of {$invoice->number}",
                );
                // Post the reversal immediately for invoice cancellation
                $this->ledgerService->postDraft($reversal);
            }

            $invoice->update(['status' => InvoiceStatus::Cancelled]);

            if ($invoice->type === InvoiceType::CreditNote && $invoice->related_invoice_id !== null) {
                $this->reopenSettledInvoice($invoice);
            }

            return $invoice->fresh();
        });
    }

    /**
     * A paid invoice whose credit note is cancelled is open again when its payments and the
     * remaining credit notes no longer cover it (a write-off of "nothing left" marked it paid).
     * Like removing a payment, this sets the status directly; the enum transitions are not checked.
     */
    private function reopenSettledInvoice(Invoice $creditNote): void
    {
        $invoice = Invoice::query()->withoutGlobalScopes()
            ->where('organization_id', $creditNote->organization_id)
            ->whereKey($creditNote->related_invoice_id)
            ->lockForUpdate()
            ->first();
        if ($invoice === null || $invoice->status !== InvoiceStatus::Paid) {
            return;
        }

        if (Money::isPositive(Money::subtract($invoice->amountDue(), $invoice->creditedByCreditNotes()))) {
            $isOverdue = $invoice->due_date?->isBefore(now()->startOfDay()) ?? false;
            $invoice->update(['status' => $isOverdue ? InvoiceStatus::Overdue : InvoiceStatus::Sent]);
        }
    }
}

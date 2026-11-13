<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceAccountingService;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a sent or overdue invoice without payments and reverses its journal entry.
 */
class CancelInvoiceAction
{
    public function __construct(
        private LedgerService $ledgerService,
        private InvoiceAccountingService $accountingService,
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

            if ($invoice->type === InvoiceType::CreditNote) {
                $this->accountingService->reopenAfterCreditNoteWithdrawn($invoice);
            }

            return $invoice->fresh();
        });
    }
}

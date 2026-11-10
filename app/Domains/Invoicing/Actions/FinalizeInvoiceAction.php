<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceAccountingService;
use App\Domains\Invoicing\Services\SwissQrInvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finalizes a draft invoice: assigns invoice number, posts journal entry, marks as sent, and
 * stores its PDF as the justificatif (if it has none).
 */
class FinalizeInvoiceAction
{
    public function __construct(
        private InvoiceAccountingService $accountingService,
        private SwissQrInvoiceService $qrService,
        private StoreInvoicePdfAction $storePdf,
    ) {}

    public function execute(Invoice $invoice): Invoice
    {
        if (! $invoice->status->canTransitionTo(InvoiceStatus::Sent)) {
            throw new InvalidInvoiceStateException("Only draft invoices can be finalized (current status: {$invoice->status->value}).");
        }

        if ($invoice->lines()->count() === 0) {
            throw new InvalidInvoiceStateException('Cannot finalize an invoice with no line items.');
        }

        $this->qrService->ensureQrReference($invoice, $invoice->organization);

        $result = $this->accountingService->postToLedger($invoice);

        // Once committed (a caller may hold a transaction): a rolled-back finalisation leaves no
        // file. Never fails the finalisation; a PDF that cannot be rendered is logged.
        // A stored PDF still on the invoice (its move on a revert failed) is replaced.
        DB::afterCommit(fn () => $this->storePdf->execute($result, replaceStored: true));

        Log::info('Invoice finalized', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $result->number,
            'organization_id' => $invoice->organization_id,
            'total' => (string) $result->total,
        ]);

        return $result;
    }
}

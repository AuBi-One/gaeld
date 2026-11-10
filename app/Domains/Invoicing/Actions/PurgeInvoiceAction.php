<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceJournalCleanup;
use App\Domains\Organizations\Services\OrganizationDocumentStorageService;
use Illuminate\Support\Facades\DB;

/**
 * Permanently removes a cancelled or soft-deleted invoice, its line items and its justificatif.
 */
class PurgeInvoiceAction
{
    public function __construct(
        private OrganizationDocumentStorageService $documentStorage,
    ) {}

    public function execute(Invoice $invoice): void
    {
        if (! $invoice->trashed() && $invoice->status->value !== 'cancelled') {
            throw new InvalidInvoiceStateException('Only cancelled or already deleted invoices can be permanently removed.');
        }

        $organization = $invoice->organization;
        $justificatifPath = $invoice->justificatif_path;

        DB::transaction(function () use ($invoice) {
            $this->deleteJournalEntries($invoice);
            $invoice->lines()->forceDelete();
            $invoice->forceDelete();
        });

        // The file and its storage quota go once the invoice is gone (not on a rollback).
        DB::afterCommit(fn () => $this->documentStorage->delete($organization, $justificatifPath));
    }

    private function deleteJournalEntries(Invoice $invoice): void
    {
        InvoiceJournalCleanup::delete($invoice);
    }
}

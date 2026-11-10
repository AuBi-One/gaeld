<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Services\OrganizationDocumentStorageService;
use Illuminate\Support\Facades\DB;

/**
 * Removes an invoice's journal entry with its reversal when the invoice is deleted or purged.
 *
 * The reversal is found by the reference LedgerService::reverseEntry gives it: "REV-" + the
 * reversed entry's own reference. For invoices finalised in Gäld that is the invoice number;
 * for entries written otherwise (e.g. imported invoices) it is not, and looking it up by the
 * number would delete the original and keep its reversal. A posted entry that was not reversed
 * is refused: deleting it would silently remove booked revenue. The entries' own justificatifs
 * (e.g. the invoice PDF moved onto the entry on a revert to draft) go too, once committed.
 */
final class InvoiceJournalCleanup
{
    public static function delete(Invoice $invoice): void
    {
        if (! $invoice->journal_entry_id) {
            return;
        }

        $entry = JournalEntry::where('organization_id', $invoice->organization_id)->find($invoice->journal_entry_id);
        if ($entry === null) {
            return;
        }

        $reversal = JournalEntry::where('organization_id', $invoice->organization_id)
            ->where('reference', 'REV-'.$entry->reference)
            ->first();

        if ($entry->is_posted && $reversal === null) {
            throw new InvalidInvoiceStateException('The invoice\'s journal entry is posted and was not reversed: cancel the invoice first.');
        }

        $paths = array_filter([$entry->justificatif_path, $reversal?->justificatif_path]);

        $reversal?->delete();
        // Cascades to transaction_lines & vat_entries
        $entry->delete();

        if ($paths !== []) {
            $organization = $invoice->organization;
            DB::afterCommit(function () use ($organization, $paths): void {
                foreach (array_unique($paths) as $path) {
                    app(OrganizationDocumentStorageService::class)->delete($organization, $path);
                }
            });
        }
    }
}

<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Services\OrganizationDocumentStorageService;
use Illuminate\Support\Facades\DB;

/**
 * Removes an invoice's journal entries with their reversals when the invoice is deleted or purged:
 * the current one and those of earlier finalizations reverted to draft (Invoice::postings()).
 *
 * A reversal is found by the reference LedgerService::reverseEntry gives it: "REV-" + the
 * reversed entry's own reference. For invoices finalised in Gäld that is the invoice number (or
 * {number}-v2, ...); for entries written otherwise (e.g. imported invoices) it is not, and looking
 * it up by the number would delete the original and keep its reversal. A posted current entry that
 * was not reversed is refused: deleting it would silently remove booked revenue. An earlier entry
 * is removed only together with its reversal. The entries' own justificatifs (e.g. the invoice PDF
 * moved onto the entry on a revert to draft) go too, once committed.
 */
final class InvoiceJournalCleanup
{
    public static function delete(Invoice $invoice): void
    {
        $postings = $invoice->postings();
        $paths = [];

        foreach ($postings as ['entry' => $entry, 'reversal' => $reversal]) {
            $isCurrent = $entry->id === $invoice->journal_entry_id;
            if ($entry->is_posted && $reversal === null) {
                if ($isCurrent) {
                    throw new InvalidInvoiceStateException('The invoice\'s journal entry is posted and was not reversed: cancel the invoice first.');
                }

                continue;
            }
            if (! $isCurrent && $reversal === null) {
                continue;
            }

            $paths[] = $entry->justificatif_path;
            $paths[] = $reversal?->justificatif_path;
        }

        // Reversals first: each one names the entry it reverses. Cascades to transaction_lines & vat_entries.
        foreach ($postings as ['entry' => $entry, 'reversal' => $reversal]) {
            if ($reversal !== null) {
                $reversal->delete();
                $entry->delete();
            } elseif ($entry->id === $invoice->journal_entry_id) {
                $entry->delete();
            }
        }

        $paths = array_values(array_unique(array_filter($paths)));
        if ($paths !== []) {
            $organization = $invoice->organization;
            DB::afterCommit(function () use ($organization, $paths): void {
                foreach ($paths as $path) {
                    app(OrganizationDocumentStorageService::class)->delete($organization, $path);
                }
            });
        }
    }
}

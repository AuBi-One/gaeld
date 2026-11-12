<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Accounting\Services\VatPeriodLockService;
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
 * moved onto the entry on a revert to draft) go too, once committed. Nothing is deleted when one
 * of the entries lies in a closed fiscal year, or carries VAT entries in a settled VAT period: the
 * filed figures would change.
 */
final class InvoiceJournalCleanup
{
    /** @param  list<JournalEntry>  $entries */
    private static function refuseInClosedPeriods(array $entries): void
    {
        $locks = app(VatPeriodLockService::class);
        $ledger = app(LedgerService::class);
        foreach ($entries as $entry) {
            $date = $entry->date->toDateString();
            $settledVat = $locks->isLocked($entry->organization_id, $date)
                && VatEntry::query()->where('journal_entry_id', $entry->id)->exists();
            if ($settledVat || $ledger->isFiscalYearClosedOn($entry->organization_id, $date)) {
                throw new InvalidInvoiceStateException(__('app.invoice_delete_closed_period', ['date' => $entry->date->format('d.m.Y')]));
            }
        }
    }

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

        /** @var list<JournalEntry> $doomed */
        $doomed = [];
        foreach ($postings as ['entry' => $entry, 'reversal' => $reversal]) {
            if ($reversal !== null) {
                $doomed[] = $reversal;
                $doomed[] = $entry;
            } elseif ($entry->id === $invoice->journal_entry_id) {
                $doomed[] = $entry;
            }
        }
        self::refuseInClosedPeriods($doomed);

        // Reversals first: each one names the entry it reverses. Cascades to transaction_lines & vat_entries.
        foreach ($doomed as $entry) {
            $entry->delete();
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

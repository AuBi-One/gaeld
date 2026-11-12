<?php

namespace App\Support\Observers;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Services\LedgerService;
use App\Exceptions\ArchivedRecordException;

/**
 * Extends the archive lock of a journal entry to its lines.
 *
 * {@see LocksArchivedRecord} guards only the entry row, so without this
 * observer the amounts of an archived entry could still be changed, added
 * or removed line by line. Bulk query deletes bypass model events; the
 * callers that use them ({@see LedgerService::deleteDraft},
 * the draft update in the accounting controller) already refuse archived entries.
 */
class LocksArchivedTransactionLine
{
    public function creating(TransactionLine $line): void
    {
        $this->assertEntryNotArchived($line->journal_entry_id);
    }

    public function updating(TransactionLine $line): void
    {
        $this->assertEntryNotArchived($line->getOriginal('journal_entry_id'));
        $this->assertEntryNotArchived($line->journal_entry_id);
    }

    public function deleting(TransactionLine $line): void
    {
        $this->assertEntryNotArchived($line->getOriginal('journal_entry_id'));
    }

    private function assertEntryNotArchived(mixed $journalEntryId): void
    {
        if ($journalEntryId === null) {
            return;
        }

        $archived = JournalEntry::withoutGlobalScopes()
            ->whereKey($journalEntryId)
            ->whereNotNull('archived_at')
            ->exists();

        if ($archived) {
            throw ArchivedRecordException::locked('journal_entry', (string) $journalEntryId);
        }
    }
}

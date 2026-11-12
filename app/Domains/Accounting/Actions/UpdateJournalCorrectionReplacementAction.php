<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\Exceptions\DuplicateReferenceException;
use App\Domains\Accounting\Exceptions\JournalCorrectionException;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Services\LedgerService;
use Illuminate\Support\Facades\DB;

/**
 * Changes the replacement draft of an open correction (header and lines),
 * with the ledger's balance and account checks. The reversal draft is never
 * edited: it is derived from the original.
 */
final class UpdateJournalCorrectionReplacementAction
{
    public function __construct(
        private readonly LedgerService $ledger,
    ) {}

    /**
     * @throws JournalCorrectionException When the correction is no longer a draft
     * @throws DuplicateReferenceException When another entry uses the reference
     */
    public function execute(JournalCorrection $correction, JournalEntryData $data): JournalEntry
    {
        return DB::transaction(function () use ($correction, $data): JournalEntry {
            $locked = JournalCorrection::withoutGlobalScopes()->whereKey($correction->getKey())->lockForUpdate()->firstOrFail();
            $replacement = JournalEntry::withoutGlobalScopes()->whereKey($locked->replacement_journal_entry_id)->lockForUpdate()->firstOrFail();
            if (! $locked->isDraft() || $replacement->is_posted) {
                throw JournalCorrectionException::notDraft();
            }

            $this->ledger->validateDraftLines($replacement->organization_id, $data->lines);
            if ($data->reference !== null && JournalEntry::withoutGlobalScopes()
                ->where('organization_id', $replacement->organization_id)
                ->where('reference', $data->reference)
                ->whereKeyNot($replacement->id)
                ->exists()) {
                throw new DuplicateReferenceException(__('app.journal_reference_taken', ['reference' => $data->reference]));
            }

            $replacement->lines()->delete();
            $replacement->update([
                'date' => $data->date,
                'reference' => $data->reference,
                'description' => $data->description,
            ]);
            foreach ($data->lines as $line) {
                TransactionLine::create([
                    'journal_entry_id' => $replacement->id,
                    'account_id' => $line->accountId,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                    'description' => $line->description,
                ]);
            }

            return $replacement->fresh(['lines.account']) ?? $replacement;
        });
    }
}

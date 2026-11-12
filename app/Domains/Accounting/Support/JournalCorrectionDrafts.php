<?php

namespace App\Domains\Accounting\Support;

use App\Domains\Accounting\DTOs\JournalEntryReference;
use App\Domains\Accounting\Enums\JournalCorrectionStatus;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Services\JournalEntryReferences;

/**
 * The drafts of an open guided correction belong to the correction: like any
 * owned entry they are not edited, deleted or reversed in the journal (the
 * replacement is edited on the correction, the pair is posted or discarded
 * together). Once the correction is posted they are ordinary entries again.
 */
final class JournalCorrectionDrafts
{
    public static function register(JournalEntryReferences $references): void
    {
        $references->register(function (array $entryIds): array {
            $owners = [];
            $corrections = JournalCorrection::withoutGlobalScopes()
                ->with(['original' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'reference', 'description'])])
                ->where('status', JournalCorrectionStatus::Draft->value)
                ->where(fn ($query) => $query
                    ->whereIn('reversal_journal_entry_id', $entryIds)
                    ->orWhereIn('replacement_journal_entry_id', $entryIds))
                ->get();

            foreach ($corrections as $correction) {
                $owner = new JournalEntryReference(
                    __('app.journal_source_correction', ['reference' => $correction->original->reference ?? $correction->original->description ?? '']),
                    route('accounting.journal-entries.show', $correction->replacement_journal_entry_id),
                );
                $owners[(string) $correction->reversal_journal_entry_id] = $owner;
                $owners[(string) $correction->replacement_journal_entry_id] = $owner;
            }

            return array_intersect_key($owners, array_flip($entryIds));
        });
    }
}

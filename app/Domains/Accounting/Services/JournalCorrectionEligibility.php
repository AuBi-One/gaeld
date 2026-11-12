<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Exceptions\JournalCorrectionException;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Support\SystemReferencePrefixes;
use App\Domains\Assets\Models\DepreciationEntry;
use App\Domains\Banking\Models\BankTransaction;
use App\Domains\Expenses\Models\Expense;

/**
 * Whether a posted journal entry may be corrected with the guided correction.
 *
 * Only entries booked by hand (journal, API, migration) qualify. An entry
 * owned by another record (invoice, payment, salary slip, expense-claims
 * records: {@see JournalEntryReferences}; also expenses, bank transactions,
 * depreciation) is corrected through its owner, and so are entries the
 * system writes itself (VAT settlement, closing, opening balances, … by type
 * or by a reserved reference prefix). A reversal is not corrected, nor is an
 * entry already reversed or corrected, nor one with VAT entries (the
 * correction would not carry them).
 */
final class JournalCorrectionEligibility
{
    /** Entry types the system writes for its own workflows. */
    private const SYSTEM_TYPES = [
        'vat_settlement',
        JournalEntry::TYPE_YEAR_END_CLOSING,
        'historical_summary',
        'social_charges',
        'bank_match',
        'reversal',
    ];

    public function __construct(
        private readonly JournalEntryReferences $owners,
        private readonly SystemReferencePrefixes $systemPrefixes,
    ) {}

    /** Why $entry cannot be corrected (shown to the user), or null when it can. */
    public function refusal(JournalEntry $entry): ?string
    {
        if (! $entry->is_posted) {
            return __('app.journal_correction_reason_not_posted');
        }
        if ($entry->archived_at !== null) {
            return __('app.journal_correction_reason_archived');
        }

        $owner = $this->owners->for($entry);
        if ($owner !== null) {
            return __('app.journal_entry_owned', ['source' => $owner->label]);
        }

        if ($entry->reversal_of_entry_id !== null || in_array($entry->type, self::SYSTEM_TYPES, true)
            || $this->systemPrefixes->matching((string) $entry->reference) !== null
            || $this->isOwnedByModule((string) $entry->id)) {
            return __('app.journal_correction_reason_system');
        }

        if (JournalCorrection::withoutGlobalScopes()->where('original_journal_entry_id', $entry->id)->exists()) {
            return __('app.journal_correction_reason_corrected');
        }

        if ($this->isReversed($entry)) {
            return __('app.journal_correction_reason_reversed');
        }

        if (VatEntry::query()->where('journal_entry_id', $entry->id)->exists()) {
            return __('app.journal_correction_reason_vat');
        }

        return null;
    }

    /** @throws JournalCorrectionException */
    public function assertCorrectable(JournalEntry $entry): void
    {
        $refusal = $this->refusal($entry);
        if ($refusal !== null) {
            throw JournalCorrectionException::notCorrectable($refusal);
        }
    }

    /** A reversal links the entry, or (older ones) carries `REV-` + its reference; drafts count. */
    public function isReversed(JournalEntry $entry): bool
    {
        return JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $entry->organization_id)
            ->where(fn ($query) => $query
                ->where('reversal_of_entry_id', $entry->id)
                ->when((string) $entry->reference !== '', fn ($byReference) => $byReference
                    ->orWhere('reference', 'REV-'.$entry->reference)))
            ->exists();
    }

    /** Records that point at their entry but are not in the owner registry. */
    private function isOwnedByModule(string $entryId): bool
    {
        return Expense::withoutGlobalScopes()->where('journal_entry_id', $entryId)->exists()
            || BankTransaction::withoutGlobalScopes()
                ->where(fn ($query) => $query->where('journal_entry_id', $entryId)->orWhere('vat_settlement_journal_entry_id', $entryId))
                ->exists()
            || DepreciationEntry::query()->where('journal_entry_id', $entryId)->exists();
    }
}

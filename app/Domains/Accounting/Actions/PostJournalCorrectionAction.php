<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\Enums\JournalCorrectionStatus;
use App\Domains\Accounting\Exceptions\InvalidEntryDataException;
use App\Domains\Accounting\Exceptions\JournalCorrectionException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Accounting\Services\VatPeriodLockService;
use Illuminate\Support\Facades\DB;

/**
 * Posts a correction's reversal and replacement drafts together: if either
 * cannot be posted (closed fiscal year, settled VAT period, unbalanced,
 * inactive account), nothing is posted and the correction stays a draft.
 */
final class PostJournalCorrectionAction
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly VatPeriodLockService $vatPeriodLocks,
    ) {}

    /** @throws \DomainException When the correction or one of its drafts cannot be posted */
    public function execute(JournalCorrection $correction): JournalCorrection
    {
        return DB::transaction(function () use ($correction): JournalCorrection {
            $locked = JournalCorrection::withoutGlobalScopes()->whereKey($correction->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isDraft()) {
                throw JournalCorrectionException::notDraft();
            }

            $original = $this->lockEntry($locked->original_journal_entry_id);
            $reversal = $this->lockEntry($locked->reversal_journal_entry_id);
            $replacement = $this->lockEntry($locked->replacement_journal_entry_id);

            // Reversed some other way in the meantime (e.g. through the API).
            $reversedElsewhere = JournalEntry::withoutGlobalScopes()
                ->where('organization_id', $original->organization_id)
                ->whereKeyNot($reversal->id)
                ->where(fn ($query) => $query
                    ->where('reversal_of_entry_id', $original->id)
                    ->when((string) $original->reference !== '', fn ($byReference) => $byReference->orWhere('reference', 'REV-'.$original->reference)))
                ->exists();
            if ($reversedElsewhere) {
                throw JournalCorrectionException::reversedMeanwhile();
            }

            // The reversal may use accounts made inactive since; the replacement books anew and may not.
            $this->assertActiveAccounts($replacement);
            foreach ([$reversal, $replacement] as $entry) {
                $date = $entry->date->toDateString();
                $this->vatPeriodLocks->assertPeriodUnlocked($locked->organization_id, $date, $date);
            }

            $this->ledger->postDraft($reversal);
            $this->ledger->postDraft($replacement);

            $locked->update(['status' => JournalCorrectionStatus::Posted, 'posted_at' => now()]);

            return $locked;
        });
    }

    private function lockEntry(string $id): JournalEntry
    {
        return JournalEntry::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->with('lines')->firstOrFail();
    }

    /** @throws InvalidEntryDataException */
    private function assertActiveAccounts(JournalEntry $entry): void
    {
        $accountIds = $entry->lines->pluck('account_id')->unique();
        $active = Account::withoutGlobalScopes()->whereIn('id', $accountIds)->where('is_active', true)->count();

        if ($active !== $accountIds->count()) {
            throw new InvalidEntryDataException(__('app.journal_correction_inactive_account'));
        }
    }
}

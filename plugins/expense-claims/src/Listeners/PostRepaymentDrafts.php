<?php

namespace Plugins\ExpenseClaims\Listeners;

use App\Domains\Accounting\Events\JournalDraftPosted;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use Plugins\ExpenseClaims\Models\DebtRecord;
use Plugins\ExpenseClaims\Models\DebtRepayment;

/**
 * A bank repayment of a draft debt is booked as a draft (Debts::repay), and
 * the journal posts owned drafts one by one. Debt and repayments go to the
 * ledger in order, so the debt account never shows one without the other:
 * posting the debt entry posts its draft repayments with it (same
 * transaction), and a repayment cannot be posted while its debt is a draft.
 */
final class PostRepaymentDrafts
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function handle(JournalDraftPosted $event): void
    {
        $entry = $event->journalEntry;
        $this->refuseRepaymentBeforeDebt($entry);

        // Locked: a repay() running now waits and then books a posted repayment.
        $debtIds = DebtRecord::withoutGlobalScopes()
            ->where('organization_id', $entry->organization_id)
            ->where('journal_entry_id', $entry->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
        if ($debtIds->isEmpty()) {
            return;
        }
        JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $entry->organization_id)
            ->where('is_posted', false)
            ->whereIn('id', DebtRepayment::withoutGlobalScopes()->select('journal_entry_id')->where('via', 'bank')->whereIn('debt_record_id', $debtIds))
            ->orderBy('date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(fn (JournalEntry $draft) => $this->ledger->postDraft($draft));
    }

    private function refuseRepaymentBeforeDebt(JournalEntry $entry): void
    {
        $repayment = DebtRepayment::withoutGlobalScopes()
            ->where('organization_id', $entry->organization_id)
            ->where('journal_entry_id', $entry->id)
            ->with(['debtRecord' => fn ($q) => $q->withoutGlobalScopes()])
            ->first();
        $debtEntryId = $repayment?->debtRecord?->journal_entry_id;
        $debtIsDraft = $debtEntryId !== null && JournalEntry::withoutGlobalScopes()->whereKey($debtEntryId)->where('is_posted', false)->exists();
        if ($debtIsDraft) {
            throw new \DomainException(__('expense-claims::ec.repayment_debt_still_draft'));
        }
    }
}

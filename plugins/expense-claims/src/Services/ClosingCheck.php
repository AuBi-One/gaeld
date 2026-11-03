<?php

namespace Plugins\ExpenseClaims\Services;

use App\Domains\Accounting\Contracts\ClosingCheckInterface;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\DebtRecord;
use Plugins\ExpenseClaims\Models\DebtRepayment;

/**
 * Year-end closing (D46). Claims dated in the closing year whose cost is not
 * in the ledger yet (draft, or approved and not booked) **block** the
 * closing until they are paid (dated on or before the closing date) or passed
 * to debt dated on the closing date. Warnings only: approved claims already
 * booked (before D37 or migrated), claims of the year paid or passed to debt
 * after the year end (their cost falls in the next year), and unpaid claims
 * of earlier years.
 */
final class ClosingCheck implements ClosingCheckInterface
{
    public function check(string $organizationId, string $fromDate, string $toDate): array
    {
        $claims = Claim::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereDate('date', '<=', $toDate)
            ->where(fn ($q) => $q->whereIn('status', [Claim::STATUS_DRAFT, Claim::STATUS_APPROVED])
                ->orWhere(fn ($q) => $q->whereDate('date', '>=', $fromDate)->whereDate('settled_on', '>', $toDate)))
            ->get(['status', 'date', 'total', 'liability_account_code', 'journal_entry_id', 'source', 'settled_via', 'settled_on']);
        $inYear = $claims->filter(fn (Claim $c): bool => $c->date->toDateString() >= $fromDate);
        $earlier = $claims->filter(fn (Claim $c): bool => $c->date->toDateString() < $fromDate);
        $booked = fn (Claim $c): bool => $c->isBooked();
        $approved = $inYear->where('status', Claim::STATUS_APPROVED);

        // Links filter Expense balances to exactly the claims counted (from/to).
        $year = "&from={$fromDate}&to={$toDate}";
        $before = '&to='.Carbon::parse($fromDate)->subDay()->toDateString();

        return [
            ...$this->finding('drafts', $inYear->where('status', Claim::STATUS_DRAFT), 'closing_drafts', '/expense-balances?status=draft'.$year, true),
            ...$this->finding('unpaid', $approved->reject($booked), 'closing_unpaid', '/expense-balances?status=approved'.$year, true),
            ...$this->finding('unpaid-booked', $approved->filter($booked), 'closing_unpaid_booked', '/expense-balances?status=approved'.$year),
            // Booked once, but the entry is gone (deleted in the journal): counted as unbooked above; say why.
            ...$this->finding('entry-lost', $approved
                ->filter(fn (Claim $c): bool => $c->liability_account_code !== null && ! $c->isBooked()), 'closing_entry_lost', '/expense-balances?status=approved'.$year),
            // Paid or in debt after the year end: no list shows them, so no link.
            ...$this->finding('settled-later', $inYear->whereIn('status', [Claim::STATUS_SETTLED, Claim::STATUS_DEBT])
                ->reject($booked)->reject(fn (Claim $c): bool => $c->settled_via === 'migrated'), 'closing_settled_later', null),
            ...$this->finding('drafts-earlier', $earlier->where('status', Claim::STATUS_DRAFT), 'closing_drafts_earlier', '/expense-balances?status=draft'.$before),
            ...$this->lostDebts($organizationId, $toDate),
            ...$this->lostRepayments($organizationId, $fromDate, $toDate),
            ...$this->finding('unpaid-earlier', $earlier->where('status', Claim::STATUS_APPROVED), 'closing_unpaid_earlier', '/expense-balances?status=approved'.$before),
        ];
    }

    /**
     * Debt records whose entry was deleted in the journal (the foreign key
     * nulled the id): the cost and the liability are missing from the ledger,
     * whatever has been repaid since (a repayment has its own entry).
     *
     * @return list<array{key: string, message: string, action_label?: string, action_url?: string, blocking?: bool}>
     */
    private function lostDebts(string $organizationId, string $toDate): array
    {
        $lost = DebtRecord::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('entry_expected', true)
            ->whereNull('journal_entry_id')
            ->where('date', '<=', $toDate)
            ->get();
        if ($lost->isEmpty()) {
            return [];
        }

        return [[
            'key' => 'expense-claims.debt-entry-lost',
            'message' => (string) __('expense-claims::ec.closing_debt_entry_lost', [
                'count' => $lost->count(),
                'amount' => Money::sumAmounts($lost->map(fn (DebtRecord $d): array => ['amount' => (string) $d->amount])->values()->all()),
            ]),
            'action_label' => (string) __('expense-claims::ec.debts'),
            'action_url' => '/expense-balances',
        ]];
    }

    /**
     * Bank repayments whose entry was deleted in the journal (the foreign key
     * nulled the id): the debt account still shows what was repaid. Reported
     * in the year of the repayment only (re-booked in the journal, it stays
     * unlinked). Payroll repayments have no entry of their own (the slip
     * carries them).
     *
     * @return list<array{key: string, message: string, action_label?: string, action_url?: string, blocking?: bool}>
     */
    private function lostRepayments(string $organizationId, string $fromDate, string $toDate): array
    {
        $lost = DebtRepayment::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('via', 'bank')
            ->whereNull('journal_entry_id')
            ->whereBetween('date', [$fromDate, $toDate])
            ->get();
        if ($lost->isEmpty()) {
            return [];
        }

        return [[
            'key' => 'expense-claims.repayment-entry-lost',
            'message' => (string) __('expense-claims::ec.closing_repayment_entry_lost', [
                'count' => $lost->count(),
                'amount' => Money::sumAmounts($lost->map(fn (DebtRepayment $r): array => ['amount' => (string) $r->amount])->values()->all()),
            ]),
            'action_label' => (string) __('expense-claims::ec.debts'),
            'action_url' => '/expense-balances',
        ]];
    }

    /**
     * @param  Collection<int, Claim>  $claims
     * @return list<array{key: string, message: string, action_label?: string, action_url?: string, blocking?: bool}>
     */
    private function finding(string $key, Collection $claims, string $message, ?string $url, bool $blocking = false): array
    {
        if ($claims->isEmpty()) {
            return [];
        }
        $finding = [
            'key' => 'expense-claims.'.$key,
            'message' => (string) __('expense-claims::ec.'.$message, [
                'count' => $claims->count(),
                'amount' => Money::sumAmounts($claims->map(fn (Claim $c): array => ['amount' => (string) $c->total])->values()->all()),
            ]),
        ];
        if ($url !== null) {
            $finding['action_label'] = (string) __('expense-claims::ec.open_claims');
            $finding['action_url'] = $url;
        }
        if ($blocking) {
            $finding['blocking'] = true;
        }

        return [$finding];
    }
}

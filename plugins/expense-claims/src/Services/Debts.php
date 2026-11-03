<?php

namespace Plugins\ExpenseClaims\Services;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Models\FiscalYear;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\DebtRecord;
use Plugins\ExpenseClaims\Models\DebtRepayment;
use Plugins\ExpenseClaims\Models\Setting;

/**
 * Passes approved, unpaid claims into debt records of the company towards
 * each person (period end), booking their cost then (D37), and repays
 * those debts.
 */
final class Debts
{
    public function __construct(
        private Journal $journal,
        private Accounts $accounts,
        private Claims $claims,
        private EntryLines $lines,
    ) {}

    /**
     * Pass approved claims dated on or before $date to debt: per person one
     * debt record and one entry dated $date (D37, D38): Dr the cost (expense
     * account per line, or the liability of a claim booked earlier) · Cr the
     * person's debt account (setting: 2260 owners, 2210 staff). A claim booked earlier
     * on the debt account itself gives no line.
     *
     * @param  Claim|iterable<Claim>  $claims
     * @return Collection<int, DebtRecord>
     */
    public function convert(Claim|iterable $claims, string $date, ?string $notes = null, bool $draft = false): Collection
    {
        return DB::transaction(function () use ($claims, $date, $notes, $draft): Collection {
            $locked = $this->claims->lockMany($claims, Claim::STATUS_APPROVED)->load(['lines', 'person']);
            if ($locked->contains(fn (Claim $c): bool => $c->date->toDateString() > $date)) {
                throw new \DomainException(__('expense-claims::ec.claim_after_debt_date', ['date' => $date]));
            }
            if (! $draft) {
                $this->claims->refuseDraftBookings($locked);
            }
            $orgId = (string) $locked->first()?->organization_id;
            $settings = Setting::forOrganization($orgId);

            $records = new Collection;
            foreach ($locked->groupBy('person_id') as $group) {
                $first = $group->first();
                $account = $first->person?->is_owner ? $settings->owner_debt_code : $settings->staff_debt_code;
                $lines = $this->debtLines($orgId, $group, $account);
                $entryId = $lines === [] ? null : $this->journal->book($orgId, new JournalEntryData(
                    date: $date,
                    reference: $this->accounts->uniqueReference($orgId, 'EC-DEBT-'.str_replace('-', '', $date).'-'.EntryLines::tag((string) $first->person?->name)),
                    description: "Frais non remboursés au {$date} — dette envers {$first->person?->name} ({$group->count()})",
                    lines: $lines,
                ), $draft)->id;

                $debt = DebtRecord::withoutGlobalScopes()->create([
                    'organization_id' => $orgId,
                    'person_id' => $first->person_id,
                    'date' => $date,
                    'amount' => Money::sumAmounts($group->map(fn (Claim $c): array => ['amount' => (string) $c->total])->values()->all()),
                    'account_code' => $account,
                    'journal_entry_id' => $entryId,
                    'entry_expected' => $lines !== [], // false: nothing to move (claims already on the debt account)
                    'notes' => $notes,
                ]);
                Claim::withoutGlobalScopes()->whereIn('id', $group->modelKeys())->update([
                    'status' => Claim::STATUS_DEBT,
                    'settled_via' => 'debt',
                    'settled_on' => $date,
                    'debt_record_id' => $debt->id,
                ]);
                Audit::log($group, 'passed_to_debt', ['date' => $date, 'debt_record_id' => $debt->id, 'journal_entry_id' => $entryId]);
                $records->push($debt);
            }

            return $records;
        });
    }

    /** Undo a debt record without repayments: its claims are approved (unpaid) again. */
    public function cancel(DebtRecord $debt): void
    {
        DB::transaction(function () use ($debt): void {
            // Lock every record of the same entry in id order first (no deadlock between two cancels).
            $entryId = DebtRecord::withoutGlobalScopes()->whereKey($debt->id)->value('journal_entry_id');
            if ($entryId !== null) {
                DebtRecord::withoutGlobalScopes()->where('journal_entry_id', $entryId)->orderBy('id')->lockForUpdate()->pluck('id');
            }
            $debt = $this->locked($debt);
            if ($debt->repayments->isNotEmpty()) {
                throw new \DomainException(__('expense-claims::ec.debt_has_repayments'));
            }

            $claims = Claim::withoutGlobalScopes()->where('debt_record_id', $debt->id)->orderBy('id')->lockForUpdate()->get()->load('person');
            $description = 'Annulation dette du '.$debt->date->toDateString();
            $orgId = (string) $debt->organization_id;
            $draft = $this->journal->draft($debt->journal_entry_id);
            if ($draft !== null) {
                // Still a draft: rewrite it with the other people's records only.
                $others = DebtRecord::withoutGlobalScopes()->where('journal_entry_id', $draft->id)->whereKeyNot($debt->id)->orderBy('id')->lockForUpdate()->get();
                $lines = [];
                foreach ($others as $other) {
                    $lines = [...$lines, ...$this->recordLines($orgId, $other)];
                }
                $newId = $this->journal->replaceDraft($draft, $lines);
                DebtRecord::withoutGlobalScopes()->whereIn('id', $others->modelKeys())->update(['journal_entry_id' => $newId]);
            } else {
                // Counter-book this person's lines only (the entry may hold other people's records).
                $this->journal->undoPart($debt->journal_entry_id, $description, $this->recordLines($orgId, $debt, $claims));
            }

            Claim::withoutGlobalScopes()->whereIn('id', $claims->modelKeys())->update([
                'status' => Claim::STATUS_APPROVED,
                'settled_via' => null,
                'settled_on' => null,
                'debt_record_id' => null,
            ]);
            Audit::log($claims, 'debt_cancelled', ['debt_record_id' => $debt->id]);
            $debt->delete();
        });
    }

    /**
     * Repay (part of) a debt record from a bank or cash account (default: the
     * bank setting). While the debt's own entry is a draft (e.g. a draft
     * migration load), the repayment entry is a draft too, so the debt
     * account never shows a repayment without its debt.
     */
    public function repay(DebtRecord $debt, string $date, string $amount, ?string $accountCode = null): DebtRepayment
    {
        $amount = Money::normalize($amount);

        return DB::transaction(function () use ($debt, $date, $amount, $accountCode): DebtRepayment {
            $debt = $this->locked($debt)->load('person');
            if (! Money::isPositive($amount) || Money::compare($amount, $debt->remaining()) > 0) {
                throw new \DomainException(__('expense-claims::ec.repayment_too_high'));
            }
            // A draft is not checked against closed years by the ledger: a repayment there could never be
            // posted, and with it neither its debt (PostRepaymentDrafts).
            $closed = FiscalYear::query()->withoutGlobalScopes()->where('organization_id', $debt->organization_id)->forDate($date)->first()?->isClosed() ?? false;
            if ($date < $debt->date->toDateString() || $closed) {
                throw new \DomainException(__('expense-claims::ec.repayment_date_invalid'));
            }
            $orgId = (string) $debt->organization_id;
            $accountCode ??= Setting::forOrganization($orgId)->bank_account_code;
            $entry = $this->journal->book($orgId, new JournalEntryData(
                date: $date,
                reference: $this->accounts->uniqueReference($orgId, 'EC-DEBT-PAY-'.str_replace('-', '', $date).'-'.EntryLines::tag((string) $debt->person?->name)),
                description: "Remboursement dette — {$debt->person?->name}",
                lines: [
                    new JournalLineData($this->accounts->id($orgId, $debt->account_code), $amount, '0', "Dette du {$debt->date->toDateString()}"),
                    new JournalLineData($this->accounts->id($orgId, $accountCode), '0', $amount, (string) $debt->person?->name),
                ],
            ), $this->journal->draft($debt->journal_entry_id) !== null);

            return $debt->repayments()->create(['organization_id' => $orgId, 'date' => $date, 'amount' => $amount, 'via' => 'bank', 'journal_entry_id' => $entry->id]);
        });
    }

    /**
     * The lines a debt record contributed to its entry.
     *
     * @param  Collection<int, Claim>|null  $claims  the record's claims (loaded when null)
     * @return list<JournalLineData>
     */
    private function recordLines(string $orgId, DebtRecord $debt, ?Collection $claims = null): array
    {
        $claims ??= Claim::withoutGlobalScopes()->where('debt_record_id', $debt->id)->get();

        return $this->debtLines($orgId, $claims->load(['lines', 'person']), $debt->account_code);
    }

    /**
     * Dr the cost of each claim · Cr the debt account (claims booked earlier
     * on the debt account itself are left out: nothing moves).
     *
     * @param  Collection<int, Claim>  $claims  with lines and person loaded
     * @return list<JournalLineData>
     */
    private function debtLines(string $orgId, Collection $claims, string $debtAccount): array
    {
        $moved = $claims->filter(fn (Claim $c): bool => ! $c->isBooked() || $c->liability_account_code !== $debtAccount)->values();

        return $moved->isEmpty() ? [] : [
            ...$this->lines->costs($orgId, $moved, 'debit'),
            ...$this->lines->perPerson($orgId, $moved, fn (): string => $debtAccount, 'credit'),
        ];
    }

    /** Reload the debt record with a row lock and its current repayments. */
    private function locked(DebtRecord $debt): DebtRecord
    {
        return DebtRecord::withoutGlobalScopes()->whereKey($debt->id)->lockForUpdate()->firstOrFail()->load('repayments');
    }
}

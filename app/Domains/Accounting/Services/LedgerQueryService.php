<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Support\AccountDisplayName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read-only ledger queries: balances, trial balance, account lookups.
 *
 * Separated from LedgerService (which handles writes) so that
 * reporting, dashboard, and reconciliation code can depend on
 * queries without pulling in the full posting contract.
 */
class LedgerQueryService
{
    /**
     * Get the balance for an account within a date range.
     *
     * Asset and expense accounts return debit-normal balances (debits − credits).
     * Liability, equity, and revenue accounts return credit-normal (credits − debits).
     * Only posted entries are included.
     *
     * Statements of trading (profit and loss) pass $withYearEndClosing = false:
     * the closing empties every revenue and expense account, so counting it
     * reads a closed year as zero. Balances of position (balance sheet, cash)
     * keep it, since it is what carries the result into equity.
     *
     * Results are cached per account + date range + closing flag (tag: org:{orgId}:ledger).
     *
     * @param  int  $accountId  The account's primary key
     * @param  string|null  $fromDate  Start date (inclusive, Y-m-d)
     * @param  string|null  $toDate  End date (inclusive, Y-m-d)
     * @param  bool  $withYearEndClosing  Count the year-end closing entry
     * @return string The calculated balance (bcmath-compatible string, 2 decimal places)
     */
    public function accountBalance(int $accountId, ?string $fromDate = null, ?string $toDate = null, bool $withYearEndClosing = true): string
    {
        $account = Account::findOrFail($accountId);
        $cacheKey = "account_balance:{$accountId}:{$fromDate}:{$toDate}".($withYearEndClosing ? '' : ':trading');
        $orgTag = "org:{$account->organization_id}:ledger";

        return Cache::tags([$orgTag])->remember($cacheKey, now()->addHour(), function () use ($accountId, $account, $fromDate, $toDate, $withYearEndClosing) {
            $query = TransactionLine::where('account_id', $accountId)
                ->whereHas('journalEntry', function ($q) use ($fromDate, $toDate, $withYearEndClosing) {
                    $q->where('is_posted', true)
                        ->when($fromDate, fn ($q, $date) => $q->where('date', '>=', $date))
                        ->when($toDate, fn ($q, $date) => $q->where('date', '<=', $date))
                        ->when(! $withYearEndClosing, fn ($q) => $q->where(fn ($type) => $type
                            ->whereNull('type')
                            ->orWhere('type', '!=', JournalEntry::TYPE_YEAR_END_CLOSING)));
                });

            $debits = (string) (clone $query)->sum('debit');
            $credits = (string) (clone $query)->sum('credit');

            return $this->isDebitNormalAccount($account->type)
                ? bcsub($debits, $credits, 2)
                : bcsub($credits, $debits, 2);
        });
    }

    /**
     * Get the most recent posted journal entries for an organization.
     *
     * @return Collection<int, JournalEntry>
     */
    public function recentEntries(string $organizationId, int $limit = 10): Collection
    {
        return JournalEntry::where('organization_id', $organizationId)
            ->with('lines.account')
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Return the date string of the most recent posted journal entry, or null when none exist.
     */
    public function latestPostedEntryDate(string $organizationId): ?string
    {
        return JournalEntry::where('organization_id', $organizationId)
            ->where('is_posted', true)
            ->orderByDesc('date')
            ->value('date');
    }

    /**
     * Journal entry types that record structural bookkeeping rather than
     * business activity.
     *
     * Year-end closing entries transfer revenue and expense balances to
     * equity, and historical summaries carry opening balances forward.
     * Counting either as operational revenue or expense doubles the figures
     * and - because a closing entry is dated in the following calendar year -
     * can make the dashboard resolve to an otherwise empty year.
     *
     * @var list<string>
     */
    public const STRUCTURAL_ENTRY_TYPES = [JournalEntry::TYPE_YEAR_END_CLOSING, 'historical_summary'];

    /** Accounts listed per month in a chart tooltip before folding into an overflow row. */
    private const TOOLTIP_ITEM_LIMIT = 8;

    /**
     * Total posted revenue and expense for a date range.
     *
     * Both figures come back positive and natural-signed: revenue
     * credit-normal, expenses debit-normal. Structural entries are excluded.
     *
     * Results are cached per organization + range (tag: org:{orgId}:ledger).
     *
     * @return array{revenue: string, expenses: string}
     */
    public function periodTotals(string $organizationId, string $fromDate, string $toDate): array
    {
        $cacheKey = "period_totals:{$organizationId}:{$fromDate}:{$toDate}";

        return Cache::tags(["org:{$organizationId}:ledger"])->remember(
            $cacheKey,
            now()->addMinutes(30),
            function () use ($organizationId, $fromDate, $toDate) {
                $rows = $this->operationalLines($organizationId, $fromDate, $toDate)
                    ->groupBy('accounts.type')
                    ->selectRaw('accounts.type AS account_type, COALESCE(SUM(transaction_lines.debit), 0) AS total_debit, COALESCE(SUM(transaction_lines.credit), 0) AS total_credit')
                    ->get();

                return [
                    'revenue' => $this->naturalBalance($rows, AccountType::Revenue),
                    'expenses' => $this->naturalBalance($rows, AccountType::Expense),
                ];
            }
        );
    }

    /**
     * Posted revenue and expense totals per calendar month of a year.
     *
     * Both arrays are keyed 1-12 with every month present, so callers can
     * plot them without filling the gaps themselves.
     *
     * @return array{revenue: array<int, string>, expenses: array<int, string>}
     */
    public function monthlyTotals(string $organizationId, int $year): array
    {
        $cacheKey = "monthly_totals:{$organizationId}:{$year}";

        return Cache::tags(["org:{$organizationId}:ledger"])->remember(
            $cacheKey,
            now()->addMinutes(30),
            function () use ($organizationId, $year) {
                $rows = $this->operationalLines($organizationId, "{$year}-01-01", "{$year}-12-31")
                    ->groupBy('accounts.type')
                    ->groupByRaw('EXTRACT(MONTH FROM journal_entries.date)')
                    ->selectRaw('accounts.type AS account_type, EXTRACT(MONTH FROM journal_entries.date) AS month, COALESCE(SUM(transaction_lines.debit), 0) AS total_debit, COALESCE(SUM(transaction_lines.credit), 0) AS total_credit')
                    ->get()
                    ->groupBy(fn ($row) => (int) $row->month);

                $revenue = [];
                $expenses = [];

                foreach (range(1, 12) as $month) {
                    $monthRows = $rows->get($month, collect());
                    $revenue[$month] = $this->naturalBalance($monthRows, AccountType::Revenue);
                    $expenses[$month] = $this->naturalBalance($monthRows, AccountType::Expense);
                }

                return ['revenue' => $revenue, 'expenses' => $expenses];
            }
        );
    }

    /**
     * Per-month revenue and expense breakdown by account, for chart tooltips.
     *
     * Aggregating by account rather than by journal entry keeps a month with
     * dozens of bookings readable ("Loehne: 18'790.97" instead of twelve
     * separate wage lines). Each month lists at most
     * {@see self::TOOLTIP_ITEM_LIMIT} accounts and folds the rest into a
     * single overflow row.
     *
     * @return array{revenue: array<int, list<array{label: string, amount: string, overflow?: int}>>, expenses: array<int, list<array{label: string, amount: string, overflow?: int}>>}
     */
    public function monthlyTotalsByAccount(string $organizationId, int $year): array
    {
        // Per locale: the labels are translated account names.
        $cacheKey = "monthly_totals_by_account:{$organizationId}:{$year}:".app()->getLocale();

        return Cache::tags(["org:{$organizationId}:ledger"])->remember(
            $cacheKey,
            now()->addMinutes(30),
            function () use ($organizationId, $year) {
                $rows = $this->operationalLines($organizationId, "{$year}-01-01", "{$year}-12-31")
                    ->groupBy('accounts.type', 'accounts.code', 'accounts.name')
                    ->groupByRaw('EXTRACT(MONTH FROM journal_entries.date)')
                    ->selectRaw('accounts.type AS account_type, accounts.code, accounts.name, EXTRACT(MONTH FROM journal_entries.date) AS month, COALESCE(SUM(transaction_lines.debit), 0) AS total_debit, COALESCE(SUM(transaction_lines.credit), 0) AS total_credit')
                    ->get()
                    ->groupBy(fn ($row) => (int) $row->month);

                $revenue = [];
                $expenses = [];

                foreach (range(1, 12) as $month) {
                    $monthRows = $rows->get($month, collect());
                    $revenue[$month] = $this->accountItems($monthRows, AccountType::Revenue);
                    $expenses[$month] = $this->accountItems($monthRows, AccountType::Expense);
                }

                return ['revenue' => $revenue, 'expenses' => $expenses];
            }
        );
    }

    /**
     * Date of the most recent posted entry that reflects business activity.
     *
     * Unlike {@see latestPostedEntryDate()} this skips structural entries, so
     * a year-end closing booked into January does not make the dashboard jump
     * to a year that holds no actual bookkeeping yet.
     *
     * @return string|null A Y-m-d date, or null when nothing is posted.
     */
    public function latestOperationalEntryDate(string $organizationId): ?string
    {
        $date = JournalEntry::where('organization_id', $organizationId)
            ->where('is_posted', true)
            ->where(fn ($query) => $query
                ->whereNull('type')
                ->orWhereNotIn('type', self::STRUCTURAL_ENTRY_TYPES))
            // Opening balances are dated on the first day of the next year.
            ->where(fn ($query) => $query
                ->whereNull('reference')
                ->orWhere('reference', 'not like', 'OPENING-%'))
            ->orderByDesc('date')
            ->value('date');

        return $date?->toDateString();
    }

    /**
     * Whether the organization posted any revenue or expense line in a year.
     */
    public function hasOperationalActivityInYear(string $organizationId, int $year): bool
    {
        return $this->operationalLines($organizationId, "{$year}-01-01", "{$year}-12-31")->exists();
    }

    /**
     * Natural-signed balance of an account group, selected by code prefix.
     *
     * Summing a whole group rather than named accounts keeps this working on
     * customised charts of accounts: an organisation that books wages through
     * a payroll clearing account in the 10xx group has that clearing balance
     * netted off its liquid assets automatically, without the caller needing
     * to know the account exists.
     *
     * @param  list<string>  $codePrefixes
     */
    public function groupBalance(string $organizationId, AccountType $type, array $codePrefixes, ?string $asOfDate = null): string
    {
        $cacheKey = "group_balance:{$organizationId}:{$type->value}:".implode(',', $codePrefixes).":{$asOfDate}";

        return Cache::tags(["org:{$organizationId}:ledger"])->remember(
            $cacheKey,
            now()->addMinutes(30),
            function () use ($organizationId, $type, $codePrefixes, $asOfDate) {
                $row = DB::table('transaction_lines')
                    ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
                    ->join('journal_entries', 'journal_entries.id', '=', 'transaction_lines.journal_entry_id')
                    ->where('journal_entries.organization_id', $organizationId)
                    ->where('journal_entries.is_posted', true)
                    ->when($asOfDate, fn ($query, $date) => $query->where('journal_entries.date', '<=', $date))
                    ->where('accounts.type', $type->value)
                    ->where(fn ($query) => $this->applyCodePrefixes($query, $codePrefixes))
                    ->selectRaw('COALESCE(SUM(transaction_lines.debit), 0) AS total_debit, COALESCE(SUM(transaction_lines.credit), 0) AS total_credit')
                    ->first();

                /** @var numeric-string $debit */
                $debit = (string) ($row->total_debit ?? '0');
                /** @var numeric-string $credit */
                $credit = (string) ($row->total_credit ?? '0');

                return $type->isDebitNormal()
                    ? bcsub($debit, $credit, 2)
                    : bcsub($credit, $debit, 2);
            }
        );
    }

    /**
     * Monthly natural-signed totals for one account type over an arbitrary
     * window, keyed "Y-m" with every month in the window present.
     *
     * The exclusion lists let a caller drop accounts it already accounts for
     * from another source, so a figure taken from master data is not counted a
     * second time from its own bookings.
     *
     * @param  list<string>  $excludeCodePrefixes
     * @param  list<string>  $excludeCodes
     * @param  list<string>  $onlyCodePrefixes  when given, restrict to these groups
     * @return array<string, string>
     */
    public function monthlyTotalsInWindow(
        string $organizationId,
        AccountType $type,
        string $fromDate,
        string $toDate,
        array $excludeCodePrefixes = [],
        array $excludeCodes = [],
        array $onlyCodePrefixes = [],
    ): array {
        $rows = $this->operationalLines($organizationId, $fromDate, $toDate)
            ->where('accounts.type', $type->value)
            ->when($onlyCodePrefixes, fn ($query) => $query->where(
                fn ($inner) => $this->applyCodePrefixes($inner, $onlyCodePrefixes)
            ))
            ->when($excludeCodePrefixes, fn ($query) => $query->where(
                fn ($inner) => $this->applyCodePrefixes($inner, $excludeCodePrefixes, negate: true)
            ))
            ->when($excludeCodes, fn ($query) => $query->whereNotIn('accounts.code', $excludeCodes))
            ->groupByRaw("TO_CHAR(journal_entries.date, 'YYYY-MM')")
            ->selectRaw("TO_CHAR(journal_entries.date, 'YYYY-MM') AS period, COALESCE(SUM(transaction_lines.debit), 0) AS total_debit, COALESCE(SUM(transaction_lines.credit), 0) AS total_credit")
            ->get()
            ->keyBy('period');

        $totals = [];
        $cursor = Carbon::parse($fromDate)->startOfMonth();
        $last = Carbon::parse($toDate)->startOfMonth();

        while ($cursor->lessThanOrEqualTo($last)) {
            $key = $cursor->format('Y-m');
            $row = $rows->get($key);

            /** @var numeric-string $debit */
            $debit = (string) ($row->total_debit ?? '0');
            /** @var numeric-string $credit */
            $credit = (string) ($row->total_credit ?? '0');

            $totals[$key] = $type->isDebitNormal()
                ? bcsub($debit, $credit, 2)
                : bcsub($credit, $debit, 2);

            $cursor->addMonth();
        }

        return $totals;
    }

    /**
     * Get trial balance for an organization.
     *
     * Returns all accounts with non-zero posted balances, ordered by code.
     * Results cached per organization (tag: org:{orgId}:ledger).
     *
     * @param  string  $organizationId  UUID of the organization
     * @param  string|null  $asOfDate  Cut-off date (inclusive)
     * @return array<array{account_code: string, account_name: string, account_type: AccountType|string, debit: string, credit: string}>
     */
    public function trialBalance(string $organizationId, ?string $asOfDate = null): array
    {
        $cacheKey = "trial_balance:{$organizationId}:{$asOfDate}";
        $orgTag = "org:{$organizationId}:ledger";

        return Cache::tags([$orgTag])->remember($cacheKey, now()->addMinutes(30), function () use ($organizationId, $asOfDate) {
            $rows = $this->buildTrialBalanceQuery($organizationId, $asOfDate)->get();

            return $this->computeTrialBalances($rows);
        });
    }

    /**
     * Check whether a reference has already been used for a posted entry in this organization.
     */
    public function isDuplicateReference(string $organizationId, string $reference): bool
    {
        return JournalEntry::where('organization_id', $organizationId)
            ->where('reference', $reference)
            ->where('is_posted', true)
            ->exists();
    }

    /**
     * Check if ANY entry (draft or posted) with this reference exists
     */
    public function isDuplicateReferenceAny(string $organizationId, string $reference): bool
    {
        return JournalEntry::where('organization_id', $organizationId)
            ->where('reference', $reference)
            ->exists();
    }

    /**
     * Resolve an account by its chart-of-accounts code within an organization.
     *
     * @throws ModelNotFoundException
     */
    public function resolveAccount(string $organizationId, string $code): Account
    {
        return Account::where('organization_id', $organizationId)
            ->where('code', $code)
            ->firstOrFail();
    }

    /**
     * Constrain a query to accounts whose code starts with one of the given
     * prefixes, or - with $negate - to those that start with none of them.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  list<string>  $codePrefixes
     */
    private function applyCodePrefixes($query, array $codePrefixes, bool $negate = false): void
    {
        foreach ($codePrefixes as $prefix) {
            if ($negate) {
                $query->where('accounts.code', 'not like', $prefix.'%');
            } else {
                $query->orWhere('accounts.code', 'like', $prefix.'%');
            }
        }
    }

    /**
     * Base query over posted revenue/expense lines of an organization,
     * excluding structural entries. Callers add their own grouping.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function operationalLines(string $organizationId, string $fromDate, string $toDate)
    {
        return DB::table('transaction_lines')
            ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'transaction_lines.journal_entry_id')
            ->where('journal_entries.organization_id', $organizationId)
            ->where('journal_entries.is_posted', true)
            ->whereBetween('journal_entries.date', [$fromDate, $toDate])
            ->whereIn('accounts.type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->where(fn ($query) => $query
                ->whereNull('journal_entries.type')
                ->orWhereNotIn('journal_entries.type', self::STRUCTURAL_ENTRY_TYPES));
    }

    /**
     * Reduce aggregate rows to one natural-signed balance for an account type.
     *
     * @param  Collection<int, \stdClass>  $rows
     */
    private function naturalBalance(Collection $rows, AccountType $type): string
    {
        /** @var numeric-string $debit */
        $debit = '0.00';
        /** @var numeric-string $credit */
        $credit = '0.00';

        foreach ($rows->where('account_type', $type->value) as $row) {
            /** @var numeric-string $rowDebit */
            $rowDebit = (string) $row->total_debit;
            /** @var numeric-string $rowCredit */
            $rowCredit = (string) $row->total_credit;
            $debit = bcadd($debit, $rowDebit, 2);
            $credit = bcadd($credit, $rowCredit, 2);
        }

        return $type->isDebitNormal()
            ? bcsub($debit, $credit, 2)
            : bcsub($credit, $debit, 2);
    }

    /**
     * Turn aggregate rows into per-account tooltip items, largest first.
     *
     * Accounts whose net movement is zero are dropped: a booking and its
     * reversal in the same month carry no information for the reader.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return list<array{label: string, amount: string, overflow?: int}>
     */
    private function accountItems(Collection $rows, AccountType $type): array
    {
        $items = $rows->where('account_type', $type->value)
            ->map(function ($row) use ($type) {
                /** @var numeric-string $debit */
                $debit = (string) $row->total_debit;
                /** @var numeric-string $credit */
                $credit = (string) $row->total_credit;

                return [
                    'label' => $row->code.' '.AccountDisplayName::for((string) $row->code, (string) $row->name),
                    'amount' => $type->isDebitNormal()
                        ? bcsub($debit, $credit, 2)
                        : bcsub($credit, $debit, 2),
                ];
            })
            ->reject(function (array $item): bool {
                /** @var numeric-string $amount */
                $amount = $item['amount'];

                return bccomp($amount, '0', 2) === 0;
            })
            ->sortByDesc(fn (array $item) => (float) $item['amount'])
            ->values();

        if ($items->count() <= self::TOOLTIP_ITEM_LIMIT) {
            return array_values($items->all());
        }

        $shown = $items->take(self::TOOLTIP_ITEM_LIMIT);
        $rest = $items->slice(self::TOOLTIP_ITEM_LIMIT);
        $restTotal = $rest->reduce(function (string $carry, array $item): string {
            /** @var numeric-string $carry */
            /** @var numeric-string $amount */
            $amount = $item['amount'];

            return bcadd($carry, $amount, 2);
        }, '0.00');

        return array_values($shown->push([
            'label' => __('app.dashboard_other_accounts', ['count' => $rest->count()]),
            'amount' => $restTotal,
            'overflow' => $rest->count(),
        ])->all());
    }

    /**
     * @return Builder<Account>
     */
    private function buildTrialBalanceQuery(string $organizationId, ?string $asOfDate): Builder
    {
        return Account::where('accounts.organization_id', $organizationId)
            ->where('accounts.is_active', true)
            ->leftJoin('transaction_lines', 'transaction_lines.account_id', '=', 'accounts.id')
            ->leftJoin('journal_entries', function ($join) use ($asOfDate) {
                $join->on('journal_entries.id', '=', 'transaction_lines.journal_entry_id')
                    ->where('journal_entries.is_posted', true);
                if ($asOfDate) {
                    $join->where('journal_entries.date', '<=', $asOfDate);
                }
            })
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.code')
            // journal_entries.id is only set when the second join matched (posted, within asOfDate):
            // a transaction_lines row from an unposted or later entry must not count, even though
            // the first (unconditional) join still brings the row in for grouping.
            ->selectRaw('accounts.id, accounts.code, accounts.name, accounts.type, '
                .'COALESCE(SUM(CASE WHEN journal_entries.id IS NOT NULL THEN transaction_lines.debit ELSE 0 END), 0) as total_debit, '
                .'COALESCE(SUM(CASE WHEN journal_entries.id IS NOT NULL THEN transaction_lines.credit ELSE 0 END), 0) as total_credit');
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return array<int, array{account_code: string, account_name: string, account_type: string, debit: string, credit: string}>
     */
    private function computeTrialBalances(Collection $rows): array
    {
        $balances = [];

        foreach ($rows as $row) {
            $isDebitNormal = $this->isDebitNormalAccount($row->type);
            /** @var numeric-string $totalDebit */
            $totalDebit = (string) $row->total_debit;
            /** @var numeric-string $totalCredit */
            $totalCredit = (string) $row->total_credit;
            $balance = $isDebitNormal
                ? bcsub($totalDebit, $totalCredit, 2)
                : bcsub($totalCredit, $totalDebit, 2);

            if (bccomp($balance, '0', 2) !== 0) {
                // A balance on the account's normal side shows there; a balance on the opposite
                // side (e.g. an overdrawn asset, or migration suspense left in credit) still has
                // to show somewhere, or the trial balance's own totals stop balancing.
                $onNormalSide = bccomp($balance, '0', 2) > 0;
                $amount = $onNormalSide ? $balance : bcmul($balance, '-1', 2);
                $debitSide = $isDebitNormal === $onNormalSide;
                $balances[] = [
                    'account_code' => $row->code,
                    'account_name' => $row->name,
                    'account_type' => $row->type,
                    'debit' => $debitSide ? $amount : '0',
                    'credit' => ! $debitSide ? $amount : '0',
                ];
            }
        }

        return $balances;
    }

    private function isDebitNormalAccount(AccountType|string $type): bool
    {
        if ($type instanceof AccountType) {
            return $type->isDebitNormal();
        }

        return AccountType::from($type)->isDebitNormal();
    }
}

<?php

namespace App\Domains\Reporting\Services;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\Models\Budget;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\VatReportService;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Expenses\Models\ReceiptScan;
use App\Domains\Expenses\Services\ExpenseService;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Queries\InvoiceReportingQuery;
use App\Domains\Organizations\Models\Organization;
use App\Support\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Aggregates KPI data for the organization dashboard: revenue/expense
 * summaries, cash flow, outstanding invoices, and recent activity.
 */
class DashboardService
{
    public function __construct(
        private readonly LedgerQueryService $ledgerService,
        private readonly InvoiceReportingQuery $invoiceQuery,
        private readonly ExpenseService $expenseService,
        private readonly VatReportService $vatReportService,
        private readonly AgingReportService $agingReportService,
    ) {}

    // ──────────────────────────────────────────────────────────────
    //  Public API
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function metrics(string $organizationId): array
    {
        return Cache::tags(["org:{$organizationId}:dashboard"])->remember(
            // Labels inside (account names) are translated: one entry per locale.
            "dashboard_metrics:{$organizationId}:".app()->getLocale(),
            300, // 5 minutes
            fn () => $this->computeMetrics($organizationId)
        );
    }

    /**
     * Flush both the dashboard cache and the "reports" cache tag (P&L, Balance
     * Sheet, Cash Flow, Aging, VAT). Callers use this after any invoice/expense
     * mutation (create/update/approve/post/etc.), and several of those reports
     * (e.g. Aging) depend on invoice/expense state directly, not just posted
     * journal entries — flushing only the dashboard tag left them stale for
     * up to 30 minutes after e.g. approving an expense.
     */
    public function flushCache(string $organizationId): void
    {
        Cache::tags(["org:{$organizationId}:dashboard"])->flush();
        Cache::tags(["org:{$organizationId}:reports"])->flush();
    }

    // ──────────────────────────────────────────────────────────────
    //  Core Computation
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function computeMetrics(string $organizationId): array
    {
        $year = $this->resolveDisplayYear($organizationId);

        $totals = $this->ledgerService->periodTotals($organizationId, ...$this->yearBounds($year));
        $totalRevenue = $totals['revenue'];
        $totalExpenses = $totals['expenses'];
        $cashBalance = $this->cashBalance($organizationId);

        $unpaidInvoices = $this->invoiceQuery->unpaidSummary($organizationId);

        $pendingExpenses = $this->expenseService->pendingSummary($organizationId);

        $recentTransactions = $this->recentTransactions($organizationId);

        // Year-over-year comparison
        $previousTotals = $this->ledgerService->periodTotals($organizationId, ...$this->yearBounds($year - 1));
        $previousRevenue = $previousTotals['revenue'];
        $previousExpenses = $previousTotals['expenses'];
        $hasPreviousYearData = $this->hasActivityInYear($organizationId, $year - 1);

        return [
            'revenue' => $totalRevenue,
            'expenses' => $totalExpenses,
            'cashBalance' => $cashBalance,
            'unpaidInvoices' => [
                'count' => $unpaidInvoices->count,
                'total' => $unpaidInvoices->total,
            ],
            'pendingExpenses' => [
                'count' => $pendingExpenses->count,
                'total' => $pendingExpenses->total,
            ],
            'balance' => Money::subtract($totalRevenue, $totalExpenses),
            'previousRevenue' => $previousRevenue,
            'previousExpenses' => $previousExpenses,
            'previousBalance' => Money::subtract($previousRevenue, $previousExpenses),
            'hasPreviousYearData' => $hasPreviousYearData,
            'recentTransactions' => $recentTransactions,
            'hasActivity' => $recentTransactions->isNotEmpty() || $this->hasCapturedDocuments($organizationId),
            'monthlyBreakdown' => $this->monthlyBreakdown($organizationId, $year),
            'budgetSummary' => $this->budgetSummary($organizationId, $year, $totalRevenue, $totalExpenses),
            'vatSummary' => $this->currentQuarterVat($organizationId),
            'receivablesAging' => $this->agingSummary($organizationId),
            'pendingOcrScans' => $this->pendingOcrScans($organizationId),
            'displayYear' => $year,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Component Metrics
    // ──────────────────────────────────────────────────────────────

    private function cashBalance(string $organizationId): string
    {
        try {
            $bankAccount = $this->ledgerService->resolveAccount($organizationId, AccountCode::BANK_CASH);
        } catch (ModelNotFoundException) {
            return '0.00';
        }

        return $this->ledgerService->accountBalance($bankAccount->id);
    }

    /**
     * @return Collection<int, mixed>
     */
    private function recentTransactions(string $organizationId): Collection
    {
        return $this->ledgerService->recentEntries($organizationId)
            ->map(function (JournalEntry $entry) {
                return [
                    'id' => $entry->id,
                    'date' => $entry->date->toDateString(),
                    'description' => $entry->description,
                    'reference' => $entry->reference,
                    'amount' => (string) $entry->lines->sum('debit'),
                    'type' => $this->classifyTransactionType($entry),
                ];
            });
    }

    private function classifyTransactionType(JournalEntry $entry): string
    {
        $hasRevenue = $entry->lines->contains(fn ($line) => AccountCode::isRevenue($line->account->code ?? ''));

        if ($hasRevenue) {
            return 'income';
        }

        $hasExpense = $entry->lines->contains(fn ($line) => AccountCode::isExpense($line->account->code ?? ''));

        return $hasExpense ? 'expense' : 'transfer';
    }

    /**
     * Monthly revenue/expense series for the dashboard chart.
     *
     * Revenue and expenses come from the ledger, so the chart adds up to the
     * KPI cards above it. The forecast series stays invoice-based: expected
     * income from sent and overdue invoices has no ledger equivalent, because
     * nothing has been booked for it yet.
     *
     * @return array<string, mixed>
     */
    private function monthlyBreakdown(string $organizationId, int $year): array
    {
        $totals = $this->ledgerService->monthlyTotals($organizationId, $year);
        $byAccount = $this->ledgerService->monthlyTotalsByAccount($organizationId, $year);

        $forecastInvoices = $this->invoiceQuery->sentOrOverdueDueInYear($organizationId, $year)
            ->groupBy(fn ($i) => Carbon::parse($i->due_date)->month);

        $months = collect(range(1, 12));

        return [
            'monthIndices' => $months->values(),
            'revenue' => $months->map(fn ($m) => $totals['revenue'][$m])->values(),
            'expenses' => $months->map(fn ($m) => $totals['expenses'][$m])->values(),
            'forecast' => $months->map(fn ($m) => (string) $forecastInvoices->get($m, collect())->sum('total'))->values(),
            'revenueItems' => $months->map(fn ($m) => $this->formatItems($byAccount['revenue'][$m]))->values(),
            'expenseItems' => $months->map(fn ($m) => $this->formatItems($byAccount['expenses'][$m]))->values(),
            'forecastItems' => $months->map(fn ($m) => $forecastInvoices->get($m, collect())
                ->map(fn ($i) => $i->number.': '.$this->formatAmount((string) $i->total))
                ->values())->values(),
        ];
    }

    /**
     * Render tooltip items as "label: amount" strings, the shape the chart
     * already expects.
     *
     * @param  list<array{label: string, amount: string, overflow?: int}>  $items
     * @return list<string>
     */
    private function formatItems(array $items): array
    {
        return array_map(
            fn (array $item): string => $item['label'].': '.$this->formatAmount($item['amount']),
            $items,
        );
    }

    private function formatAmount(string $amount): string
    {
        return number_format((float) $amount, 2, '.', "'");
    }

    /**
     * Budget vs actual summary for the current fiscal year.
     *
     * The actuals are passed in rather than re-queried: they are the same
     * ledger totals the KPI cards show, and recomputing them here would let
     * the budget card drift from the cards above it.
     *
     * Returns null when no budgets are configured.
     *
     * @return array{budgetedRevenue: string, budgetedExpenses: string, actualRevenue: string, actualExpenses: string, revenueVariance: string, expenseVariance: string, monthsElapsed: int}|null
     */
    private function budgetSummary(string $organizationId, int $year, string $actualRevenue, string $actualExpenses): ?array
    {
        $budgets = Budget::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->forYear($year)
            ->with('account')
            ->get();

        if ($budgets->isEmpty()) {
            return null;
        }

        $monthsElapsed = $this->computeMonthsElapsed($organizationId, $year);

        $budgetedRevenue = '0.00';
        $budgetedExpenses = '0.00';

        foreach ($budgets as $budget) {
            $code = $budget->account->code ?? '';
            $annualBudget = Money::multiply2((string) $budget->monthly_amount, '12');

            if (AccountCode::isRevenue($code)) {
                $budgetedRevenue = Money::add($budgetedRevenue, $annualBudget);
            } elseif (AccountCode::isExpense($code)) {
                $budgetedExpenses = Money::add($budgetedExpenses, $annualBudget);
            }
        }

        // Pro-rated budget based on months elapsed
        $proRatedRevenue = Money::multiply2(Money::divide4($budgetedRevenue, '12'), (string) $monthsElapsed);
        $proRatedExpenses = Money::multiply2(Money::divide4($budgetedExpenses, '12'), (string) $monthsElapsed);

        return [
            'budgetedRevenue' => $budgetedRevenue,
            'budgetedExpenses' => $budgetedExpenses,
            'proRatedRevenue' => $proRatedRevenue,
            'proRatedExpenses' => $proRatedExpenses,
            'actualRevenue' => $actualRevenue,
            'actualExpenses' => $actualExpenses,
            'revenueVariance' => ! Money::isZero($proRatedRevenue)
                ? Money::multiply2(Money::divide4(Money::subtract($actualRevenue, $proRatedRevenue), $proRatedRevenue), '100')
                : '0.00',
            'expenseVariance' => ! Money::isZero($proRatedExpenses)
                ? Money::multiply2(Money::divide4(Money::subtract($actualExpenses, $proRatedExpenses), $proRatedExpenses), '100')
                : '0.00',
            'monthsElapsed' => $monthsElapsed,
        ];
    }

    /**
     * Compute months elapsed for a given fiscal year, respecting org fiscal_year_start.
     *
     * For past years returns the full duration (typically 12 months, but can be
     * up to 23 for Swiss long fiscal years). For the current fiscal year returns
     * the number of months elapsed since its start.
     */
    private function computeMonthsElapsed(string $organizationId, int $year): int
    {
        // Prefer a record from the fiscal_years table (handles long fiscal years).
        $fiscalYear = FiscalYear::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->forDate(Carbon::create($year, 6, 30)->toDateString())
            ->first();

        if ($fiscalYear !== null) {
            $fyStart = $fiscalYear->start_date->copy()->startOfDay();
            $fyEnd = $fiscalYear->end_date->copy()->endOfDay();
        } else {
            $org = Organization::find($organizationId);
            $startMonthDay = $org?->fiscal_year_start ?: '01-01';
            $parts = preg_split('/[\.\-\/]/', (string) $startMonthDay);
            $month = (int) ($parts[0] ?? 1);
            $day = (int) ($parts[1] ?? 1);
            if ($month < 1 || $month > 12) {
                $month = 1;
            }
            if ($day < 1 || $day > 31) {
                $day = 1;
            }

            $fyStart = Carbon::create($year, $month, $day)->startOfDay();
            $fyEnd = $fyStart->copy()->addYear()->subDay()->endOfDay();
        }

        $now = now();
        $totalMonths = (int) $fyStart->diffInMonths($fyEnd->copy()->addDay());

        if ($now->greaterThan($fyEnd)) {
            return max(1, $totalMonths);
        }

        if ($now->lessThan($fyStart)) {
            return 0;
        }

        return (int) $fyStart->diffInMonths($now->startOfMonth()) + 1;
    }

    /**
     * Current-quarter VAT liability summary.
     *
     * Returns null when no VAT entries exist for the quarter.
     *
     * @return array{vatPayable: string, quarterLabel: string, quarterEnd: string}|null
     */
    private function currentQuarterVat(string $organizationId): ?array
    {
        $now = now();
        $quarter = (int) ceil($now->month / 3);
        $fromMonth = ($quarter - 1) * 3 + 1;
        $from = Carbon::create($now->year, $fromMonth, 1)->toDateString();
        $to = Carbon::create($now->year, $fromMonth, 1)->endOfQuarter()->toDateString();

        $report = $this->vatReportService->generate($organizationId, $from, $to);

        // No VAT activity this quarter
        if ($report['total_revenue'] === '0.00' && $report['input_vat'] === '0.00') {
            return null;
        }

        return [
            'vatPayable' => $report['vat_payable'],
            'quarterLabel' => 'Q'.$quarter.' '.$now->year,
            'quarterEnd' => $to,
        ];
    }

    /**
     * Receivables aging summary with overdue totals per bracket.
     *
     * Returns null when there are no overdue receivables.
     *
     * @return array{overdueCount: int, totalOverdue: string, brackets: array<string, string>}|null
     */
    private function agingSummary(string $organizationId): ?array
    {
        $report = $this->agingReportService->generate($organizationId, 'receivables');

        $overdueBrackets = ['1_30', '31_60', '61_90', '90_plus'];
        $totalOverdue = '0.00';
        $overdueCount = 0;
        $bracketTotals = [];

        foreach ($overdueBrackets as $key) {
            $bracket = $report['brackets'][$key];
            $totalOverdue = Money::add($totalOverdue, $bracket['total']);
            $overdueCount += count($bracket['items']);
            $bracketTotals[$key] = $bracket['total'];
        }

        if (Money::isZero($totalOverdue)) {
            return null;
        }

        return [
            'overdueCount' => $overdueCount,
            'totalOverdue' => $totalOverdue,
            'brackets' => $bracketTotals,
        ];
    }

    /**
     * Resolve the fiscal year to display on the dashboard.
     *
     * Priority order:
     * 1. Most recent year with operational ledger activity (capped at the
     *    current year).
     * 2. Most recent year with expense or invoice activity, for organizations
     *    that record documents before posting them.
     * 3. Current calendar year (no data at all).
     *
     * Structural entries are deliberately excluded from step 1: year-end
     * closing and opening-balance entries are dated in the *next* calendar
     * year, so counting them would send the dashboard to a year that has no
     * real bookkeeping in it yet and show all-zero KPIs right after a closing.
     */
    private function resolveDisplayYear(string $organizationId): int
    {
        $currentYear = now()->year;

        $latestDate = $this->ledgerService->latestOperationalEntryDate($organizationId);

        if ($latestDate) {
            return min((int) Carbon::parse($latestDate)->year, $currentYear);
        }

        // No posted bookkeeping yet — fall back to document dates so an org
        // that has captured invoices or expenses still lands on the right year.
        $latestExpenseDate = Expense::where('organization_id', $organizationId)->max('date');
        $latestInvoiceDate = Invoice::where('organization_id', $organizationId)->max('issue_date');

        $activityYears = array_filter([
            $latestExpenseDate ? (int) Carbon::parse($latestExpenseDate)->year : null,
            $latestInvoiceDate ? (int) Carbon::parse($latestInvoiceDate)->year : null,
        ]);

        if (! empty($activityYears)) {
            return min(max($activityYears), $currentYear);
        }

        return $currentYear;
    }

    /**
     * Whether the organization has captured any invoice or expense, posted
     * or not.
     *
     * This is what decides the onboarding empty state: someone who has
     * entered a document has started working, even though the KPI cards
     * stay at zero until it is booked.
     */
    private function hasCapturedDocuments(string $organizationId): bool
    {
        return Invoice::where('organization_id', $organizationId)->exists()
            || Expense::where('organization_id', $organizationId)->exists();
    }

    /**
     * Whether the organization recorded any activity during the given
     * calendar year. Used to suppress year-over-year trend indicators when
     * there is no real comparison baseline.
     */
    private function hasActivityInYear(string $organizationId, int $year): bool
    {
        if ($this->ledgerService->hasOperationalActivityInYear($organizationId, $year)) {
            return true;
        }

        $hasInvoice = Invoice::where('organization_id', $organizationId)
            ->whereYear('issue_date', $year)
            ->exists();

        if ($hasInvoice) {
            return true;
        }

        return Expense::where('organization_id', $organizationId)
            ->whereYear('date', $year)
            ->exists();
    }

    /**
     * Calendar-year date bounds, spread into the from/to arguments of the
     * ledger queries.
     *
     * @return array{0: string, 1: string}
     */
    private function yearBounds(int $year): array
    {
        return ["{$year}-01-01", "{$year}-12-31"];
    }

    private function pendingOcrScans(string $organizationId): int
    {
        return ReceiptScan::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['pending', 'completed'])
            ->where('expires_at', '>', now())
            ->count();
    }
}

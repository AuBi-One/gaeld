<?php

namespace App\Domains\Reporting\Services;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Expenses\Models\RecurringExpense;
use App\Domains\Invoicing\Enums\RecurrenceFrequency;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Queries\DeductionRateSetQuery;
use App\Domains\Payroll\Services\SwissDeductionService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Answers one question: how long does the money on the company account last?
 *
 * The burn rate is assembled from three parts — payroll, recurring charges,
 * and everything else — and each part is taken from master data when the
 * organisation maintains it, falling back to a run rate derived from the
 * ledger when it does not. Every figure carries the source it came from, so
 * the UI can tell a commitment apart from an estimate rather than presenting
 * a guess with the confidence of a contract.
 */
class LiquidityForecastService
{
    /** Asset accounts counted as spendable cash (Swiss KMU chart: group 10). */
    private const LIQUID_ASSET_PREFIXES = ['10'];

    /**
     * Liability groups that fall due within the year. 24xx and above are
     * long-term debt and must not shorten a twelve-month runway.
     */
    private const SHORT_TERM_LIABILITY_PREFIXES = ['20', '21', '22', '23'];

    /** Expense accounts that payroll master data already accounts for. */
    private const PAYROLL_EXPENSE_PREFIXES = ['5'];

    /** Months of history used to derive a run rate. */
    private const RUN_RATE_WINDOW = 6;

    public function __construct(
        private readonly LedgerQueryService $ledgerService,
        private readonly SwissDeductionService $deductionService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forecast(string $organizationId, int $horizonMonths = 12): array
    {
        return Cache::tags(["org:{$organizationId}:dashboard"])->remember(
            "liquidity_forecast:{$organizationId}:{$horizonMonths}",
            300,
            fn () => $this->computeForecast($organizationId, $horizonMonths)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computeForecast(string $organizationId, int $horizonMonths): array
    {
        $liquidAssets = $this->ledgerService->groupBalance($organizationId, AccountType::Asset, self::LIQUID_ASSET_PREFIXES);
        $shortTermLiabilities = $this->ledgerService->groupBalance($organizationId, AccountType::Liability, self::SHORT_TERM_LIABILITY_PREFIXES);
        $availableFunds = Money::subtract($liquidAssets, $shortTermLiabilities);

        [$windowFrom, $windowTo] = $this->runRateWindow($organizationId);

        $payroll = $this->payrollBurn($organizationId, $windowFrom, $windowTo);
        $recurring = $this->recurringBurn($organizationId);
        $other = $this->otherBurn($organizationId, $windowFrom, $windowTo, $recurring['accountCodes']);

        $monthlyBurn = Money::add(Money::add($payroll['amount'], $recurring['amount']), $other['amount']);
        $monthlyRevenue = $this->revenueRunRate($organizationId, $windowFrom, $windowTo);

        return [
            'availableFunds' => $availableFunds,
            'liquidAssets' => $liquidAssets,
            'shortTermLiabilities' => $shortTermLiabilities,
            'burn' => [
                'payroll' => ['amount' => $payroll['amount'], 'source' => $payroll['source'], 'headcount' => $payroll['headcount']],
                'recurring' => ['amount' => $recurring['amount'], 'source' => $recurring['source'], 'count' => $recurring['count']],
                'other' => ['amount' => $other['amount'], 'source' => $other['source']],
                'total' => $monthlyBurn,
            ],
            'monthlyRevenue' => $monthlyRevenue,
            'scenarios' => [
                'withoutRevenue' => $this->project($availableFunds, $monthlyBurn, '0.00', $horizonMonths),
                'withRevenue' => $this->project($availableFunds, $monthlyBurn, $monthlyRevenue['amount'], $horizonMonths),
            ],
            'window' => ['from' => $windowFrom, 'to' => $windowTo, 'months' => self::RUN_RATE_WINDOW],
            'horizonMonths' => $horizonMonths,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Burn components
    // ──────────────────────────────────────────────────────────────

    /**
     * Monthly payroll cost: gross salary plus the employer's own social
     * charges, which are a real outflow but never appear on a salary slip's
     * net figure.
     *
     * @return array{amount: string, source: string, headcount: int}
     */
    private function payrollBurn(string $organizationId, string $windowFrom, string $windowTo): array
    {
        $employees = Employee::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('exit_date')->orWhere('exit_date', '>=', now()->toDateString()))
            ->get();

        if ($employees->isEmpty()) {
            return [
                'amount' => $this->ledgerRunRate(
                    $organizationId,
                    AccountType::Expense,
                    $windowFrom,
                    $windowTo,
                    includeOnlyPrefixes: self::PAYROLL_EXPENSE_PREFIXES,
                    average: true,
                ),
                'source' => 'ledger',
                'headcount' => 0,
            ];
        }

        $total = '0.00';
        $today = now()->toDateString();

        foreach ($employees as $employee) {
            // The employee's deduction-rate set for today, as payroll resolves
            // it; an employee whose set is missing counts with the defaults
            // here (payroll itself refuses to calculate that slip).
            try {
                $rates = DeductionRateSetQuery::forEmployee($employee, $today)?->rates()->where('is_active', true)->get();
            } catch (\DomainException) {
                $rates = null;
            }

            $gross = Money::normalize((string) $employee->gross_salary);

            // A thirteenth salary is one extra month spread across twelve.
            if ($employee->has_thirteenth_salary) {
                $gross = Money::divideRounded(Money::multiply($gross, '13'), '12');
            }

            $employerCharges = $this->deductionService->calculateDeductions($gross, $rates)['total_employer'];
            $total = Money::add($total, Money::add($gross, $employerCharges));
        }

        return ['amount' => $total, 'source' => 'master_data', 'headcount' => $employees->count()];
    }

    /**
     * Monthly cost of active recurring charges, normalised to a month.
     *
     * VAT is included: the runway is about cash leaving the account, and the
     * input tax only comes back at the next settlement.
     *
     * @return array{amount: string, source: string, count: int, accountCodes: list<string>}
     */
    private function recurringBurn(string $organizationId): array
    {
        $recurring = RecurringExpense::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString()))
            ->get();

        if ($recurring->isEmpty()) {
            return ['amount' => '0.00', 'source' => 'none', 'count' => 0, 'accountCodes' => []];
        }

        $total = '0.00';

        foreach ($recurring as $expense) {
            $gross = Money::add(
                Money::normalize((string) $expense->amount),
                Money::normalize((string) $expense->vat_amount),
            );

            $total = Money::add($total, $this->toMonthlyAmount($gross, $expense->frequency));
        }

        return [
            'amount' => $total,
            'source' => 'master_data',
            'count' => $recurring->count(),
            'accountCodes' => array_values(array_unique(array_filter(
                $recurring->pluck('expense_account_code')->map(fn ($code) => (string) $code)->all(),
                fn (string $code) => $code !== '',
            ))),
        ];
    }

    /**
     * Everything the other two components do not already cover, as a run rate
     * from the ledger.
     *
     * Payroll accounts are always excluded, whichever source the payroll
     * component used: from master data they are a commitment, from the ledger
     * they are the very same bookings. Counting them here as well would
     * double the largest item on the list.
     *
     * @param  list<string>  $recurringAccountCodes
     * @return array{amount: string, source: string}
     */
    private function otherBurn(string $organizationId, string $windowFrom, string $windowTo, array $recurringAccountCodes): array
    {
        return [
            'amount' => $this->ledgerRunRate(
                $organizationId,
                AccountType::Expense,
                $windowFrom,
                $windowTo,
                excludePrefixes: self::PAYROLL_EXPENSE_PREFIXES,
                excludeCodes: $recurringAccountCodes,
            ),
            'source' => 'ledger',
        ];
    }

    /**
     * @return array{amount: string, source: string}
     */
    private function revenueRunRate(string $organizationId, string $windowFrom, string $windowTo): array
    {
        return [
            'amount' => $this->ledgerRunRate($organizationId, AccountType::Revenue, $windowFrom, $windowTo),
            'source' => 'ledger',
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Projection
    // ──────────────────────────────────────────────────────────────

    /**
     * Walk the balance forward month by month.
     *
     * Returns monthsRemaining = null when the money outlives the horizon;
     * that is not the same as "forever", and the caller should say so.
     *
     * @return array{monthsRemaining: int|null, depletionDate: string|null, sustainable: bool, series: list<array{month: string, balance: string}>}
     */
    private function project(string $availableFunds, string $monthlyBurn, string $monthlyRevenue, int $horizonMonths): array
    {
        $netDrain = Money::subtract($monthlyBurn, $monthlyRevenue);
        $balance = $availableFunds;
        $series = [];
        $monthsRemaining = null;
        $depletionDate = null;

        $cursor = now()->startOfMonth();

        for ($month = 1; $month <= $horizonMonths; $month++) {
            $cursor = $cursor->copy()->addMonth();
            $balance = Money::subtract($balance, $netDrain);
            $series[] = ['month' => $cursor->format('Y-m'), 'balance' => $balance];

            if ($monthsRemaining === null && Money::isNegative($balance)) {
                // The runway ends with the last month the balance still holds,
                // not with the month it goes under.
                $monthsRemaining = $month - 1;
                $depletionDate = $cursor->copy()->subMonth()->endOfMonth()->toDateString();
            }
        }

        return [
            'monthsRemaining' => $monthsRemaining,
            'depletionDate' => $depletionDate,
            'sustainable' => ! Money::isPositive($netDrain),
            'series' => $series,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Monthly run rate over the window, as a median by default.
     *
     * The median asks "what does an ordinary month cost", which is the right
     * question for costs that recur monthly: one annual insurance premium
     * cannot drag it away from the truth.
     *
     * $average switches to the mean, which is the right question for a cost
     * that *accrues* every month but is *booked* in batches. Wages are the
     * case in point: an organisation that posts two quarters in one entry
     * leaves most months at zero, and a median of those months would report a
     * payroll cost near zero for a business that very much has employees.
     *
     * Neither statistic handles a cost that genuinely occurs once a year — for
     * those the answer is master data, not a smarter average, which is why the
     * card marks every ledger-derived figure as an estimate.
     *
     * @param  list<string>  $includeOnlyPrefixes
     * @param  list<string>  $excludePrefixes
     * @param  list<string>  $excludeCodes
     */
    private function ledgerRunRate(
        string $organizationId,
        AccountType $type,
        string $windowFrom,
        string $windowTo,
        array $includeOnlyPrefixes = [],
        array $excludePrefixes = [],
        array $excludeCodes = [],
        bool $average = false,
    ): string {
        $totals = $this->ledgerService->monthlyTotalsInWindow(
            $organizationId,
            $type,
            $windowFrom,
            $windowTo,
            $excludePrefixes,
            $excludeCodes,
            $includeOnlyPrefixes,
        );

        $values = array_values($totals);

        return $average ? $this->mean($values) : $this->median($values);
    }

    /**
     * @param  list<string>  $values
     */
    private function mean(array $values): string
    {
        if ($values === []) {
            return '0.00';
        }

        $sum = '0.00';

        foreach ($values as $value) {
            $sum = Money::add($sum, $value);
        }

        return Money::divideRounded($sum, (string) count($values));
    }

    /**
     * @param  list<string>  $values
     */
    private function median(array $values): string
    {
        if ($values === []) {
            return '0.00';
        }

        usort($values, fn (string $a, string $b) => (float) $a <=> (float) $b);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return Money::normalize($values[$middle]);
        }

        return Money::divideRounded(Money::add($values[$middle - 1], $values[$middle]), '2');
    }

    private function toMonthlyAmount(string $amount, RecurrenceFrequency $frequency): string
    {
        return match ($frequency) {
            RecurrenceFrequency::Weekly => Money::divideRounded(Money::multiply($amount, '52'), '12'),
            RecurrenceFrequency::Monthly => $amount,
            RecurrenceFrequency::Quarterly => Money::divideRounded($amount, '3'),
            RecurrenceFrequency::Yearly => Money::divideRounded($amount, '12'),
        };
    }

    /**
     * The window of complete months the run rates are read from.
     *
     * It ends with the last month that actually holds bookkeeping, not with
     * the current month: a half-finished month, or a gap after the last entry,
     * would otherwise pull every run rate towards zero.
     *
     * @return array{0: string, 1: string}
     */
    private function runRateWindow(string $organizationId): array
    {
        $latest = $this->ledgerService->latestOperationalEntryDate($organizationId);
        $end = ($latest ? Carbon::parse($latest) : now())->startOfMonth();

        // Drop the closing month when it is the current, still-incomplete one.
        if ($end->isSameMonth(now())) {
            $end = $end->subMonth();
        }

        $start = $end->copy()->subMonths(self::RUN_RATE_WINDOW - 1);

        return [$start->toDateString(), $end->endOfMonth()->toDateString()];
    }
}

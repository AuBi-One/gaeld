<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\CreatesAccountingFixtures;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Covers the aggregate queries the dashboard reads its KPI figures from.
 */
class LedgerQueryAggregatesTest extends TestCase
{
    use CreatesAccountingFixtures, RefreshDatabase, WithAuthenticatedOrganization;

    private LedgerQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->service = app(LedgerQueryService::class);
    }

    public function test_period_totals_return_natural_signed_revenue_and_expenses(): void
    {
        $this->postSale('2026-02-10', '2000.00');
        $this->postSale('2026-05-04', '500.00');
        $this->postCost('2026-03-05', '750.00');

        $totals = $this->service->periodTotals($this->org->id, '2026-01-01', '2026-12-31');

        $this->assertSame('2500.00', $totals['revenue']);
        $this->assertSame('750.00', $totals['expenses']);
    }

    public function test_period_totals_respect_the_date_range(): void
    {
        $this->postSale('2025-12-31', '900.00');
        $this->postSale('2026-01-01', '100.00');

        $totals = $this->service->periodTotals($this->org->id, '2026-01-01', '2026-12-31');

        $this->assertSame('100.00', $totals['revenue']);
    }

    public function test_period_totals_ignore_unposted_entries(): void
    {
        $this->postSale('2026-02-10', '2000.00');
        JournalEntry::where('organization_id', $this->org->id)->update(['is_posted' => false]);
        app(LedgerService::class)->flushCache($this->org->id);

        $totals = $this->service->periodTotals($this->org->id, '2026-01-01', '2026-12-31');

        $this->assertSame('0.00', $totals['revenue']);
    }

    public function test_period_totals_ignore_structural_entries(): void
    {
        $this->postSale('2026-02-10', '2000.00');
        $this->markLatestEntryAs('year_end_closing');

        $totals = $this->service->periodTotals($this->org->id, '2026-01-01', '2026-12-31');

        $this->assertSame('0.00', $totals['revenue'], 'A closing entry is bookkeeping, not turnover.');
    }

    public function test_period_totals_ignore_historical_summaries(): void
    {
        $this->postSale('2026-02-10', '2000.00');
        $this->postSale('2026-03-01', '9000.00');
        $this->markLatestEntryAs('historical_summary');

        $totals = $this->service->periodTotals($this->org->id, '2026-01-01', '2026-12-31');

        $this->assertSame('2000.00', $totals['revenue']);
    }

    public function test_period_totals_are_scoped_to_the_organization(): void
    {
        $this->postSale('2026-02-10', '2000.00');

        $totals = $this->service->periodTotals((string) Str::uuid(), '2026-01-01', '2026-12-31');

        $this->assertSame('0.00', $totals['revenue']);
        $this->assertSame('0.00', $totals['expenses']);
    }

    public function test_monthly_totals_cover_all_twelve_months(): void
    {
        $this->postSale('2026-02-10', '2000.00');
        $this->postCost('2026-02-20', '300.00');

        $totals = $this->service->monthlyTotals($this->org->id, 2026);

        $this->assertSame(range(1, 12), array_keys($totals['revenue']));
        $this->assertSame('2000.00', $totals['revenue'][2]);
        $this->assertSame('300.00', $totals['expenses'][2]);
        $this->assertSame('0.00', $totals['revenue'][1]);
        $this->assertSame('0.00', $totals['expenses'][12]);
    }

    public function test_monthly_totals_by_account_group_and_label_each_month(): void
    {
        $this->postCost('2026-03-05', '750.00');
        $this->postCost('2026-03-19', '250.00');

        $byAccount = $this->service->monthlyTotalsByAccount($this->org->id, 2026);

        $this->assertSame(
            [['label' => '6500 Administration', 'amount' => '1000.00']],
            $byAccount['expenses'][3],
        );
        $this->assertSame([], $byAccount['expenses'][4]);
    }

    public function test_monthly_totals_by_account_drop_accounts_that_net_to_zero(): void
    {
        $this->postCost('2026-03-05', '750.00');
        $this->postJournalEntry('2026-03-06', [
            $this->journalLine($this->account('1020'), '750.00', '0.00'),
            $this->journalLine($this->account('6500'), '0.00', '750.00'),
        ], 'REV-1');

        $byAccount = $this->service->monthlyTotalsByAccount($this->org->id, 2026);

        $this->assertSame([], $byAccount['expenses'][3], 'A booking and its reversal say nothing worth showing.');
    }

    public function test_latest_operational_entry_date_skips_structural_entries(): void
    {
        $this->postSale('2026-02-10', '2000.00');
        $this->postSale('2027-01-01', '1.00');
        $this->markLatestEntryAs('year_end_closing');

        $this->assertSame('2026-02-10', $this->service->latestOperationalEntryDate($this->org->id));
    }

    public function test_has_operational_activity_in_year(): void
    {
        $this->postSale('2026-02-10', '2000.00');

        $this->assertTrue($this->service->hasOperationalActivityInYear($this->org->id, 2026));
        $this->assertFalse($this->service->hasOperationalActivityInYear($this->org->id, 2025));
    }

    // ──────────────────────────────────────────────────────────────
    //  Fixtures
    // ──────────────────────────────────────────────────────────────

    private function postSale(string $date, string $amount): void
    {
        $this->postJournalEntry($date, [
            $this->journalLine($this->account('1020'), $amount, '0.00'),
            $this->journalLine($this->account('3000'), '0.00', $amount),
        ], 'SALE-'.$date);
    }

    private function postCost(string $date, string $amount): void
    {
        $this->postJournalEntry($date, [
            $this->journalLine($this->account('6500'), $amount, '0.00'),
            $this->journalLine($this->account('1020'), '0.00', $amount),
        ], 'COST-'.$date);
    }

    private function markLatestEntryAs(string $type): void
    {
        JournalEntry::where('organization_id', $this->org->id)
            ->orderByDesc('date')
            ->first()
            ->update(['type' => $type]);

        app(LedgerService::class)->flushCache($this->org->id);
    }

    private function account(string $code): Account
    {
        $definitions = [
            '1020' => ['Bank', AccountType::Asset],
            '3000' => ['Sales', AccountType::Revenue],
            '6500' => ['Administration', AccountType::Expense],
        ];

        [$name, $type] = $definitions[$code];

        return Account::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => $code],
            ['name' => $name, 'type' => $type->value],
        );
    }
}

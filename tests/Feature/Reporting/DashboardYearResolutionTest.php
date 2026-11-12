<?php

namespace Tests\Feature\Reporting;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Expenses\Actions\ApproveExpenseAction;
use App\Domains\Expenses\Actions\CreateExpenseAction;
use App\Domains\Expenses\DTOs\CreateExpenseData;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Reporting\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Tests\Traits\CreatesAccountingFixtures;
use Tests\Traits\WithAuthenticatedOrganization;

class DashboardYearResolutionTest extends TestCase
{
    use CreatesAccountingFixtures, RefreshDatabase, WithAuthenticatedOrganization;

    private DashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->service = app(DashboardService::class);
    }

    // ──────────────────────────────────────────────────────────────
    //  resolveDisplayYear — no posted journal entries
    //
    //  Document dates still steer which year the dashboard opens on, so an
    //  org that captures expenses or invoices before booking them does not
    //  land on an empty year. The KPI figures themselves stay at zero until
    //  something is actually posted — they report the books, not the inbox.
    // ──────────────────────────────────────────────────────────────

    public function test_display_year_resolves_to_prior_year_when_expense_dated_in_prior_year(): void
    {
        Carbon::setTestNow('2026-04-12 10:00:00');

        Expense::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'category' => 'Utilities',
            'description' => 'Clef local',
            'amount' => '76.60',
            'vat_amount' => '0.00',
            'date' => '2025-12-16',
            'status' => 'approved',
            'currency' => 'CHF',
        ]);

        $metrics = $this->service->metrics($this->org->id);

        $this->assertEquals(2025, $metrics['displayYear']);
        $this->assertEquals('0.00', $metrics['expenses'], 'An unposted expense must not reach the KPI cards.');

        Carbon::setTestNow();
    }

    public function test_display_year_resolves_to_current_year_when_expense_dated_in_current_year(): void
    {
        Carbon::setTestNow('2026-04-12 10:00:00');

        Expense::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'category' => 'Utilities',
            'description' => 'Office rent',
            'amount' => '1200.00',
            'vat_amount' => '0.00',
            'date' => '2026-03-01',
            'status' => 'approved',
            'currency' => 'CHF',
        ]);

        $metrics = $this->service->metrics($this->org->id);

        $this->assertEquals(2026, $metrics['displayYear']);
        $this->assertEquals('0.00', $metrics['expenses'], 'An unposted expense must not reach the KPI cards.');

        Carbon::setTestNow();
    }

    public function test_display_year_uses_most_recent_when_both_years_have_expenses(): void
    {
        Carbon::setTestNow('2026-04-12 10:00:00');

        // 2025 expense
        Expense::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'category' => 'Utilities',
            'description' => 'Old expense',
            'amount' => '500.00',
            'vat_amount' => '0.00',
            'date' => '2025-06-01',
            'status' => 'approved',
            'currency' => 'CHF',
        ]);

        // 2026 expense
        Expense::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'category' => 'Utilities',
            'description' => 'New expense',
            'amount' => '300.00',
            'vat_amount' => '0.00',
            'date' => '2026-01-15',
            'status' => 'approved',
            'currency' => 'CHF',
        ]);

        $metrics = $this->service->metrics($this->org->id);

        $this->assertEquals(2026, $metrics['displayYear']);
        $this->assertEquals('0.00', $metrics['expenses'], 'An unposted expense must not reach the KPI cards.');

        Carbon::setTestNow();
    }

    public function test_display_year_is_capped_at_current_year_even_if_future_expense_exists(): void
    {
        Carbon::setTestNow('2026-04-12 10:00:00');

        Expense::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'category' => 'Utilities',
            'description' => 'Future expense',
            'amount' => '100.00',
            'vat_amount' => '0.00',
            'date' => '2027-01-01',
            'status' => 'pending',
            'currency' => 'CHF',
        ]);

        $metrics = $this->service->metrics($this->org->id);

        $this->assertEquals(2026, $metrics['displayYear']);

        Carbon::setTestNow();
    }

    public function test_display_year_falls_back_to_current_year_when_no_activity(): void
    {
        Carbon::setTestNow('2026-04-12 10:00:00');

        $metrics = $this->service->metrics($this->org->id);

        $this->assertEquals(2026, $metrics['displayYear']);
        $this->assertEquals('0.00', $metrics['expenses']);

        Carbon::setTestNow();
    }

    public function test_display_year_resolves_to_invoice_year_when_only_invoices_exist(): void
    {
        Carbon::setTestNow('2026-04-12 10:00:00');

        Invoice::create([
            'organization_id' => $this->org->id,
            'number' => 'INV-2025-001',
            'status' => 'sent',
            'issue_date' => '2025-11-01',
            'due_date' => '2025-12-01',
            'subtotal' => '2000.00',
            'vat_amount' => '0.00',
            'total' => '2000.00',
            'currency' => 'CHF',
        ]);

        $metrics = $this->service->metrics($this->org->id);

        $this->assertEquals(2025, $metrics['displayYear']);

        Carbon::setTestNow();
    }

    // ──────────────────────────────────────────────────────────────
    //  Cache invalidation
    // ──────────────────────────────────────────────────────────────

    public function test_dashboard_cache_flushed_after_expense_created_via_action(): void
    {
        Carbon::setTestNow('2026-04-12 10:00:00');

        // Prime the cache with empty metrics
        $first = $this->service->metrics($this->org->id);
        $this->assertSame(0, $first['pendingExpenses']['count']);

        // Create expense and flush cache (as the controller would)
        $action = new CreateExpenseAction;
        $action->execute(CreateExpenseData::fromArray([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'category' => 'Software and Subscriptions',
            'description' => 'New tool',
            'amount' => '150.00',
            'vat_amount' => '0.00',
            'date' => '2026-04-01',
        ]));
        $this->service->flushCache($this->org->id);

        // Re-fetch should reflect the new expense
        $second = $this->service->metrics($this->org->id);
        $this->assertSame(1, $second['pendingExpenses']['count']);
        $this->assertEquals('150.00', $second['pendingExpenses']['total']);
        $this->assertEquals(2026, $second['displayYear']);

        Carbon::setTestNow();
    }

    public function test_dashboard_cache_flushed_after_expense_approved(): void
    {
        Carbon::setTestNow('2026-04-12 10:00:00');

        $expense = Expense::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'category' => 'Utilities',
            'description' => 'Pending expense',
            'amount' => '500.00',
            'vat_amount' => '0.00',
            'date' => '2026-02-15',
            'status' => 'pending',
            'currency' => 'CHF',
        ]);

        // Prime cache
        $this->service->metrics($this->org->id);

        // Approve and flush cache (as the controller would)
        $approveAction = new ApproveExpenseAction;
        $approveAction->execute($expense);
        $this->service->flushCache($this->org->id);

        // Approving clears the expense out of the pending bucket; the cache
        // must have been dropped for that to be visible.
        $metrics = $this->service->metrics($this->org->id);
        $this->assertSame(0, $metrics['pendingExpenses']['count']);

        Carbon::setTestNow();
    }

    // ──────────────────────────────────────────────────────────────
    //  Ledger-sourced figures
    // ──────────────────────────────────────────────────────────────

    public function test_posted_entries_drive_revenue_expenses_and_display_year(): void
    {
        Carbon::setTestNow('2026-04-12 10:00:00');

        $this->postSale('2026-02-10', '2000.00');
        $this->postCost('2026-03-05', '750.00');

        $metrics = $this->service->metrics($this->org->id);

        $this->assertEquals(2026, $metrics['displayYear']);
        $this->assertEquals('2000.00', $metrics['revenue']);
        $this->assertEquals('750.00', $metrics['expenses']);
        $this->assertEquals('1250.00', $metrics['balance']);
        $this->assertEquals('2000.00', $metrics['monthlyBreakdown']['revenue'][1]);
        $this->assertEquals('750.00', $metrics['monthlyBreakdown']['expenses'][2]);
    }

    public function test_ledger_activity_wins_over_a_later_document_date(): void
    {
        Carbon::setTestNow('2026-04-12 10:00:00');

        $this->postSale('2025-11-20', '900.00');

        // A captured but unposted 2026 invoice must not pull the dashboard
        // away from the year that actually holds the bookkeeping.
        Invoice::create([
            'organization_id' => $this->org->id,
            'number' => 'INV-2026-001',
            'status' => 'draft',
            'issue_date' => '2026-03-01',
            'due_date' => '2026-04-01',
            'subtotal' => '5000.00',
            'vat_amount' => '0.00',
            'total' => '5000.00',
            'currency' => 'CHF',
        ]);

        $metrics = $this->service->metrics($this->org->id);

        $this->assertEquals(2025, $metrics['displayYear']);
        $this->assertEquals('900.00', $metrics['revenue']);
    }

    public function test_year_end_closing_entries_are_excluded(): void
    {
        Carbon::setTestNow('2026-04-12 10:00:00');

        $this->postSale('2026-02-10', '2000.00');

        // A closing entry moves the revenue balance to equity. Counting it
        // would cancel the year's revenue out to zero.
        $entry = app(LedgerService::class)->postEntry($this->org->id, new JournalEntryData(
            date: '2026-12-31',
            reference: 'CLOSE-2026',
            description: 'Year-end closing',
            lines: [
                $this->journalLine($this->account('3000'), '2000.00', '0.00'),
                $this->journalLine($this->account('2851'), '0.00', '2000.00'),
            ],
        ));
        $entry->update(['type' => 'year_end_closing']);
        $this->service->flushCache($this->org->id);
        app(LedgerService::class)->flushCache($this->org->id);

        $metrics = $this->service->metrics($this->org->id);

        $this->assertEquals('2000.00', $metrics['revenue']);
        $this->assertEquals(2026, $metrics['displayYear']);
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

    /**
     * Resolve a chart-of-accounts entry, creating it on first use so each
     * test only has to name the codes it actually books against.
     */
    private function account(string $code): Account
    {
        $definitions = [
            '1020' => ['Bank', AccountType::Asset],
            '2851' => ['Retained earnings', AccountType::Equity],
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

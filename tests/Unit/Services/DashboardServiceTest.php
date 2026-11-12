<?php

namespace Tests\Unit\Services;

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\VatReportService;
use App\Domains\Expenses\Services\ExpenseService;
use App\Domains\Invoicing\Queries\InvoiceReportingQuery;
use App\Domains\Reporting\Services\AgingReportService;
use App\Domains\Reporting\Services\DashboardService;
use App\Support\DTOs\SummaryResult;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $orgId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgId = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_metrics_aggregates_summaries_recent_entries_and_monthly_breakdown(): void
    {
        Carbon::setTestNow('2026-03-20 12:00:00');

        $ledgerService = Mockery::mock(LedgerQueryService::class);
        $invoiceService = Mockery::mock(InvoiceReportingQuery::class);
        $expenseService = Mockery::mock(ExpenseService::class);

        $bankAccount = new Account([
            'code' => '1020',
            'name' => 'Bank',
        ]);
        $bankAccount->id = 10;

        $ledgerService->shouldReceive('periodTotals')->once()->with($this->orgId, '2026-01-01', '2026-12-31')
            ->andReturn(['revenue' => '1500.00', 'expenses' => '450.00']);
        $invoiceService->shouldReceive('unpaidSummary')->once()->with($this->orgId)->andReturn(new SummaryResult(2, '700.00'));
        $expenseService->shouldReceive('pendingSummary')->once()->with($this->orgId)->andReturn(new SummaryResult(1, '120.00'));
        $ledgerService->shouldReceive('latestOperationalEntryDate')->once()->with($this->orgId)->andReturn('2026-03-04');
        $ledgerService->shouldReceive('resolveAccount')->once()->with($this->orgId, '1020')->andReturn($bankAccount);
        $ledgerService->shouldReceive('accountBalance')->once()->with(10)->andReturn('800.00');
        $ledgerService->shouldReceive('recentEntries')->once()->with($this->orgId)->andReturn(collect([
            $this->makeEntry('je-1', '2026-03-02', 'Invoice payment', 'PAY-1', '3000', '500.00'),
            $this->makeEntry('je-2', '2026-03-04', 'Software expense', 'EXP-1', '6530', '120.00'),
        ]));

        $ledgerService->shouldReceive('monthlyTotals')->once()->with($this->orgId, 2026)->andReturn([
            'revenue' => $this->monthSeries([1 => '100.00', 3 => '200.00']),
            'expenses' => $this->monthSeries([1 => '50.00', 3 => '70.00']),
        ]);
        $ledgerService->shouldReceive('monthlyTotalsByAccount')->once()->with($this->orgId, 2026)->andReturn([
            'revenue' => $this->monthSeries([1 => [['label' => '3000 Sales', 'amount' => '100.00']]], []),
            'expenses' => $this->monthSeries([1 => [['label' => '6500 Hosting', 'amount' => '50.00']]], []),
        ]);
        $invoiceService->shouldReceive('sentOrOverdueDueInYear')->once()->with($this->orgId, 2026)->andReturn(collect([
            (object) ['number' => 'INV-3', 'total' => '300.00', 'due_date' => '2026-03-25'],
        ]));

        // Year-over-year comparison
        $ledgerService->shouldReceive('periodTotals')->once()->with($this->orgId, '2025-01-01', '2025-12-31')
            ->andReturn(['revenue' => '1200.00', 'expenses' => '400.00']);
        $ledgerService->shouldReceive('hasOperationalActivityInYear')->once()->with($this->orgId, 2025)->andReturn(true);

        $vatReportService = Mockery::mock(VatReportService::class);
        $vatReportService->shouldReceive('generate')->once()->andReturn([
            'total_revenue' => '0.00',
            'input_vat' => '0.00',
            'vat_payable' => '0.00',
        ]);

        $agingReportService = Mockery::mock(AgingReportService::class);
        $agingReportService->shouldReceive('generate')->once()->andReturn([
            'brackets' => [
                '1_30' => ['total' => '0.00', 'items' => []],
                '31_60' => ['total' => '0.00', 'items' => []],
                '61_90' => ['total' => '0.00', 'items' => []],
                '90_plus' => ['total' => '0.00', 'items' => []],
            ],
        ]);

        $service = new DashboardService($ledgerService, $invoiceService, $expenseService, $vatReportService, $agingReportService);

        $metrics = $service->metrics($this->orgId);

        $this->assertSame('1500.00', $metrics['revenue']);
        $this->assertSame('450.00', $metrics['expenses']);
        $this->assertSame('800.00', $metrics['cashBalance']);
        $this->assertSame('1050.00', $metrics['balance']);
        $this->assertSame(2, $metrics['unpaidInvoices']['count']);
        $this->assertSame('700.00', $metrics['unpaidInvoices']['total']);
        $this->assertSame('income', $metrics['recentTransactions']->first()['type']);
        $this->assertSame('expense', $metrics['recentTransactions']->last()['type']);
        $this->assertCount(12, $metrics['monthlyBreakdown']['monthIndices']);
        $this->assertSame('100.00', (string) $metrics['monthlyBreakdown']['revenue'][0]);
        $this->assertSame('300', (string) $metrics['monthlyBreakdown']['forecast'][2]);
        $this->assertSame(['3000 Sales: 100.00'], $metrics['monthlyBreakdown']['revenueItems'][0]);
        $this->assertSame(['6500 Hosting: 50.00'], $metrics['monthlyBreakdown']['expenseItems'][0]);
        $this->assertSame(2026, $metrics['displayYear']);
        $this->assertTrue($metrics['hasActivity']);
    }

    public function test_metrics_returns_zero_cash_balance_when_bank_account_is_missing(): void
    {
        Carbon::setTestNow('2026-03-20 12:00:00');

        $ledgerService = Mockery::mock(LedgerQueryService::class);
        $invoiceService = Mockery::mock(InvoiceReportingQuery::class);
        $expenseService = Mockery::mock(ExpenseService::class);

        $ledgerService->shouldReceive('periodTotals')->andReturn(['revenue' => '0.00', 'expenses' => '0.00']);
        $ledgerService->shouldReceive('hasOperationalActivityInYear')->andReturn(false);
        $invoiceService->shouldReceive('unpaidSummary')->once()->andReturn(new SummaryResult(0, '0.00'));
        $expenseService->shouldReceive('pendingSummary')->once()->andReturn(new SummaryResult(0, '0.00'));
        $ledgerService->shouldReceive('latestOperationalEntryDate')->once()->with($this->orgId)->andReturn(null);
        $ledgerService->shouldReceive('resolveAccount')->once()->andThrow(new ModelNotFoundException);
        $ledgerService->shouldReceive('recentEntries')->once()->andReturn(collect());
        $ledgerService->shouldReceive('monthlyTotals')->once()->andReturn([
            'revenue' => $this->monthSeries([]),
            'expenses' => $this->monthSeries([]),
        ]);
        $ledgerService->shouldReceive('monthlyTotalsByAccount')->once()->andReturn([
            'revenue' => $this->monthSeries([], []),
            'expenses' => $this->monthSeries([], []),
        ]);
        $invoiceService->shouldReceive('sentOrOverdueDueInYear')->once()->andReturn(collect());

        $vatReportService = Mockery::mock(VatReportService::class);
        $vatReportService->shouldReceive('generate')->once()->andReturn([
            'total_revenue' => '0.00',
            'input_vat' => '0.00',
            'vat_payable' => '0.00',
        ]);

        $agingReportService = Mockery::mock(AgingReportService::class);
        $agingReportService->shouldReceive('generate')->once()->andReturn([
            'brackets' => [
                '1_30' => ['total' => '0.00', 'items' => []],
                '31_60' => ['total' => '0.00', 'items' => []],
                '61_90' => ['total' => '0.00', 'items' => []],
                '90_plus' => ['total' => '0.00', 'items' => []],
            ],
        ]);

        $service = new DashboardService($ledgerService, $invoiceService, $expenseService, $vatReportService, $agingReportService);

        $metrics = $service->metrics($this->orgId);

        $this->assertSame('0.00', $metrics['cashBalance']);
        $this->assertTrue($metrics['recentTransactions']->isEmpty());
    }

    /**
     * Build a 1-12 keyed month series, filling unlisted months with $default.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, mixed>
     */
    private function monthSeries(array $values, mixed $default = '0.00'): array
    {
        $series = [];

        foreach (range(1, 12) as $month) {
            $series[$month] = $values[$month] ?? $default;
        }

        return $series;
    }

    private function makeEntry(string $id, string $date, string $description, string $reference, string $accountCode, string $amount): JournalEntry
    {
        $account = new Account(['code' => $accountCode]);

        $line = new TransactionLine([
            'debit' => $amount,
            'credit' => '0.00',
        ]);
        $line->setRelation('account', $account);

        $entry = new JournalEntry([
            'id' => $id,
            'date' => $date,
            'description' => $description,
            'reference' => $reference,
        ]);
        $entry->exists = true;
        $entry->setRelation('lines', new Collection([$line]));

        return $entry;
    }
}

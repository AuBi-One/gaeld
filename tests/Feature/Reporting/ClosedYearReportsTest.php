<?php

namespace Tests\Feature\Reporting;

use App\Domains\Accounting\Actions\YearEndClosingAction;
use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TaxDeclaration;
use App\Domains\Reporting\Services\ReportingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesAccountingFixtures;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * A closed year still shows what it earned and spent: the profit and loss
 * statement and the tax-return figures leave the year-end closing entry out;
 * the balance sheet keeps it (it carries the result into equity).
 */
class ClosedYearReportsTest extends TestCase
{
    use CreatesAccountingFixtures, RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        foreach ([
            ['1020', 'Bank', AccountType::Asset],
            ['2800', 'Share capital', AccountType::Equity],
            ['2900', 'Annual result', AccountType::Equity],
            ['3000', 'Revenue', AccountType::Revenue],
            ['6000', 'Expenses', AccountType::Expense],
            [AccountCode::OPENING_BALANCE, 'Opening balance', AccountType::Equity],
        ] as [$code, $name, $type]) {
            Account::create(['organization_id' => $this->organization->id, 'code' => $code, 'name' => $name, 'type' => $type->value, 'is_active' => true]);
        }

        $this->postJournalEntry('2025-01-05', [
            $this->journalLine($this->account('1020'), '1000.00', '0'),
            $this->journalLine($this->account('2800'), '0', '1000.00'),
        ], 'CAPITAL');
        $this->postJournalEntry('2025-03-01', [
            $this->journalLine($this->account('1020'), '5000.00', '0'),
            $this->journalLine($this->account('3000'), '0', '5000.00'),
        ], 'SALE');
        $this->postJournalEntry('2025-04-01', [
            $this->journalLine($this->account('6000'), '1200.00', '0'),
            $this->journalLine($this->account('1020'), '0', '1200.00'),
        ], 'COST');

        app(YearEndClosingAction::class)->execute($this->organization, [
            'year' => 2025,
            'fiscal_year_id' => null,
            'closing_date' => '2025-12-31',
            'reference' => 'BOUCL-2025',
            'result_account_code' => '2900',
        ], $this->user);
        $this->assertSame(1, JournalEntry::query()->where('type', JournalEntry::TYPE_YEAR_END_CLOSING)->count());
    }

    public function test_the_profit_and_loss_of_a_closed_year_shows_what_it_traded(): void
    {
        $pnl = app(ReportingService::class)->profitAndLoss($this->organization->id, '2025-01-01', '2025-12-31', '2025-01-01', '2025-12-31');

        $this->assertSame(0, bccomp((string) $pnl['total_revenue'], '5000.00', 2));
        $this->assertSame(0, bccomp((string) $pnl['total_expenses'], '1200.00', 2));
        $this->assertSame(0, bccomp((string) $pnl['net_profit'], '3800.00', 2));
        $this->assertSame(0, bccomp((string) $pnl['comparison']['net_profit'], '3800.00', 2));
    }

    public function test_the_balance_sheet_keeps_the_closing_and_counts_the_result_once(): void
    {
        $sheet = app(ReportingService::class)->balanceSheet($this->organization->id, '2025-12-31');

        $assets = (string) $sheet['assets']['total'];
        $liabilitiesAndEquity = Money::add((string) $sheet['liabilities']['total'], (string) $sheet['equity']['total']);
        $this->assertSame(0, bccomp($assets, '4800.00', 2));
        $this->assertSame(0, bccomp($liabilitiesAndEquity, '4800.00', 2), 'assets = liabilities + equity');
    }

    public function test_the_tax_return_figures_of_a_closed_year_show_what_it_traded(): void
    {
        config()->set('features.tax_declaration', true);

        $this->actAsOrg()
            ->post(route('accounting.tax-declarations.store'), ['fiscal_year' => 2025, 'canton' => 'VD'])
            ->assertRedirect();

        $data = TaxDeclaration::query()->where('fiscal_year', 2025)->sole()->data;
        $this->assertEqualsWithDelta(5000.0, $data['revenue'], 0.001);
        $this->assertEqualsWithDelta(1200.0, $data['expenses'], 0.001);
        $this->assertEqualsWithDelta(3800.0, $data['profit'], 0.001);
        // Position figures keep the closing: equity holds capital and result.
        $this->assertEqualsWithDelta(4800.0, $data['equity'], 0.001);
    }

    private function account(string $code): Account
    {
        return Account::query()->where('organization_id', $this->organization->id)->where('code', $code)->firstOrFail();
    }
}

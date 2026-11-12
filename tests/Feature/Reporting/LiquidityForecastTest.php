<?php

namespace Tests\Feature\Reporting;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Expenses\Models\RecurringExpense;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\DeductionRateSet;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Reporting\Services\LiquidityForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\CreatesAccountingFixtures;
use Tests\Traits\WithAuthenticatedOrganization;

class LiquidityForecastTest extends TestCase
{
    use CreatesAccountingFixtures, RefreshDatabase, WithAuthenticatedOrganization;

    private LiquidityForecastService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->service = app(LiquidityForecastService::class);
        Carbon::setTestNow('2026-07-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────
    //  Available funds
    // ──────────────────────────────────────────────────────────────

    public function test_available_funds_net_liabilities_off_the_bank_balance(): void
    {
        $this->fundBank('50000.00');
        $this->owe('2200', '3000.00');

        $forecast = $this->service->forecast($this->org->id);

        $this->assertSame('50000.00', $forecast['liquidAssets']);
        $this->assertSame('3000.00', $forecast['shortTermLiabilities']);
        $this->assertSame('47000.00', $forecast['availableFunds']);
    }

    public function test_a_payroll_clearing_balance_reduces_liquid_assets(): void
    {
        $this->fundBank('50000.00');

        // Wages booked but not yet paid out sit as a credit on the 109x
        // clearing account. That money is spoken for and must not count as
        // available cash.
        $this->postJournalEntry('2026-03-31', [
            $this->journalLine($this->account('5600'), '8000.00', '0.00'),
            $this->journalLine($this->account('1091'), '0.00', '8000.00'),
        ], 'WAGE-1');

        $forecast = $this->service->forecast($this->org->id);

        $this->assertSame('42000.00', $forecast['liquidAssets']);
    }

    public function test_long_term_debt_does_not_shorten_the_runway(): void
    {
        $this->fundBank('50000.00');
        $this->owe('2400', '100000.00');

        $forecast = $this->service->forecast($this->org->id);

        $this->assertSame('0.00', $forecast['shortTermLiabilities']);
        $this->assertSame('50000.00', $forecast['availableFunds']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Burn components
    // ──────────────────────────────────────────────────────────────

    public function test_payroll_uses_master_data_including_employer_charges(): void
    {
        $this->fundBank('50000.00');
        $this->employee('5000.00');

        $forecast = $this->service->forecast($this->org->id);

        $payroll = $forecast['burn']['payroll'];
        $this->assertSame('master_data', $payroll['source']);
        $this->assertSame(1, $payroll['headcount']);

        // Gross plus the employer's own contributions — never just the gross.
        $this->assertGreaterThan(5000.0, (float) $payroll['amount']);
    }

    public function test_payroll_uses_the_employees_deduction_rate_set(): void
    {
        $this->fundBank('50000.00');
        $set = DeductionRateSet::create(['organization_id' => $this->org->id, 'code' => 'STD', 'title' => 'Standard', 'date_from' => '2020-01-01', 'date_to' => null]);
        DeductionRate::create(['organization_id' => $this->org->id, 'deduction_rate_set_id' => $set->id, 'name' => 'AVS employer', 'code' => 'avs_employer', 'rate' => '10.0000', 'type' => 'employer', 'is_active' => true]);
        $employee = $this->employee('5000.00');
        $employee->update(['deduction_rate_code' => 'STD']);
        // Same organisation, no code: payroll would refuse the slip; the forecast still counts the gross.
        $this->employee('1000.00');

        $payroll = $this->service->forecast($this->org->id)['burn']['payroll'];

        $this->assertSame(2, $payroll['headcount']);
        // 5000 + 10 % from the set; 1000 + 13.4 % employer defaults.
        $this->assertSame('6634.00', $payroll['amount']);
    }

    public function test_thirteenth_salary_is_spread_across_the_year(): void
    {
        $this->fundBank('50000.00');
        $this->employee('6000.00', thirteenth: true);

        $payroll = $this->service->forecast($this->org->id)['burn']['payroll'];

        // 6000 * 13/12 = 6500 gross, before employer charges.
        $this->assertGreaterThanOrEqual(6500.0, (float) $payroll['amount']);
    }

    public function test_an_employee_who_has_left_is_not_counted(): void
    {
        $this->fundBank('50000.00');
        $this->employee('5000.00', exitDate: '2026-05-31');

        $payroll = $this->service->forecast($this->org->id)['burn']['payroll'];

        $this->assertSame(0, $payroll['headcount']);
    }

    public function test_recurring_charges_are_normalised_to_a_month(): void
    {
        $this->fundBank('50000.00');
        $this->recurring('120.00', 'yearly');
        $this->recurring('300.00', 'quarterly');
        $this->recurring('50.00', 'monthly');

        $recurring = $this->service->forecast($this->org->id)['burn']['recurring'];

        // 10.00 + 100.00 + 50.00
        $this->assertSame('160.00', $recurring['amount']);
        $this->assertSame('master_data', $recurring['source']);
        $this->assertSame(3, $recurring['count']);
    }

    public function test_an_expired_recurring_charge_is_not_counted(): void
    {
        $this->fundBank('50000.00');
        $this->recurring('50.00', 'monthly', endDate: '2026-06-30');

        $recurring = $this->service->forecast($this->org->id)['burn']['recurring'];

        $this->assertSame('0.00', $recurring['amount']);
        $this->assertSame('none', $recurring['source']);
    }

    public function test_payroll_falls_back_to_the_ledger_when_no_employees_exist(): void
    {
        $this->fundBank('50000.00');

        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $month) {
            $this->spend('5600', $month.'-10', '4000.00');
        }

        $payroll = $this->service->forecast($this->org->id)['burn']['payroll'];

        $this->assertSame('ledger', $payroll['source']);
        $this->assertSame('4000.00', $payroll['amount']);
    }

    /**
     * Wages posted in batches leave most months of the window empty. Averaging
     * spreads them back over the window; taking a median would report a
     * payroll cost of nearly nothing for a business that is paying salaries.
     */
    public function test_batched_wage_postings_are_averaged_not_medianed(): void
    {
        $this->fundBank('50000.00');

        // Two months carry the whole half-year: 24000 over six months.
        $this->spend('5600', '2026-05-10', '12000.00');
        $this->spend('5600', '2026-06-10', '12000.00');

        $payroll = $this->service->forecast($this->org->id)['burn']['payroll'];

        $this->assertSame('4000.00', $payroll['amount']);
    }

    /**
     * The counterpart: an annual premium must not be spread over the window as
     * though it recurred monthly, so everything outside payroll stays on the
     * median.
     */
    public function test_other_expenses_stay_on_the_median(): void
    {
        $this->fundBank('50000.00');

        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05'] as $month) {
            $this->spend('6500', $month.'-10', '100.00');
        }
        $this->spend('6500', '2026-06-10', '12000.00');

        $this->assertSame('100.00', $this->service->forecast($this->org->id)['burn']['other']['amount']);
    }

    public function test_estimated_payroll_is_not_counted_twice_from_the_ledger(): void
    {
        $this->fundBank('50000.00');

        // No employees on file, so payroll is estimated from the 5xx accounts.
        // Those same bookings must not surface again under "other".
        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $month) {
            $this->spend('5600', $month.'-10', '4000.00');
            $this->spend('6500', $month.'-11', '200.00');
        }

        $burn = $this->service->forecast($this->org->id)['burn'];

        $this->assertSame('ledger', $burn['payroll']['source']);
        $this->assertSame('4000.00', $burn['payroll']['amount']);
        $this->assertSame('200.00', $burn['other']['amount']);
        $this->assertSame('4200.00', $burn['total']);
    }

    public function test_master_data_payroll_is_not_counted_twice_from_the_ledger(): void
    {
        $this->fundBank('50000.00');
        $this->employee('5000.00');

        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $month) {
            $this->spend('5600', $month.'-10', '4000.00');
            $this->spend('6500', $month.'-11', '200.00');
        }

        $burn = $this->service->forecast($this->org->id)['burn'];

        // The 5600 wage bookings are already represented by the employee, so
        // only the 6500 administration cost may show up as "other".
        $this->assertSame('200.00', $burn['other']['amount']);
    }

    public function test_a_recurring_charges_own_account_is_excluded_from_the_ledger_estimate(): void
    {
        $this->fundBank('50000.00');
        $this->recurring('195.00', 'monthly', accountCode: '6031');

        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $month) {
            $this->spend('6031', $month.'-10', '195.00');
            $this->spend('6500', $month.'-11', '80.00');
        }

        $burn = $this->service->forecast($this->org->id)['burn'];

        $this->assertSame('195.00', $burn['recurring']['amount']);
        $this->assertSame('80.00', $burn['other']['amount'], 'The subscription must not be counted from both sources.');
    }

    public function test_the_run_rate_uses_the_median_not_the_mean(): void
    {
        $this->fundBank('50000.00');

        // Five ordinary months and one annual premium. A mean would claim the
        // business spends ~2100 a month; it spends 100.
        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05'] as $month) {
            $this->spend('6500', $month.'-10', '100.00');
        }
        $this->spend('6500', '2026-06-10', '12000.00');

        $other = $this->service->forecast($this->org->id)['burn']['other'];

        $this->assertSame('100.00', $other['amount']);
    }

    public function test_entries_dated_ahead_do_not_move_the_window_past_the_last_complete_month(): void
    {
        $this->fundBank('50000.00');
        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $month) {
            $this->spend('6500', $month.'-10', '100.00');
        }
        // Booked ahead (today is 2026-07-15): a window ending in October would hold empty months.
        $this->spend('6500', '2026-10-01', '100.00');

        $forecast = $this->service->forecast($this->org->id);

        $this->assertSame('2026-06-30', $forecast['window']['to']);
        $this->assertSame('100.00', $forecast['burn']['other']['amount']);
    }

    public function test_an_employee_who_has_not_started_yet_is_not_counted(): void
    {
        $this->fundBank('50000.00');
        $this->employee('5000.00')->update(['entry_date' => '2026-09-01']);

        $payroll = $this->service->forecast($this->org->id)['burn']['payroll'];

        $this->assertSame(0, $payroll['headcount']);
        $this->assertSame('ledger', $payroll['source']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Projection
    // ──────────────────────────────────────────────────────────────

    public function test_runway_counts_the_months_the_money_lasts(): void
    {
        $this->fundBank('10000.00');
        $this->recurring('2000.00', 'monthly');

        $scenario = $this->service->forecast($this->org->id)['scenarios']['withoutRevenue'];

        $this->assertSame(5, $scenario['monthsRemaining']);
        $this->assertSame('2026-12-31', $scenario['depletionDate']);
        $this->assertFalse($scenario['sustainable']);
    }

    public function test_runway_beyond_the_horizon_reports_no_depletion(): void
    {
        $this->fundBank('500000.00');
        $this->recurring('100.00', 'monthly');

        $scenario = $this->service->forecast($this->org->id)['scenarios']['withoutRevenue'];

        $this->assertNull($scenario['monthsRemaining']);
        $this->assertNull($scenario['depletionDate']);
        $this->assertCount(12, $scenario['series']);
    }

    public function test_a_profitable_business_is_sustainable_in_the_revenue_scenario(): void
    {
        $this->fundBank('10000.00');
        $this->recurring('1000.00', 'monthly');

        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $month) {
            $this->earn($month.'-20', '5000.00');
        }

        $forecast = $this->service->forecast($this->org->id);

        $this->assertFalse($forecast['scenarios']['withoutRevenue']['sustainable']);
        $this->assertTrue($forecast['scenarios']['withRevenue']['sustainable']);
        $this->assertNull($forecast['scenarios']['withRevenue']['monthsRemaining']);
    }

    public function test_forecast_is_scoped_to_the_organization(): void
    {
        $this->fundBank('50000.00');

        $forecast = $this->service->forecast((string) Str::uuid());

        $this->assertSame('0.00', $forecast['availableFunds']);
        $this->assertSame('0.00', $forecast['burn']['total']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Fixtures
    // ──────────────────────────────────────────────────────────────

    private function fundBank(string $amount): void
    {
        $this->postJournalEntry('2026-01-02', [
            $this->journalLine($this->account('1020'), $amount, '0.00'),
            $this->journalLine($this->account('3000'), '0.00', $amount),
        ], 'FUND-1');
    }

    private function owe(string $code, string $amount): void
    {
        $this->postJournalEntry('2026-01-03', [
            $this->journalLine($this->account('6500'), $amount, '0.00'),
            $this->journalLine($this->account($code), '0.00', $amount),
        ], 'OWE-'.$code);
    }

    private function spend(string $code, string $date, string $amount): void
    {
        $this->postJournalEntry($date, [
            $this->journalLine($this->account($code), $amount, '0.00'),
            $this->journalLine($this->account('1020'), '0.00', $amount),
        ], 'SPEND-'.$code.'-'.$date);
    }

    private function earn(string $date, string $amount): void
    {
        $this->postJournalEntry($date, [
            $this->journalLine($this->account('1020'), $amount, '0.00'),
            $this->journalLine($this->account('3000'), '0.00', $amount),
        ], 'EARN-'.$date);
    }

    private function employee(string $gross, bool $thirteenth = false, ?string $exitDate = null): Employee
    {
        return Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Test',
            'last_name' => 'Employee '.Str::random(4),
            'gross_salary' => $gross,
            'is_active' => true,
            'has_thirteenth_salary' => $thirteenth,
            'exit_date' => $exitDate,
            'entry_date' => '2026-01-01',
        ]);
    }

    private function recurring(string $amount, string $frequency, ?string $endDate = null, ?string $accountCode = null): RecurringExpense
    {
        return RecurringExpense::create([
            'organization_id' => $this->org->id,
            'category' => 'Software',
            'description' => 'Subscription',
            'amount' => $amount,
            'vat_amount' => '0.00',
            'currency' => 'CHF',
            'frequency' => $frequency,
            'next_due_date' => '2026-08-01',
            'end_date' => $endDate,
            'expense_account_code' => $accountCode,
            'is_active' => true,
        ]);
    }

    private function account(string $code): Account
    {
        $definitions = [
            '1020' => ['Bank', AccountType::Asset],
            '1091' => ['Payroll clearing', AccountType::Asset],
            '2200' => ['VAT payable', AccountType::Liability],
            '2400' => ['Long-term loan', AccountType::Liability],
            '3000' => ['Sales', AccountType::Revenue],
            '5600' => ['Wages', AccountType::Expense],
            '6031' => ['Notion', AccountType::Expense],
            '6500' => ['Administration', AccountType::Expense],
        ];

        [$name, $type] = $definitions[$code];

        return Account::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => $code],
            ['name' => $name, 'type' => $type->value],
        );
    }
}

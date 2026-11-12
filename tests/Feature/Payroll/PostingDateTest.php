<?php

namespace Tests\Feature\Payroll;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Payroll\Actions\PostPayrollAction;
use App\Domains\Payroll\Actions\UnpostPayrollAction;
use App\Domains\Payroll\Exceptions\PostingDateOutsidePeriodException;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * A salary slip is posted on its payday: the slip's own posting date, else
 * the organization's payday in the slip's month, else the month's last day.
 * The date is bounded to the payroll month.
 *
 * Idea from Ideall-Engineering/Gaeld 6abd6364.
 */
class PostingDateTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        foreach ([
            ['code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value],
            ['code' => '5000', 'name' => 'Salaries', 'type' => AccountType::Expense->value],
            ['code' => '5700', 'name' => 'Social Charges', 'type' => AccountType::Expense->value],
            ['code' => '2270', 'name' => 'AVS Payable', 'type' => AccountType::Liability->value],
            ['code' => '2271', 'name' => 'AC Payable', 'type' => AccountType::Liability->value],
            ['code' => '2272', 'name' => 'LPP Payable', 'type' => AccountType::Liability->value],
        ] as $account) {
            Account::create(array_merge($account, ['organization_id' => $this->org->id]));
        }

        $this->employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'entry_date' => '2025-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
        ]);
    }

    private function draft(int $month = 3, ?string $postingDate = null): SalarySlip
    {
        $slip = app(PayrollCalculator::class)->calculate($this->employee, $month, 2026);
        $slip->posting_date = $postingDate;
        $slip->save();

        return $slip;
    }

    private function entryDate(SalarySlip $slip): string
    {
        return $slip->fresh()->journalEntry->date->toDateString();
    }

    public function test_without_a_date_or_payday_the_entry_is_dated_the_last_day_of_the_month(): void
    {
        $slip = app(PostPayrollAction::class)->execute($this->draft(2));

        $this->assertSame('2026-02-28', $this->entryDate($slip));
    }

    public function test_the_organization_payday_dates_the_entry(): void
    {
        $this->org->update(['payroll_payday' => 25]);

        $slip = app(PostPayrollAction::class)->execute($this->draft(3));

        $this->assertSame('2026-03-25', $this->entryDate($slip));
    }

    public function test_a_payday_past_the_end_of_a_short_month_is_its_last_day(): void
    {
        $this->org->update(['payroll_payday' => 31]);

        $slip = app(PostPayrollAction::class)->execute($this->draft(2));

        $this->assertSame('2026-02-28', $this->entryDate($slip));
    }

    public function test_the_slip_date_wins_over_the_payday_and_is_kept_by_unpost(): void
    {
        $this->org->update(['payroll_payday' => 25]);

        $slip = app(PostPayrollAction::class)->execute($this->draft(3, '2026-03-20'));
        $this->assertSame('2026-03-20', $this->entryDate($slip));

        // Re-posting then uses the same date (re-posting itself is covered elsewhere).
        $slip = app(UnpostPayrollAction::class)->execute($slip);
        $this->assertSame('2026-03-20', $slip->posting_date->toDateString());
        $this->assertSame('2026-03-20', PostPayrollAction::postingDate($slip, $this->org->fresh()));
    }

    public function test_posting_refuses_a_slip_dated_outside_its_month(): void
    {
        $slip = $this->draft(3);
        $slip->forceFill(['posting_date' => '2026-04-01'])->save();

        try {
            app(PostPayrollAction::class)->execute($slip);
            $this->fail('A slip dated outside its month was posted.');
        } catch (PostingDateOutsidePeriodException) {
            $this->assertNull($slip->fresh()->journal_entry_id);
        }
    }

    public function test_the_run_stores_its_posting_date_on_every_slip(): void
    {
        $this->actAsOrg()->post(route('payroll.run.generate'), [
            'month' => 3,
            'year' => 2026,
            'post' => true,
            'posting_date' => '2026-03-26',
        ])->assertRedirect();

        $slip = SalarySlip::where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('2026-03-26', $slip->posting_date->toDateString());
        $this->assertSame('2026-03-26', $this->entryDate($slip));
    }

    public function test_the_run_rejects_a_posting_date_outside_the_month(): void
    {
        $this->actAsOrg()->postJson(route('payroll.run.generate'), [
            'month' => 3,
            'year' => 2026,
            'posting_date' => '2026-04-02',
        ])->assertUnprocessable()->assertJsonValidationErrors('posting_date');

        $this->assertSame(0, SalarySlip::count());
    }

    public function test_posting_a_slip_can_set_its_date(): void
    {
        $slip = $this->draft(3);

        $this->actAsOrg()->postJson("/payroll/salary-slips/{$slip->id}/post", ['posting_date' => '2026-03-27'])
            ->assertOk();
        $this->assertSame('2026-03-27', $this->entryDate($slip));

        $other = $this->draft(4);
        $this->actAsOrg()->postJson("/payroll/salary-slips/{$other->id}/post", ['posting_date' => '2026-03-31'])
            ->assertUnprocessable()->assertJsonValidationErrors('posting_date');
        $this->assertNull($other->fresh()->posted_at);
    }

    public function test_a_malformed_posting_date_is_a_validation_error_on_every_endpoint(): void
    {
        $this->actAsOrg()->postJson(route('payroll.run.generate'), ['month' => 3, 'year' => 2026, 'posting_date' => 'abc'])
            ->assertUnprocessable()->assertJsonValidationErrors('posting_date');

        $this->actAsOrg()->postJson(route('payroll.salarySlips.generate'), ['employee_id' => $this->employee->id, 'month' => 3, 'year' => 2026, 'posting_date' => 'abc'])
            ->assertUnprocessable()->assertJsonValidationErrors('posting_date');

        $slip = $this->draft(4);
        $this->actAsOrg()->postJson("/payroll/salary-slips/{$slip->id}/post", ['posting_date' => 'abc'])
            ->assertUnprocessable()->assertJsonValidationErrors('posting_date');

        $this->assertNull($slip->fresh()->posted_at);
        $this->assertSame(1, SalarySlip::count());
    }

    public function test_a_refused_post_leaves_the_draft_date_unchanged(): void
    {
        FiscalYear::factory()->for($this->org)->create([
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Closed,
        ]);
        $slip = $this->draft(3, '2026-03-20');

        $this->actAsOrg()->postJson("/payroll/salary-slips/{$slip->id}/post", ['posting_date' => '2026-03-27'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'fiscal_year_closed');

        $fresh = $slip->fresh();
        $this->assertNull($fresh->posted_at);
        $this->assertSame('2026-03-20', $fresh->posting_date->toDateString());
    }

    public function test_the_slip_page_shows_the_date_it_would_post_on(): void
    {
        $this->org->update(['payroll_payday' => 25]);
        $slip = $this->draft(3);

        $this->actAsOrg()->get("/payroll/salary-slips/{$slip->id}")
            ->assertInertia(fn ($page) => $page->where('postingDate', '2026-03-25'));
    }

    public function test_the_payday_is_an_organization_setting(): void
    {
        $this->actAsOrg()->put(route('settings.general'), [
            'name' => $this->org->name,
            'currency' => 'CHF',
            'locale' => 'fr',
            'payroll_payday' => 25,
        ])->assertSessionHasNoErrors();
        $this->assertSame(25, $this->org->fresh()->payroll_payday);

        // A save without the field leaves it alone.
        $this->actAsOrg()->put(route('settings.general'), [
            'name' => $this->org->name,
            'currency' => 'CHF',
            'locale' => 'fr',
        ])->assertSessionHasNoErrors();
        $this->assertSame(25, $this->org->fresh()->payroll_payday);

        $this->actAsOrg()->put(route('settings.general'), [
            'name' => $this->org->name,
            'currency' => 'CHF',
            'locale' => 'fr',
            'payroll_payday' => 32,
        ])->assertSessionHasErrors('payroll_payday');
    }
}

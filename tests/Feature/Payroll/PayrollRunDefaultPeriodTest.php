<?php

namespace Tests\Feature\Payroll;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The payroll run proposes the month after the latest salary slip, else the current month.
 */
class PayrollRunDefaultPeriodTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        Carbon::setTestNow('2026-11-27 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_without_slips_the_current_month_is_proposed(): void
    {
        $this->assertDefaultPeriod(11, 2026);
    }

    public function test_the_month_after_the_latest_slip_is_proposed(): void
    {
        $this->slip(2026, 9);
        $this->slip(2026, 10);
        $this->slip(2025, 12);

        $this->assertDefaultPeriod(11, 2026);
    }

    public function test_after_december_the_next_january_is_proposed_and_offered(): void
    {
        $this->slip(2026, 12);

        $this->assertDefaultPeriod(1, 2027)
            ->assertInertia(fn ($page) => $page->where('fiscalYears.0', 2027));
    }

    public function test_slips_of_another_organisation_or_far_from_now_are_ignored(): void
    {
        $other = Organization::factory()->create();
        SalarySlip::withoutGlobalScopes()->create([
            'organization_id' => $other->id,
            'employee_id' => Employee::factory()->create(['organization_id' => $other->id])->id,
            'period_month' => 5, 'period_year' => 2026,
            'gross_salary' => '6000.00', 'net_salary' => '5000.00', 'deductions' => [],
        ]);
        $this->slip(2023, 4);
        $this->assertDefaultPeriod(11, 2026);

        $this->slip(2099, 1);
        $this->assertDefaultPeriod(11, 2026)
            ->assertInertia(fn ($page) => $page->where('fiscalYears.0', 2026));
    }

    public function test_a_mistyped_future_slip_does_not_hide_the_latest_real_one(): void
    {
        $this->slip(2026, 11);
        $this->slip(2099, 1);

        $this->assertDefaultPeriod(12, 2026);
    }

    private function assertDefaultPeriod(int $month, int $year): TestResponse
    {
        return $this->actingAs($this->user)->get('/payroll/run')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Payroll/Run')
                ->where('defaultPeriod', ['month' => $month, 'year' => $year]));
    }

    private function slip(int $year, int $month): void
    {
        SalarySlip::create([
            'organization_id' => $this->org->id,
            'employee_id' => Employee::factory()->create(['organization_id' => $this->org->id])->id,
            'period_month' => $month,
            'period_year' => $year,
            'gross_salary' => '6000.00',
            'net_salary' => '5000.00',
            'deductions' => [],
        ]);
    }
}

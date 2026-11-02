<?php

namespace Tests\Feature\Payroll;

use App\Domains\Payroll\Actions\GeneratePayrollRunAction;
use App\Domains\Payroll\Models\DeductionRateSet;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Proves that payroll calculation resolves the deduction rate set
 * automatically from the employee's code and the payroll period's date,
 * picks the right one when the same code has a different set (different
 * rates) for different years, and never falls back silently to the built-in
 * defaults once the organization has a set.
 */
class DeductionRateSetResolutionTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $this->employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Anna',
            'last_name' => 'Klein',
            'entry_date' => '2020-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function it_uses_the_set_matching_the_employees_code_and_the_payroll_period(): void
    {
        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'SPECIAL',
            'title' => 'Classe spéciale 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '10.0000',
            'type' => 'employee',
        ]);
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'AVS',
            'code' => 'avs_employer',
            'rate' => '10.0000',
            'type' => 'employer',
        ]);

        $this->employee->update(['deduction_rate_code' => 'SPECIAL']);

        $slip = app(PayrollCalculator::class)->calculate($this->employee->fresh(), 3, 2026);

        // 10% of 6000.00, not the built-in default (5.3%).
        $this->assertSame('600.00', $slip->deductions['avs_employee']);
    }

    #[Test]
    public function it_picks_the_set_whose_date_range_covers_the_period_when_the_same_code_has_several(): void
    {
        $set2025 = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Barème 2025',
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]);
        $set2025->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.0000',
            'type' => 'employee',
        ]);

        $set2026 = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Barème 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $set2026->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '8.0000',
            'type' => 'employee',
        ]);

        $this->employee->update(['deduction_rate_code' => 'STANDARD']);

        $slip2025 = app(PayrollCalculator::class)->calculate($this->employee->fresh(), 6, 2025);
        $slip2026 = app(PayrollCalculator::class)->calculate($this->employee->fresh(), 6, 2026);

        $this->assertSame('300.00', $slip2025->deductions['avs_employee']);
        $this->assertSame('480.00', $slip2026->deductions['avs_employee']);
    }

    #[Test]
    public function it_uses_the_built_in_defaults_only_when_the_organization_has_no_set(): void
    {
        $slip = app(PayrollCalculator::class)->calculate($this->employee->fresh(), 3, 2026);

        // Built-in default AVS rate is 5.3%.
        $this->assertSame('318.00', $slip->deductions['avs_employee']);
        $this->assertNull($slip->deduction_snapshot['set_id']);
        $this->assertSame('AVS/AI/APG (employee)', $slip->deduction_snapshot['lines']['avs_employee']['name']);
    }

    #[Test]
    public function it_refuses_to_calculate_an_employee_without_a_code_once_the_organization_has_a_set(): void
    {
        $this->set('STANDARD', '2026-01-01', null);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Anna Klein');

        app(PayrollCalculator::class)->calculate($this->employee->fresh(), 3, 2026);
    }

    #[Test]
    public function it_refuses_to_calculate_when_no_set_covers_the_period(): void
    {
        $this->set('SPECIAL', '2025-01-01', '2025-12-31');
        $this->employee->update(['deduction_rate_code' => 'SPECIAL']);

        // 2026 is outside the only set's range (2025): an error, not the defaults.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('SPECIAL');

        app(PayrollCalculator::class)->calculate($this->employee->fresh(), 3, 2026);
    }

    #[Test]
    public function it_refuses_to_calculate_with_a_set_without_active_lines(): void
    {
        $set = $this->set('SPECIAL', '2026-01-01', null);
        $set->rates()->update(['is_active' => false]);
        $this->employee->update(['deduction_rate_code' => 'SPECIAL']);

        $this->expectException(\DomainException::class);

        app(PayrollCalculator::class)->calculate($this->employee->fresh(), 3, 2026);
    }

    #[Test]
    public function the_error_is_translated(): void
    {
        $this->set('STANDARD', '2026-01-01', null);
        app()->setLocale('fr');

        try {
            app(PayrollCalculator::class)->calculate($this->employee->fresh(), 3, 2026);
            $this->fail('expected a DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Aucune classe de charges sociales', $e->getMessage());
        }
    }

    #[Test]
    public function an_open_ended_set_keeps_covering_the_next_years(): void
    {
        $this->set('STANDARD', '2026-01-01', null);
        $this->employee->update(['deduction_rate_code' => 'STANDARD']);

        $slip = app(PayrollCalculator::class)->calculate($this->employee->fresh(), 1, 2027);

        $this->assertSame('600.00', $slip->deductions['avs_employee']);
    }

    #[Test]
    public function a_payroll_run_creates_no_slip_when_one_employee_has_no_set(): void
    {
        $this->set('STANDARD', '2026-01-01', null);
        $this->employee->update(['deduction_rate_code' => 'STANDARD']);
        Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Ben',
            'last_name' => 'Ohnecode',
            'entry_date' => '2020-01-01',
            'gross_salary' => '5000.00',
            'is_active' => true,
        ]);

        try {
            app(GeneratePayrollRunAction::class)->execute($this->org->id, 3, 2026);
            $this->fail('expected a DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Ben Ohnecode', $e->getMessage());
        }

        $this->assertSame(0, SalarySlip::where('organization_id', $this->org->id)->count());
    }

    private function set(string $code, string $from, ?string $to): DeductionRateSet
    {
        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => $code,
            'title' => $code,
            'date_from' => $from,
            'date_to' => $to,
        ]);
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '10.0000',
            'type' => 'employee',
        ]);

        return $set;
    }
}

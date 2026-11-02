<?php

namespace Tests\Feature\Payroll;

use App\Domains\Payroll\Models\DeductionRateSet;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The hardening migration keeps organisations that had flat rates (grouped
 * into a dated STANDARD set by the previous migration) calculating with
 * them: their employees get the code, the latest set of each code stays
 * valid for later months, and the legacy employee lines get their
 * certificate box.
 */
class DeductionRateSetBackfillMigrationTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    #[Test]
    public function it_assigns_standard_opens_the_latest_set_and_sets_certificate_boxes(): void
    {
        $this->setUpOrganization();
        $migration = require database_path('migrations/2026_11_02_110000_harden_deduction_rate_sets.php');
        $migration->down();

        $older = DeductionRateSet::create(['organization_id' => $this->org->id, 'code' => 'STANDARD', 'title' => 'Standard 2025', 'date_from' => '2025-01-01', 'date_to' => '2025-12-31']);
        $latest = DeductionRateSet::create(['organization_id' => $this->org->id, 'code' => 'STANDARD', 'title' => 'Standard', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31']);
        foreach ([['avs_employee', 'employee'], ['lpp_employee', 'employee'], ['lpp_employer', 'employer'], ['ktg_employee', 'employee']] as [$code, $type]) {
            DB::table('deduction_rates')->insert([
                'organization_id' => $this->org->id, 'deduction_rate_set_id' => $latest->id, 'name' => $code,
                'code' => $code, 'rate' => '1.0000', 'type' => $type, 'is_active' => true,
            ]);
        }
        $withoutCode = Employee::create(['organization_id' => $this->org->id, 'first_name' => 'A', 'last_name' => 'B', 'entry_date' => '2020-01-01', 'gross_salary' => '5000.00', 'is_active' => true]);
        $withCode = Employee::create(['organization_id' => $this->org->id, 'first_name' => 'C', 'last_name' => 'D', 'entry_date' => '2020-01-01', 'gross_salary' => '5000.00', 'is_active' => true, 'deduction_rate_code' => 'CADRE']);

        SalarySlip::create([
            'organization_id' => $this->org->id, 'employee_id' => $withoutCode->id, 'period_month' => 5, 'period_year' => 2024,
            'gross_salary' => '5000.00', 'net_salary' => '5000.00', 'deductions' => [],
        ]);

        $migration->up();

        $this->assertNull($latest->fresh()->date_to, 'the latest set of the code is open-ended');
        $this->assertSame('2025-12-31', $older->fresh()->date_to->toDateString());
        $this->assertSame('2024-05-01', $older->fresh()->date_from->toDateString(), 'the earliest set covers the earliest slip');
        $this->assertSame('2026-01-01', $latest->fresh()->date_from->toDateString());
        $this->assertSame('STANDARD', $withoutCode->fresh()->deduction_rate_code);
        $this->assertSame('CADRE', $withCode->fresh()->deduction_rate_code, 'an existing code is kept');
        $boxes = DB::table('deduction_rates')->pluck('certificate_box', 'code')->all();
        $this->assertSame('9', $boxes['avs_employee']);
        $this->assertSame('10', $boxes['lpp_employee']);
        $this->assertNull($boxes['lpp_employer'], 'employer lines are not on the certificate');
        $this->assertNull($boxes['ktg_employee'], 'a custom code gets no box by guess');
    }
}

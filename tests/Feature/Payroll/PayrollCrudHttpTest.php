<?php

namespace Tests\Feature\Payroll;

use App\Domains\Payroll\Models\DeductionRateSet;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class PayrollCrudHttpTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_employee_index_renders(): void
    {
        $this->actingAs($this->user)
            ->get('/payroll/employees')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Payroll/Employees/Index')
                ->where('payrollWritable', true));
    }

    public function test_payroll_run_hides_source_tax_in_ce(): void
    {
        $this->actingAs($this->user)
            ->get('/payroll/run')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Payroll/Run')
                ->where('withholdingTaxEnabled', false));
    }

    public function test_viewer_can_view_employees_without_payroll_write_controls(): void
    {
        $viewer = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        $this->assignOrganizationRole($viewer, $this->organization, 'viewer');

        $this->actingAs($viewer)
            ->withSession(['current_organization_id' => $this->organization->id])
            ->get('/payroll/employees')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Payroll/Employees/Index')
                ->where('payrollWritable', false));
    }

    public function test_viewer_cannot_open_employee_creation_page(): void
    {
        $viewer = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        $this->assignOrganizationRole($viewer, $this->organization, 'viewer');

        $this->actingAs($viewer)
            ->withSession(['current_organization_id' => $this->organization->id])
            ->get('/payroll/employees/create')
            ->assertForbidden();
    }

    public function test_employee_create_page_renders(): void
    {
        $this->actingAs($this->user)
            ->get('/payroll/employees/create')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Payroll/Employees/Create')
                ->where('withholdingTaxEnabled', false));
    }

    public function test_employee_store_creates_record(): void
    {
        $this->actingAs($this->user)
            ->post('/payroll/employees', [
                'first_name' => 'Max',
                'last_name' => 'Muster',
                'email' => 'max@example.ch',
                'ahv_number' => '756.1234.5678.90',
                'entry_date' => '2026-01-01',
                'gross_salary' => '6500.00',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'organization_id' => $this->org->id,
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'entry_date' => '2026-01-01',
        ]);
    }

    public function test_employee_store_validates_required_fields(): void
    {
        $this->actingAs($this->user)
            ->post('/payroll/employees', [])
            ->assertSessionHasErrors(['first_name', 'last_name', 'entry_date', 'gross_salary']);
    }

    public function test_employee_show_loads_salary_slips(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Anna',
            'last_name' => 'Klein',
            'entry_date' => '2026-01-01',
            'gross_salary' => '7000.00',
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->get("/payroll/employees/{$employee->id}")
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Payroll/Employees/Show')
                ->has('employee')
                ->where('employee.entry_date', $employee->entry_date->toDateString())
            );
    }

    public function test_employee_show_exposes_ahv_number_to_authorized_viewer(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Anna',
            'last_name' => 'Klein',
            'entry_date' => '2026-01-01',
            'gross_salary' => '7000.00',
            'is_active' => true,
            'ahv_number' => '756.9217.0769.85',
        ]);

        $this->actingAs($this->user)
            ->get("/payroll/employees/{$employee->id}")
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Payroll/Employees/Show')
                ->where('employee.ahv_number', '756.9217.0769.85')
            );
    }

    public function test_employee_edit_exposes_ahv_number_to_authorized_editor(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Anna',
            'last_name' => 'Klein',
            'entry_date' => '2026-01-01',
            'gross_salary' => '7000.00',
            'is_active' => true,
            'ahv_number' => '756.9217.0769.85',
        ]);

        $this->actingAs($this->user)
            ->get("/payroll/employees/{$employee->id}/edit")
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Payroll/Employees/Edit')
                ->where('employee.ahv_number', '756.9217.0769.85')
            );
    }

    public function test_employee_show_orders_salary_slips_by_period_descending(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Anna',
            'last_name' => 'Klein',
            'entry_date' => '2026-01-01',
            'gross_salary' => '7000.00',
            'is_active' => true,
        ]);

        foreach ([
            ['year' => 2025, 'month' => 11],
            ['year' => 2026, 'month' => 2],
            ['year' => 2025, 'month' => 12],
            ['year' => 2026, 'month' => 1],
        ] as $period) {
            SalarySlip::create([
                'organization_id' => $this->org->id,
                'employee_id' => $employee->id,
                'period_month' => $period['month'],
                'period_year' => $period['year'],
                'gross_salary' => '7000.00',
                'net_salary' => '6000.00',
                'deductions' => [],
            ]);
        }

        $this->actingAs($this->user)
            ->get("/payroll/employees/{$employee->id}")
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Payroll/Employees/Show')
                ->where('salarySlips.0.period_year', 2026)
                ->where('salarySlips.0.period_month', 2)
                ->where('salarySlips.1.period_year', 2026)
                ->where('salarySlips.1.period_month', 1)
                ->where('salarySlips.2.period_year', 2025)
                ->where('salarySlips.2.period_month', 12)
                ->where('salarySlips.3.period_year', 2025)
                ->where('salarySlips.3.period_month', 11)
            );
    }

    public function test_employee_update_changes_fields(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Old',
            'last_name' => 'Name',
            'entry_date' => '2026-01-01',
            'gross_salary' => '5000.00',
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->put("/payroll/employees/{$employee->id}", [
                'first_name' => 'New',
                'last_name' => 'Name',
                'entry_date' => '2026-01-01',
                'gross_salary' => '5500.00',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'first_name' => 'New',
            'gross_salary' => '5500.00',
        ]);
    }

    public function test_employee_update_saves_the_deduction_rate_code(): void
    {
        DeductionRateSet::create(['organization_id' => $this->org->id, 'code' => 'STANDARD', 'title' => 'Standard', 'date_from' => '2026-01-01', 'date_to' => null]);
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Old',
            'last_name' => 'Name',
            'entry_date' => '2026-01-01',
            'gross_salary' => '5000.00',
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->put("/payroll/employees/{$employee->id}", [
                'first_name' => 'Old',
                'last_name' => 'Name',
                'entry_date' => '2026-01-01',
                'gross_salary' => '5000.00',
                'deduction_rate_code' => 'STANDARD',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'deduction_rate_code' => 'STANDARD',
        ]);
    }

    public function test_the_deduction_rate_code_must_name_an_existing_set_and_is_required_once_there_is_one(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id, 'first_name' => 'Old', 'last_name' => 'Name',
            'entry_date' => '2026-01-01', 'gross_salary' => '5000.00', 'is_active' => true,
        ]);
        $put = fn (?string $code) => $this->actingAs($this->user)->put("/payroll/employees/{$employee->id}", [
            'first_name' => 'Old', 'last_name' => 'Name', 'entry_date' => '2026-01-01', 'gross_salary' => '5000.00',
            'deduction_rate_code' => $code,
        ]);

        // No set yet: empty is fine (built-in defaults), an unknown code is not.
        $put(null)->assertSessionHasNoErrors();
        $put('STANDRAD')->assertSessionHasErrors('deduction_rate_code');

        DeductionRateSet::create(['organization_id' => $this->org->id, 'code' => 'STANDARD', 'title' => 'Standard', 'date_from' => '2026-01-01', 'date_to' => null]);

        $put(null)->assertSessionHasErrors('deduction_rate_code');
        $put('STANDRAD')->assertSessionHasErrors('deduction_rate_code');
        $put('STANDARD')->assertSessionHasNoErrors();
        $this->assertSame('STANDARD', $employee->fresh()->deduction_rate_code);
    }

    public function test_employee_update_without_thirteenth_salary_field_preserves_existing_setting(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Thirteen',
            'last_name' => 'Preserved',
            'entry_date' => '2026-01-01',
            'gross_salary' => '5000.00',
            'has_thirteenth_salary' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->put("/payroll/employees/{$employee->id}", [
                'first_name' => 'Updated',
                'last_name' => 'Preserved',
                'entry_date' => '2026-01-01',
                'gross_salary' => '5500.00',
            ])
            ->assertRedirect();

        $this->assertTrue($employee->fresh()->has_thirteenth_salary);
    }

    public function test_employee_destroy_deletes_record(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'To',
            'last_name' => 'Delete',
            'entry_date' => '2026-01-01',
            'gross_salary' => '4000.00',
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->delete("/payroll/employees/{$employee->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_unauthenticated_user_cannot_access_employees(): void
    {
        $this->get('/payroll/employees')->assertRedirect('/login');
    }

    public function test_salary_slips_index_renders(): void
    {
        $this->actingAs($this->user)
            ->get('/payroll/salary-slips')
            ->assertStatus(200);
    }
}

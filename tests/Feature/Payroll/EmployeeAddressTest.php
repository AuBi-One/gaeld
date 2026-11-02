<?php

namespace Tests\Feature\Payroll;

use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * An employee's postal address: edited on the employee form, snapshotted on
 * each slip with the other employee data, and printed under the name on the
 * standard salary-slip PDF.
 */
class EmployeeAddressTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return [
            'first_name' => 'Anna', 'last_name' => 'Klein', 'entry_date' => '2025-01-01', 'gross_salary' => '6000.00', 'is_active' => true,
            ...$extra,
        ];
    }

    public function test_the_address_is_stored_and_updated_from_the_employee_form(): void
    {
        $this->actAsOrg()->post('/payroll/employees', $this->payload([
            'address' => 'Rue du Lac 4', 'postal_code' => '1003', 'city' => 'Lausanne', 'country' => 'CH',
        ]))->assertSessionHasNoErrors();
        $employee = Employee::query()->sole();
        $this->assertSame(['Rue du Lac 4', '1003', 'Lausanne', 'CH'], [$employee->address, $employee->postal_code, $employee->city, $employee->country]);

        $this->actAsOrg()->put("/payroll/employees/{$employee->id}", $this->payload([
            'address' => 'Hauptstrasse 1', 'postal_code' => '79539', 'city' => 'Lörrach', 'country' => 'DE',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('DE', $employee->fresh()->country);

        $this->actAsOrg()->put("/payroll/employees/{$employee->id}", $this->payload(['country' => 'Suisse', 'postal_code' => str_repeat('1', 11)]))
            ->assertSessionHasErrors(['country', 'postal_code']);

        $this->actAsOrg()->get("/payroll/employees/{$employee->id}/edit")
            ->assertInertia(fn ($page) => $page->where('employee.city', 'Lörrach'));
    }

    public function test_the_slip_snapshots_the_address_and_prints_it_under_the_name(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id, ...$this->payload(),
            'address' => 'Hauptstrasse 1', 'postal_code' => '79539', 'city' => 'Lörrach', 'country' => 'DE',
        ]);
        $slip = app(PayrollCalculator::class)->calculate($employee->fresh(), 3, 2026);
        $slip->save();

        // A later move does not change the slip already calculated.
        $employee->update(['address' => 'Neue Gasse 2', 'city' => 'Basel', 'postal_code' => '4051', 'country' => 'CH']);
        $data = $slip->fresh()->employeeDocumentData();
        $this->assertSame(['Hauptstrasse 1', '79539 Lörrach', 'DE'], SalarySlip::addressLines($data));

        $html = view('exports.salary-slip', ['slip' => $slip->fresh(), 'organization' => $this->org])->render();
        $this->assertStringContainsString('Hauptstrasse 1', $html);
        $this->assertStringContainsString('79539 Lörrach', $html);
        $this->assertStringNotContainsString('Basel', $html);
        $this->actAsOrg()->get(route('payroll.salarySlips.pdf', $slip))->assertOk();
    }

    public function test_a_snapshot_taken_before_the_address_existed_reads_it_from_the_employee(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id, ...$this->payload(),
            'address' => 'Rue du Lac 4', 'postal_code' => '1003', 'city' => 'Lausanne', 'country' => 'CH',
        ]);
        $slip = app(PayrollCalculator::class)->calculate($employee->fresh(), 3, 2026);
        $slip->employee_snapshot = ['first_name' => 'Anna', 'last_name' => 'Klein', 'email' => null, 'ahv_number' => null, 'ahv_number_encrypted' => true, 'iban' => null, 'iban_encrypted' => true];
        $slip->save();

        // Switzerland is not printed; nothing else is lost.
        $this->assertSame(['Rue du Lac 4', '1003 Lausanne'], SalarySlip::addressLines($slip->fresh()->employeeDocumentData()));
    }

    public function test_an_employee_without_address_prints_only_the_name(): void
    {
        $employee = Employee::create(['organization_id' => $this->org->id, ...$this->payload()]);
        $slip = app(PayrollCalculator::class)->calculate($employee->fresh(), 3, 2026);
        $slip->save();

        $this->assertSame([], SalarySlip::addressLines($slip->fresh()->employeeDocumentData()));
    }
}

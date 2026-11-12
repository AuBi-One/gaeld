<?php

namespace Tests\Feature\Payroll;

use App\Domains\Payroll\Actions\GenerateSalaryCertificateAction;
use App\Domains\Payroll\Models\DeductionRateSet;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\PayrollCalculator;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The salary slip detail page must label every deduction actually present
 * on the slip (any code, not a fixed AVS/AC/AANP/LPP list), using the name
 * configured for it in Payroll > Charges sociales.
 */
class SalarySlipDeductionLabelsTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        // Required on a salary certificate.
        $this->org->update(['address' => 'Avenue de la Gare 2', 'postal_code' => '1003', 'city' => 'Lausanne']);
    }

    #[Test]
    public function it_sends_a_name_for_a_custom_deduction_code_not_in_the_built_in_defaults(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Anna',
            'last_name' => 'Klein',
            'entry_date' => '2025-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
            'deduction_rate_code' => 'SPECIAL',
        ]);

        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'SPECIAL',
            'title' => 'Spécial 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'APGM',
            'code' => 'apgm_employee',
            'rate' => '0.6100',
            'type' => 'employee',
        ]);

        $slip = app(PayrollCalculator::class)->calculate($employee->fresh(), 3, 2026);
        $slip->save();

        $response = $this->actAsOrg()->get("/payroll/salary-slips/{$slip->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Payroll/SalarySlips/Show')
            ->where('deductionRows.0.name', 'APGM')
            ->where('deductionRows.0.employee', $slip->deductions['apgm_employee']));
    }

    #[Test]
    public function deduction_rows_include_a_custom_charge_beyond_the_legacy_four(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Anna',
            'last_name' => 'Klein',
            'entry_date' => '2025-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
            'deduction_rate_code' => 'SPECIAL',
        ]);

        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'SPECIAL',
            'title' => 'Spécial 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'Alloc. familiales',
            'code' => 'family_allowance_employer',
            'rate' => '2.6200',
            'type' => 'employer',
        ]);

        $slip = app(PayrollCalculator::class)->calculate($employee->fresh(), 3, 2026);
        $slip->save();

        $rows = $slip->deductionRows();
        $familyRow = collect($rows)->firstWhere('name', 'Alloc. familiales');

        $this->assertNotNull($familyRow, 'expected a row for the employer-only custom charge');
        $this->assertSame('0.00', $familyRow['employee']);
        $this->assertSame($slip->deductions['family_allowance_employer'], $familyRow['employer']);
    }

    #[Test]
    public function it_falls_back_to_the_built_in_default_names_when_the_employee_has_no_code(): void
    {
        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Anna',
            'last_name' => 'Klein',
            'entry_date' => '2025-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
        ]);

        $slip = app(PayrollCalculator::class)->calculate($employee->fresh(), 3, 2026);
        $slip->save();

        $response = $this->actAsOrg()->get("/payroll/salary-slips/{$slip->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Payroll/SalarySlips/Show')
            ->where('deductionRows.0.name', 'AVS/AI/APG (employee)'));
    }

    #[Test]
    public function the_slip_page_and_the_pdf_work_for_a_deleted_employee(): void
    {
        $employee = $this->employee();
        $calculated = app(PayrollCalculator::class)->calculate($employee->fresh(), 3, 2026);
        $calculated->save();
        // A slip calculated before the rate snapshot existed.
        $older = app(PayrollCalculator::class)->calculate($employee->fresh(), 4, 2026);
        $older->deduction_snapshot = null;
        $older->save();

        $employee->delete();

        foreach ([$calculated, $older] as $slip) {
            $this->actAsOrg()->get("/payroll/salary-slips/{$slip->id}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('deductionRows.0.name', 'AVS/AI/APG (employee)'));
            $this->actAsOrg()->get(route('payroll.salarySlips.pdf', $slip))->assertOk();
        }
    }

    #[Test]
    public function the_document_iban_falls_back_to_the_employee_when_the_snapshot_predates_it(): void
    {
        $employee = $this->employee(['iban' => 'CH9300762011623852957']);
        $slip = app(PayrollCalculator::class)->calculate($employee->fresh(), 3, 2026);
        // Snapshot shape before the IBAN was added to it (no `iban` key).
        $slip->employee_snapshot = ['first_name' => 'Anna', 'last_name' => 'Klein', 'email' => null, 'ahv_number' => null, 'ahv_number_encrypted' => true];
        $slip->save();

        $this->assertSame('CH9300762011623852957', $slip->fresh()->employeeDocumentData()['iban']);

        // An explicit empty IBAN in the snapshot stays empty.
        $slip->employee_snapshot = [...$slip->employee_snapshot, 'iban' => null, 'iban_encrypted' => true];
        $slip->save();
        $this->assertNull($slip->fresh()->employeeDocumentData()['iban']);
    }

    #[Test]
    public function the_annual_summary_sums_every_employee_charge_by_its_certificate_box(): void
    {
        $employee = $this->employee(['deduction_rate_code' => 'SPECIAL']);
        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id, 'code' => 'SPECIAL', 'title' => 'Spécial', 'date_from' => '2026-01-01', 'date_to' => null,
        ]);
        foreach ([
            // Codes created in the screen, not the four legacy keys.
            ['name' => 'AVS/AI/APG/AC', 'code' => 'avs_ai_apg_ac_employee', 'rate' => '6.0000', 'type' => 'employee', 'certificate_box' => '9'],
            ['name' => 'APGM', 'code' => 'apgm_employee', 'rate' => '0.5000', 'type' => 'employee', 'certificate_box' => '9'],
            ['name' => 'Caisse de pension', 'code' => 'pension_employee', 'rate' => '7.0000', 'type' => 'employee', 'certificate_box' => '10'],
            ['name' => 'Caisse de pension', 'code' => 'pension_employer', 'rate' => '7.0000', 'type' => 'employer'],
            ['name' => 'Indemnité maladie', 'code' => 'ktg_employee', 'rate' => '1.0000', 'type' => 'employee'],
        ] as $line) {
            $set->rates()->create(['organization_id' => $this->org->id, ...$line]);
        }
        foreach ([3, 4] as $month) {
            $slip = app(PayrollCalculator::class)->calculate($employee->fresh(), $month, 2026);
            $slip->posted_at = now();
            $slip->save();
        }

        $certificate = app(GenerateSalaryCertificateAction::class)->execute($employee->fresh(), 2026);

        // 6000 a month: box 9 = 2 x (360 + 30), box 10 = 2 x 420, other = 2 x 60.
        $this->assertSame('780.00', $certificate['social_contributions']);
        $this->assertSame('840.00', $certificate['pension_contributions']);
        $this->assertSame('120.00', $certificate['other_deductions']);
        $this->assertSame('10260.00', $certificate['net_salary']);
    }

    #[Test]
    public function the_annual_summary_lists_box_15_charges_by_name_with_their_amount(): void
    {
        $employee = $this->employee(['deduction_rate_code' => 'SPECIAL']);
        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id, 'code' => 'SPECIAL', 'title' => 'Spécial', 'date_from' => '2026-01-01', 'date_to' => null,
        ]);
        foreach ([
            ['name' => 'AVS/AI/APG', 'code' => 'avs_employee', 'rate' => '5.3000', 'type' => 'employee', 'certificate_box' => '9'],
            ['name' => 'PC Familles', 'code' => 'pc_familles_employee', 'rate' => '0.0600', 'type' => 'employee', 'certificate_box' => '15'],
            ['name' => 'APGM', 'code' => 'apgm_employee', 'rate' => '0.5000', 'type' => 'employee', 'certificate_box' => '15'],
            ['name' => 'APGM', 'code' => 'apgm_employer', 'rate' => '0.5000', 'type' => 'employer'],
            ['name' => 'Divers', 'code' => 'divers_employee', 'rate' => '1.0000', 'type' => 'employee'],
        ] as $line) {
            $set->rates()->create(['organization_id' => $this->org->id, ...$line]);
        }
        foreach ([3, 4] as $month) {
            $slip = app(PayrollCalculator::class)->calculate($employee->fresh(), $month, 2026);
            $slip->posted_at = now();
            $slip->save();
            // Renamed after March: one remark per charge, under its latest name.
            $set->rates()->where('code', 'pc_familles_employee')->update(['name' => 'PC Familles VD']);
        }
        // The box is read from the slip's snapshot: a later change of the line does not move posted slips.
        $set->rates()->where('code', 'apgm_employee')->update(['certificate_box' => null]);

        $certificate = app(GenerateSalaryCertificateAction::class)->execute($employee->fresh(), 2026);

        // 6000 a month: AVS 2 x 318, PC Familles 2 x 3.60, APGM 2 x 30, Divers 2 x 60.
        $this->assertSame('636.00', $certificate['social_contributions']);
        $this->assertSame([
            ['name' => 'APGM', 'amount' => '60.00'],
            ['name' => 'PC Familles VD', 'amount' => '7.20'],
        ], $certificate['remarks']);
        // Box 11 = box 8 (12'000) - box 9 (636) - box 10 (0).
        $this->assertSame('11364.00', $certificate['certificate_net_salary']);
        $this->assertSame('120.00', $certificate['other_deductions']);
        // Box 15 charges are still deducted from the net salary.
        $this->assertSame('11176.80', $certificate['net_salary']);

        app()->setLocale('fr');
        $html = view('exports.salary-certificate', ['certificate' => $certificate, 'organization' => $this->org])->render();
        $this->assertStringContainsString('Remarques (chiffre 15)', $html);
        $this->assertStringContainsString('Cotisation du collaborateur : PC Familles VD', $html);
        $this->assertStringContainsString('Salaire net (chiffre 11)', $html);
        $this->assertStringContainsString('11&#039;364.00', $html); // escaped thousands separator
        // Box 15 charges are remarks: their deduction row carries the name only.
        $this->assertStringNotContainsString('APGM (chiffre 15)', $html);
        $this->assertMatchesRegularExpression('#<td>APGM</td><td class="right">-60.00</td>#', $html);
        $this->assertStringContainsString('CHF 60.00', $html);
    }

    #[Test]
    public function the_annual_summary_has_no_remarks_without_box_15_charges(): void
    {
        $employee = $this->employee();
        $slip = app(PayrollCalculator::class)->calculate($employee->fresh(), 3, 2026);
        $slip->posted_at = now();
        $slip->save();

        $certificate = app(GenerateSalaryCertificateAction::class)->execute($employee->fresh(), 2026);

        $this->assertSame([], $certificate['remarks']);
        app()->setLocale('fr');
        $html = view('exports.salary-certificate', ['certificate' => $certificate, 'organization' => $this->org])->render();
        $this->assertStringNotContainsString('Remarques (chiffre 15)', $html);
    }

    #[Test]
    public function the_annual_summary_uses_the_legacy_boxes_for_imported_slips(): void
    {
        $employee = $this->employee();
        SalarySlip::create([
            'organization_id' => $this->org->id, 'employee_id' => $employee->id, 'period_month' => 1, 'period_year' => 2026,
            'gross_salary' => '6000.00', 'net_salary' => '5300.00', 'posted_at' => now(),
            'deductions' => ['avs_employee' => '318.00', 'aanp_employee' => '60.00', 'lpp_employee' => '300.00', 'apgm_employee' => '22.00', 'lpp_employer' => '300.00', 'total_employee' => '700.00', 'total_employer' => '300.00', 'net_salary' => '5300.00', 'source' => 'imported'],
        ]);

        $certificate = app(GenerateSalaryCertificateAction::class)->execute($employee->fresh(), 2026);

        $this->assertSame('378.00', $certificate['social_contributions']);
        $this->assertSame('300.00', $certificate['pension_contributions']);
        $this->assertSame('22.00', $certificate['other_deductions']);
    }

    #[Test]
    public function an_employee_viewing_their_own_slip_does_not_get_the_rate_snapshot(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->org->users()->attach($user->id, ['role' => 'employee']);
        $this->assignOrganizationRole($user, $this->org, 'employee');
        $employee = $this->employee(['user_id' => $user->id]);
        $slip = app(PayrollCalculator::class)->calculate($employee->fresh(), 3, 2026);
        $slip->posted_at = now();
        $slip->save();

        $this->actingAs($user)->withSession(['current_organization_id' => $this->org->id])
            ->get("/payroll/salary-slips/{$slip->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('slip.deduction_snapshot')->has('deductionRows'));

        // A payroll manager still sees it.
        $this->actAsOrg()->get("/payroll/salary-slips/{$slip->id}")
            ->assertInertia(fn ($page) => $page->has('slip.deduction_snapshot'));
    }

    /** @param array<string, mixed> $attributes */
    private function employee(array $attributes = []): Employee
    {
        return Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Anna',
            'last_name' => 'Klein',
            'entry_date' => '2025-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
            // Required on a salary certificate.
            'ahv_number' => '756.1234.5678.97',
            'date_of_birth' => '1988-02-29',
            'address' => 'Rue Centrale 5',
            'postal_code' => '1003',
            'city' => 'Lausanne',
            ...$attributes,
        ]);
    }
}

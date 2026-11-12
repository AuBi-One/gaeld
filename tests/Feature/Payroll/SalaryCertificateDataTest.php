<?php

namespace Tests\Feature\Payroll;

use App\Domains\Payroll\Actions\GenerateSalaryCertificateAction;
use App\Domains\Payroll\Exceptions\IncompleteSalaryCertificateException;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The salary certificate shows the employment period within the year and the
 * employee's date of birth, and is refused while data it must show is
 * missing.
 *
 * Ideas from Ideall-Engineering/Gaeld 0c63f233, 3c5f6342, ee8bb7d0 (our box
 * mapping and layout kept).
 */
class SalaryCertificateDataTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->org->update(['address' => 'Avenue de la Gare 2', 'postal_code' => '1003', 'city' => 'Lausanne']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function employee(array $attributes = []): Employee
    {
        return Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Anna',
            'last_name' => 'Klein',
            'ahv_number' => '756.1234.5678.97',
            'date_of_birth' => '1988-02-29',
            'address' => 'Rue Centrale 5',
            'postal_code' => '1003',
            'city' => 'Lausanne',
            'entry_date' => '2020-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function postedSlip(Employee $employee, int $month): void
    {
        $slip = app(PayrollCalculator::class)->calculate($employee, $month, 2026);
        $slip->posted_at = now();
        $slip->save();
    }

    public function test_the_period_is_the_whole_year_for_an_employee_there_all_year(): void
    {
        $employee = $this->employee();
        $this->postedSlip($employee, 3);

        $certificate = app(GenerateSalaryCertificateAction::class)->execute($employee->fresh(), 2026);

        $this->assertSame('2026-01-01', $certificate['period_from']);
        $this->assertSame('2026-12-31', $certificate['period_to']);
    }

    public function test_the_period_follows_entry_and_exit_within_the_year(): void
    {
        $employee = $this->employee(['entry_date' => '2026-03-15', 'exit_date' => '2026-09-30']);
        $this->postedSlip($employee, 4);

        $certificate = app(GenerateSalaryCertificateAction::class)->execute($employee->fresh(), 2026);

        $this->assertSame('2026-03-15', $certificate['period_from']);
        $this->assertSame('2026-09-30', $certificate['period_to']);
    }

    public function test_the_pdf_view_shows_date_of_birth_address_and_period(): void
    {
        App::setLocale('fr');
        $employee = $this->employee(['entry_date' => '2026-03-15']);
        $this->postedSlip($employee, 4);
        $certificate = app(GenerateSalaryCertificateAction::class)->execute($employee->fresh(), 2026);

        $html = view('exports.salary-certificate', ['certificate' => $certificate, 'organization' => $this->org])->render();

        $this->assertStringContainsString('Date de naissance', $html);
        $this->assertStringContainsString('29.02.1988', $html);
        $this->assertStringContainsString('Rue Centrale 5, 1003 Lausanne', $html);
        $this->assertStringContainsString('du 15.03.2026 au 31.12.2026', $html);
    }

    public function test_it_refuses_while_required_data_is_missing_and_says_what(): void
    {
        App::setLocale('fr');
        $employee = $this->employee(['date_of_birth' => null, 'ahv_number' => null, 'city' => '']);
        $this->org->update(['address' => null]);
        $this->postedSlip($employee, 3);

        try {
            app(GenerateSalaryCertificateAction::class)->execute($employee->fresh(), 2026);
            $this->fail('An incomplete certificate was generated.');
        } catch (IncompleteSalaryCertificateException $e) {
            $this->assertSame([
                __('app.ahv_number'),
                __('app.date_of_birth'),
                __('app.city'),
                __('app.salary_certificate_employer_address'),
            ], $e->missing);
            $this->assertStringStartsWith('Le certificat de salaire ne peut pas encore être établi. Manquant : ', $e->getMessage());
        }
    }

    public function test_the_download_redirects_with_the_message_instead_of_a_pdf(): void
    {
        $employee = $this->employee(['date_of_birth' => null]);
        $this->postedSlip($employee, 3);

        $this->actAsOrg()
            ->get(route('payroll.salaryCertificate.download', ['employee' => $employee, 'year' => 2026]))
            ->assertRedirect(route('payroll.employees.show', $employee))
            ->assertSessionHas('error', __('app.salary_certificate_incomplete', ['fields' => __('app.date_of_birth')]));
    }

    public function test_the_employee_page_lists_what_the_certificate_still_needs(): void
    {
        $employee = $this->employee(['date_of_birth' => null]);

        $this->actAsOrg()->get(route('payroll.employees.show', $employee))
            ->assertInertia(fn ($page) => $page
                ->where('certificateMissing', [__('app.date_of_birth')])
                ->where('employee.date_of_birth', null));

        $complete = $this->employee(['first_name' => 'Ben']);
        $this->actAsOrg()->get(route('payroll.employees.show', $complete))
            ->assertInertia(fn ($page) => $page
                ->where('certificateMissing', [])
                ->where('employee.date_of_birth', '1988-02-29'));
    }

    public function test_the_date_of_birth_is_saved_from_the_forms_and_not_listed_on_the_run_page(): void
    {
        $this->actAsOrg()->post(route('payroll.employees.store'), [
            'first_name' => 'Chloé',
            'last_name' => 'Rey',
            'date_of_birth' => '1995-07-01',
            'entry_date' => '2026-01-01',
            'gross_salary' => '5000.00',
            'is_active' => true,
        ])->assertSessionHasNoErrors();
        $employee = Employee::where('last_name', 'Rey')->firstOrFail();
        $this->assertSame('1995-07-01', $employee->date_of_birth->toDateString());

        $this->actAsOrg()->put(route('payroll.employees.update', $employee), [
            'first_name' => 'Chloé',
            'last_name' => 'Rey',
            'date_of_birth' => '1995-07-02',
            'entry_date' => '2026-01-01',
            'gross_salary' => '5000.00',
            'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame('1995-07-02', $employee->fresh()->date_of_birth->toDateString());

        $this->actAsOrg()->put(route('payroll.employees.update', $employee), [
            'first_name' => 'Chloé',
            'last_name' => 'Rey',
            'date_of_birth' => now()->addDay()->toDateString(),
            'entry_date' => '2026-01-01',
            'gross_salary' => '5000.00',
        ])->assertSessionHasErrors('date_of_birth');

        // Personal data, hidden like the AHV number outside the employee screens.
        $this->actAsOrg()->get(route('payroll.run'))
            ->assertInertia(fn ($page) => $page->missing('employees.0.date_of_birth'));
    }
}

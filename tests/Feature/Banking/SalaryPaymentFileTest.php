<?php

namespace Tests\Feature\Banking;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Posted salary slips are paid with a separate pain.001 file (category purpose SALA), leave the
 * list of payments to make once in a file, and can be put back.
 */
class SalaryPaymentFileTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->organization->update(['locale' => 'fr']);

        $bank = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value,
        ]);
        $this->bankAccount = BankAccount::create([
            'organization_id' => $this->organization->id,
            'account_id' => $bank->id,
            'name' => 'Main',
            'iban' => 'CH9300762011623852957',
            'currency' => 'CHF',
            'balance' => 1000,
        ]);
    }

    private function slip(bool $posted = true, ?string $city = 'Lausanne'): SalarySlip
    {
        $employee = Employee::factory()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Anna', 'last_name' => 'Muster',
            'iban' => 'CH4431999123000889012',
            'address' => 'Rue de Bourg 1', 'postal_code' => '1003', 'city' => $city, 'country' => 'CH',
        ]);
        $entry = $posted ? JournalEntry::create([
            'organization_id' => $this->organization->id, 'date' => '2026-11-25',
            'reference' => 'SAL-'.uniqid(), 'description' => 'Salary', 'is_posted' => true,
        ]) : null;

        return SalarySlip::create([
            'organization_id' => $this->organization->id,
            'employee_id' => $employee->id,
            'period_month' => 11,
            'period_year' => 2026,
            'gross_salary' => '6000.00',
            'net_salary' => '5328.60',
            'deductions' => [],
            'journal_entry_id' => $entry?->id,
            'posted_at' => $posted ? now() : null,
            'posting_date' => '2026-11-25',
        ]);
    }

    private function download(array $slipIds, string $date = '2099-11-30')
    {
        return $this->actAsOrg()->post('/payments/outgoing/salaries/download', [
            'bank_account_id' => $this->bankAccount->id,
            'salary_slip_ids' => $slipIds,
            'execution_date' => $date,
        ]);
    }

    public function test_only_posted_slips_not_yet_in_a_file_are_listed(): void
    {
        $posted = $this->slip();
        $this->slip(posted: false);

        $this->actAsOrg()->get('/payments/outgoing')->assertOk()
            ->assertInertia(fn ($page) => $page->where('canPaySalaries', true)
                ->has('salarySlips', 1)
                ->where('salarySlips.0.id', $posted->id)
                ->where('salarySlips.0.employee', 'Anna Muster')
                ->where('salarySlips.0.amount', '5328.60'));
    }

    public function test_the_salary_file_is_a_separate_sala_batch_and_the_slip_leaves_the_list(): void
    {
        $slip = $this->slip();

        $response = $this->download([$slip->id])->assertOk();
        $xml = $response->getContent();

        $this->assertStringContainsString('<CtgyPurp>', $xml);
        $this->assertStringContainsString('<Cd>SALA</Cd>', $xml);
        $this->assertStringContainsString('<InstdAmt Ccy="CHF">5328.60</InstdAmt>', $xml);
        $this->assertStringContainsString('<Nm>Anna Muster</Nm>', $xml);
        $this->assertStringContainsString('CH4431999123000889012', $xml);
        $this->assertStringContainsString('<Dt>2099-11-30</Dt>', $xml);
        $this->assertStringContainsString('Salaire novembre 2026', $xml);
        // Domestic CHF: no SEPA charge bearer (BCV rejects it), even though PmtTpInf is there for SALA.
        $this->assertStringNotContainsString('<ChrgBr>', $xml);
        $this->assertStringNotContainsString('<SvcLvl>', $xml);
        $this->assertNotNull($slip->refresh()->payment_file_at);

        // Not twice in a file.
        $this->download([$slip->id])->assertSessionHasErrors('salary_slip_ids.0');
        $this->actAsOrg()->get('/payments/outgoing')
            ->assertInertia(fn ($page) => $page->has('salarySlips', 0)->has('paidSalarySlips', 1));
    }

    public function test_the_employees_current_iban_is_paid_not_the_one_copied_on_the_slip(): void
    {
        $slip = $this->slip();
        $slip->forceFill(['employee_snapshot' => ['first_name' => 'Anna', 'last_name' => 'Muster', 'iban' => null, 'city' => null]])->save();
        $slip->employee->forceFill(['iban' => 'CH5604835012345678009'])->save();

        $xml = $this->download([$slip->id])->assertOk()->getContent();

        $this->assertStringContainsString('CH5604835012345678009', $xml);
        $this->assertStringContainsString('<TwnNm>Lausanne</TwnNm>', $xml);
    }

    public function test_the_migration_marks_earlier_months_as_paid_and_keeps_the_current_one(): void
    {
        $this->travelTo('2026-11-27 10:00:00');
        $current = $this->slip();
        $earlier = $this->slip();
        $earlier->forceFill(['period_month' => 10, 'posting_date' => '2026-10-25'])->save();
        $draft = $this->slip(posted: false);
        $draft->forceFill(['period_month' => 9])->save();

        $migration = require database_path('migrations/2026_11_27_100000_add_payment_file_at_to_salary_slips.php');
        $migration->down();
        $migration->up();

        $this->assertNull($current->refresh()->payment_file_at);
        $this->assertSame('2026-10-25', $earlier->refresh()->payment_file_at?->toDateString());
        $this->assertNull($earlier->payment_file_ref);
        // Paid before payment files: not offered for putting back.
        $this->actAsOrg()->post("/payments/outgoing/salaries/{$earlier->id}/reopen")->assertStatus(422);
        $this->actAsOrg()->get('/payments/outgoing')->assertInertia(fn ($page) => $page->has('paidSalarySlips', 0));
        $this->assertNull($draft->refresh()->payment_file_at);
    }

    public function test_a_salary_can_be_put_back_on_the_list(): void
    {
        $slip = $this->slip();
        $this->download([$slip->id])->assertOk();

        $this->actAsOrg()->post("/payments/outgoing/salaries/{$slip->id}/reopen")->assertRedirect('/payments/outgoing');

        $this->assertNull($slip->refresh()->payment_file_at);
    }

    public function test_an_unposted_slip_or_a_missing_address_is_refused(): void
    {
        $this->download([$this->slip(posted: false)->id])->assertSessionHasErrors('salary_slip_ids.0');

        $noTown = $this->slip(city: null);
        $this->download([$noTown->id])->assertRedirect('/payments/outgoing')->assertSessionHas('error');
        $this->assertNull($noTown->refresh()->payment_file_at);

        $noIban = $this->slip();
        $noIban->employee->forceFill(['iban' => null])->save();
        $this->download([$noIban->id])->assertRedirect('/payments/outgoing')
            ->assertSessionHas('error', __('app.payments_salary_iban_missing', ['names' => 'Anna Muster']));
        $this->assertNull($noIban->refresh()->payment_file_at);
    }

    public function test_a_user_who_may_not_see_the_bank_cannot_download_salaries(): void
    {
        $slip = $this->slip();
        $staff = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($staff->id, ['role' => 'employee']);
        $this->assignOrganizationRole($staff, $this->organization, 'employee');

        $this->actingAs($staff)->withSession(['current_organization_id' => $this->organization->id])
            ->post('/payments/outgoing/salaries/download', [
                'bank_account_id' => $this->bankAccount->id, 'salary_slip_ids' => [$slip->id], 'execution_date' => '2099-11-30',
            ])->assertForbidden();
        $this->assertNull($slip->refresh()->payment_file_at);
    }

    public function test_the_slip_pdf_is_named_after_the_payday_and_the_employee(): void
    {
        $slip = $this->slip();

        $this->actAsOrg()->get("/payroll/salary-slips/{$slip->id}/pdf")->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=Salaire_2026-11-25_Anna_Muster.pdf');
    }

    public function test_a_salary_in_a_payment_file_cannot_be_unposted(): void
    {
        $slip = $this->slip();
        $this->download([$slip->id])->assertOk();

        $this->actAsOrg()->postJson("/payroll/salary-slips/{$slip->id}/unpost")
            ->assertStatus(422)->assertJsonPath('message', __('app.salary_slip_in_payment_file'));
        $this->assertNotNull($slip->refresh()->posted_at);
    }

    public function test_a_read_only_viewer_sees_salaries_but_cannot_pay_or_put_back(): void
    {
        $slip = $this->slip();
        $this->download([$slip->id])->assertOk();
        $other = $this->slip();
        $viewer = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        $this->assignOrganizationRole($viewer, $this->organization, 'viewer');
        $asViewer = fn () => $this->actingAs($viewer)->withSession(['current_organization_id' => $this->organization->id]);

        $asViewer()->get('/payments/outgoing')->assertOk()
            ->assertInertia(fn ($page) => $page->where('canPaySalaries', false)->has('salarySlips', 1));
        $asViewer()->post('/payments/outgoing/salaries/download', [
            'bank_account_id' => $this->bankAccount->id, 'salary_slip_ids' => [$other->id], 'execution_date' => '2099-11-30',
        ])->assertForbidden();
        $asViewer()->post("/payments/outgoing/salaries/{$slip->id}/reopen")->assertForbidden();
        $this->assertNotNull($slip->refresh()->payment_file_at);
    }

    public function test_zero_net_past_dates_and_non_chf_accounts_are_refused(): void
    {
        $zero = $this->slip();
        $zero->forceFill(['net_salary' => '0.00'])->save();
        $this->actAsOrg()->get('/payments/outgoing')->assertInertia(fn ($page) => $page->has('salarySlips', 0));
        $this->download([$zero->id])->assertRedirect('/payments/outgoing')->assertSessionHas('error');

        $slip = $this->slip();
        $this->download([$slip->id], now()->subDay()->toDateString())->assertSessionHasErrors('execution_date');

        $eur = BankAccount::create([
            'organization_id' => $this->organization->id, 'account_id' => $this->bankAccount->account_id,
            'name' => 'EUR', 'iban' => 'CH5604835012345678009', 'currency' => 'EUR', 'balance' => 0,
        ]);
        $this->actAsOrg()->post('/payments/outgoing/salaries/download', [
            'bank_account_id' => $eur->id, 'salary_slip_ids' => [$slip->id], 'execution_date' => '2099-11-30',
        ])->assertSessionHasErrors('bank_account_id');
        $this->assertNull($slip->refresh()->payment_file_at);
    }
}

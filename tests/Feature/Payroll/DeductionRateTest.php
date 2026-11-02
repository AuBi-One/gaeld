<?php

namespace Tests\Feature\Payroll;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\DeductionRateSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class DeductionRateTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private DeductionRateSet $set;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $this->set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
    }

    public function test_it_creates_a_deduction_rate_line_with_different_employee_and_employer_values(): void
    {
        $response = $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'APGM',
            'code' => 'apgm_employee',
            'rate' => '0.6100',
            'type' => 'employee',
        ]);
        $response->assertRedirect();

        $response = $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'APGM',
            'code' => 'apgm_employer',
            'rate' => '0.6100',
            'type' => 'employer',
        ]);
        $response->assertRedirect();

        $this->assertDatabaseHas('deduction_rates', [
            'deduction_rate_set_id' => $this->set->id,
            'code' => 'apgm_employee',
            'type' => 'employee',
        ]);
        $this->assertDatabaseHas('deduction_rates', [
            'deduction_rate_set_id' => $this->set->id,
            'code' => 'apgm_employer',
            'type' => 'employer',
        ]);
    }

    public function test_it_rejects_a_duplicate_code_within_the_same_set(): void
    {
        DeductionRate::create([
            'organization_id' => $this->org->id,
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.3000',
            'type' => 'employee',
        ]);

        $response = $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'AVS duplicate',
            'code' => 'avs_employee',
            'rate' => '6.0000',
            'type' => 'employee',
        ]);

        $response->assertSessionHasErrors('code');
    }

    public function test_the_same_code_can_be_reused_in_a_different_set(): void
    {
        DeductionRate::create([
            'organization_id' => $this->org->id,
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.3000',
            'type' => 'employee',
        ]);

        $otherSet = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2027',
            'date_from' => '2027-01-01',
            'date_to' => '2027-12-31',
        ]);

        $response = $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $otherSet->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.5000',
            'type' => 'employee',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('deduction_rates', [
            'deduction_rate_set_id' => $otherSet->id,
            'code' => 'avs_employee',
            'rate' => '5.5000',
        ]);
    }

    public function test_it_updates_the_rate_independently_for_employee_and_employer(): void
    {
        $employeeRate = DeductionRate::create([
            'organization_id' => $this->org->id,
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'LPP',
            'code' => 'lpp_employee',
            'rate' => '7.0000',
            'type' => 'employee',
        ]);
        $employerRate = DeductionRate::create([
            'organization_id' => $this->org->id,
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'LPP',
            'code' => 'lpp_employer',
            'rate' => '7.0000',
            'type' => 'employer',
        ]);

        $this->actAsOrg()->put("/payroll/deduction-rates/{$employerRate->id}", [
            'name' => 'LPP',
            'rate' => '11.0000',
            'type' => 'employer',
        ])->assertRedirect();

        $this->assertDatabaseHas('deduction_rates', ['id' => $employeeRate->id, 'rate' => '7.0000']);
        $this->assertDatabaseHas('deduction_rates', ['id' => $employerRate->id, 'rate' => '11.0000']);
    }

    public function test_it_deletes_a_deduction_rate_line(): void
    {
        $rate = DeductionRate::create([
            'organization_id' => $this->org->id,
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'Allocations familiales',
            'code' => 'family_allowance_employer',
            'rate' => '3.1500',
            'type' => 'employer',
        ]);

        $response = $this->actAsOrg()->delete("/payroll/deduction-rates/{$rate->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('deduction_rates', ['id' => $rate->id]);
    }

    public function test_it_creates_a_deduction_rate_with_an_account_mapping(): void
    {
        $account = Account::create([
            'organization_id' => $this->org->id,
            'code' => '2270',
            'name' => 'AVS payable',
            'type' => AccountType::Liability->value,
        ]);

        $response = $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'AVS',
            'code' => 'avs_custom_employee',
            'rate' => '5.3000',
            'type' => 'employee',
            'account_id' => $account->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('deduction_rates', [
            'deduction_rate_set_id' => $this->set->id,
            'code' => 'avs_custom_employee',
            'account_id' => $account->id,
        ]);
    }

    public function test_it_rejects_an_account_from_another_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignAccount = Account::withoutGlobalScopes()->create([
            'organization_id' => $otherOrg->id,
            'code' => '2270',
            'name' => 'Other org account',
            'type' => AccountType::Liability->value,
        ]);

        $response = $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $this->set->id,
            'name' => 'AVS',
            'code' => 'avs_custom_employee',
            'rate' => '5.3000',
            'type' => 'employee',
            'account_id' => $foreignAccount->id,
        ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertDatabaseMissing('deduction_rates', ['code' => 'avs_custom_employee']);
    }

    public function test_it_rejects_a_set_from_another_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignSet = DeductionRateSet::withoutGlobalScopes()->create([
            'organization_id' => $otherOrg->id,
            'code' => 'STANDARD',
            'title' => 'Foreign',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $response = $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $foreignSet->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.3000',
            'type' => 'employee',
        ]);

        $response->assertSessionHasErrors('deduction_rate_set_id');
    }

    public function test_it_prevents_updating_a_deduction_rate_from_another_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignSet = DeductionRateSet::withoutGlobalScopes()->create([
            'organization_id' => $otherOrg->id,
            'code' => 'STANDARD',
            'title' => 'Foreign',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $foreignRate = DeductionRate::withoutGlobalScopes()->create([
            'organization_id' => $otherOrg->id,
            'deduction_rate_set_id' => $foreignSet->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.3000',
            'type' => 'employee',
        ]);

        $response = $this->actAsOrg()->put("/payroll/deduction-rates/{$foreignRate->id}", [
            'name' => 'Hijacked',
            'rate' => '99.0000',
            'type' => 'employee',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseHas('deduction_rates', ['id' => $foreignRate->id, 'rate' => '5.3000']);
    }

    public function test_it_prevents_deleting_a_deduction_rate_from_another_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignSet = DeductionRateSet::withoutGlobalScopes()->create([
            'organization_id' => $otherOrg->id,
            'code' => 'STANDARD',
            'title' => 'Foreign',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $foreignRate = DeductionRate::withoutGlobalScopes()->create([
            'organization_id' => $otherOrg->id,
            'deduction_rate_set_id' => $foreignSet->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.3000',
            'type' => 'employee',
        ]);

        $response = $this->actAsOrg()->delete("/payroll/deduction-rates/{$foreignRate->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('deduction_rates', ['id' => $foreignRate->id]);
    }

    public function test_it_rejects_reserved_codes_and_codes_whose_suffix_is_not_the_side(): void
    {
        foreach ([
            ['code' => 'total_employee', 'type' => 'employee'],
            ['code' => 'net_salary', 'type' => 'employee'],
            ['code' => 'source_tax', 'type' => 'employee'],
            ['code' => 'ktg', 'type' => 'employee'],
            ['code' => 'ktg_employer', 'type' => 'employee'],
        ] as $line) {
            $this->actAsOrg()->post('/payroll/deduction-rates', [
                'deduction_rate_set_id' => $this->set->id, 'name' => 'X', 'rate' => '1', ...$line,
            ])->assertSessionHasErrors('code');
        }

        $this->assertSame(0, $this->set->rates()->count());
    }

    public function test_the_rate_must_fit_the_column(): void
    {
        $post = fn (string $rate) => $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $this->set->id, 'name' => 'AVS', 'code' => 'avs_employee', 'rate' => $rate, 'type' => 'employee',
        ]);

        $post('100')->assertSessionHasErrors('rate');
        $post('5.12345')->assertSessionHasErrors('rate');
        $post('99.9999')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('deduction_rates', ['code' => 'avs_employee', 'rate' => '99.9999']);

        $rate = $this->set->rates()->sole();
        $this->actAsOrg()->put("/payroll/deduction-rates/{$rate->id}", ['name' => 'AVS', 'rate' => '100'])
            ->assertSessionHasErrors('rate');
    }

    public function test_only_a_liability_account_can_be_mapped(): void
    {
        $expense = Account::create(['organization_id' => $this->org->id, 'code' => '5700', 'name' => 'Social charges', 'type' => AccountType::Expense->value]);

        $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $this->set->id, 'name' => 'AVS', 'code' => 'avs_employer', 'rate' => '5.3', 'type' => 'employer', 'account_id' => $expense->id,
        ])->assertSessionHasErrors('account_id');

        $rate = DeductionRate::create(['organization_id' => $this->org->id, 'deduction_rate_set_id' => $this->set->id, 'name' => 'AVS', 'code' => 'avs_employer', 'rate' => '5.3000', 'type' => 'employer']);
        $this->actAsOrg()->put("/payroll/deduction-rates/{$rate->id}", ['name' => 'AVS', 'rate' => '5.3', 'account_id' => $expense->id])
            ->assertSessionHasErrors('account_id');
        $this->assertNull($rate->fresh()->account_id);

        $this->actAsOrg()->get('/payroll/deduction-rates')
            ->assertInertia(fn ($page) => $page->has('deductionRateAccounts', 0));
    }

    public function test_an_employee_line_keeps_its_certificate_box_and_an_employer_line_has_none(): void
    {
        $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $this->set->id, 'name' => 'LPP plan', 'code' => 'lpp_plan_employee', 'rate' => '6', 'type' => 'employee', 'certificate_box' => '10',
        ])->assertSessionHasNoErrors();
        $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $this->set->id, 'name' => 'LPP plan', 'code' => 'lpp_plan_employer', 'rate' => '6', 'type' => 'employer', 'certificate_box' => '10',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('deduction_rates', ['code' => 'lpp_plan_employee', 'certificate_box' => '10']);
        $this->assertDatabaseHas('deduction_rates', ['code' => 'lpp_plan_employer', 'certificate_box' => null]);

        $this->actAsOrg()->post('/payroll/deduction-rates', [
            'deduction_rate_set_id' => $this->set->id, 'name' => 'Bad', 'code' => 'bad_employee', 'rate' => '1', 'type' => 'employee', 'certificate_box' => '15',
        ])->assertSessionHasErrors('certificate_box');
    }
}

<?php

namespace Tests\Feature\Payroll;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\DeductionRateSet;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class DeductionRateSetTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_opening_the_page_does_not_write(): void
    {
        $response = $this->actAsOrg()->get('/payroll/deduction-rates');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Payroll/DeductionRates')->where('canManage', true));
        $this->assertSame(0, DeductionRateSet::where('organization_id', $this->org->id)->count());
    }

    public function test_seeding_creates_an_open_ended_standard_set_and_gives_it_to_employees_without_a_code(): void
    {
        $employee = $this->employee();

        $this->actAsOrg()->post('/payroll/deduction-rate-sets/seed')->assertRedirect();

        $set = DeductionRateSet::where('organization_id', $this->org->id)->sole();
        $this->assertSame('STANDARD', $set->code);
        $this->assertNull($set->date_to);
        $this->assertSame(7, $set->rates()->count());
        $this->assertSame('9', $set->rates()->where('code', 'avs_employee')->value('certificate_box'));
        $this->assertSame('STANDARD', $employee->fresh()->deduction_rate_code);

        // A second call does not create another set.
        $this->actAsOrg()->post('/payroll/deduction-rate-sets/seed')->assertRedirect();
        $this->assertSame(1, DeductionRateSet::where('organization_id', $this->org->id)->count());
    }

    public function test_a_set_may_be_open_ended(): void
    {
        $this->actAsOrg()->post('/payroll/deduction-rate-sets', [
            'code' => 'STANDARD', 'title' => 'Standard', 'date_from' => '2026-01-01', 'date_to' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(DeductionRateSet::where('code', 'STANDARD')->sole()->date_to);
    }

    public function test_a_new_set_closes_the_open_ended_set_of_its_code(): void
    {
        $open = $this->set('2026-01-01', null);

        $this->actAsOrg()->post('/payroll/deduction-rate-sets', [
            'code' => 'STANDARD', 'title' => 'Standard 2027', 'date_from' => '2027-01-01', 'date_to' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('2026-12-31', $open->fresh()->date_to->toDateString());
        $this->assertNull(DeductionRateSet::where('title', 'Standard 2027')->sole()->date_to);
    }

    public function test_duplicating_closes_the_open_ended_set_of_its_code(): void
    {
        $open = $this->set('2026-01-01', null);

        $this->actAsOrg()->post("/payroll/deduction-rate-sets/{$open->id}/duplicate", [
            'title' => 'Standard 2027', 'date_from' => '2027-01-01', 'date_to' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('2026-12-31', $open->fresh()->date_to->toDateString());
    }

    public function test_a_dated_set_inside_an_open_ended_set_is_an_overlap(): void
    {
        $open = $this->set('2026-01-01', null);

        $this->actAsOrg()->post('/payroll/deduction-rate-sets', [
            'code' => 'STANDARD', 'title' => 'Mid-year', 'date_from' => '2027-01-01', 'date_to' => '2027-06-30',
        ])->assertSessionHasErrors('date_from');
        $this->actAsOrg()->post("/payroll/deduction-rate-sets/{$open->id}/duplicate", [
            'title' => 'Mid-year copy', 'date_from' => '2027-01-01', 'date_to' => '2027-06-30',
        ])->assertSessionHasErrors('date_from');

        $this->assertNull($open->fresh()->date_to, 'the open-ended set is left untouched');
        $this->assertSame(1, DeductionRateSet::where('code', 'STANDARD')->count());
    }

    public function test_the_seeded_set_starts_with_the_earliest_slip_period(): void
    {
        $employee = $this->employee();
        SalarySlip::create([
            'organization_id' => $this->org->id, 'employee_id' => $employee->id, 'period_month' => 3, 'period_year' => 2024,
            'gross_salary' => '6000.00', 'net_salary' => '6000.00', 'deductions' => [],
        ]);

        $this->actAsOrg()->post('/payroll/deduction-rate-sets/seed')->assertRedirect();

        $this->assertSame('2024-03-01', DeductionRateSet::where('organization_id', $this->org->id)->sole()->date_from->toDateString());
    }

    public function test_a_set_starting_before_an_open_ended_set_cannot_reach_into_it(): void
    {
        $this->set('2026-01-01', null);

        $this->actAsOrg()->post('/payroll/deduction-rate-sets', [
            'code' => 'STANDARD', 'title' => 'Earlier', 'date_from' => '2025-01-01', 'date_to' => '',
        ])->assertSessionHasErrors('date_from');
    }

    public function test_the_last_set_of_a_code_used_by_an_employee_cannot_be_deleted(): void
    {
        $set = $this->set('2026-01-01', null);
        $this->employee(['deduction_rate_code' => 'STANDARD']);

        $this->actAsOrg()->delete("/payroll/deduction-rate-sets/{$set->id}")->assertSessionHas('error');

        $this->assertDatabaseHas('deduction_rate_sets', ['id' => $set->id]);
    }

    public function test_the_current_set_cannot_be_deleted_when_only_a_past_set_of_its_code_remains(): void
    {
        $this->set('2020-01-01', '2020-12-31');
        $current = $this->set('2021-01-01', null);
        $this->employee(['deduction_rate_code' => 'STANDARD']);

        $this->actAsOrg()->delete("/payroll/deduction-rate-sets/{$current->id}")->assertSessionHas('error');

        $this->assertDatabaseHas('deduction_rate_sets', ['id' => $current->id]);
    }

    public function test_an_older_set_of_a_code_in_use_can_be_deleted(): void
    {
        $older = $this->set('2025-01-01', '2025-12-31');
        $this->set('2026-01-01', null);
        $this->employee(['deduction_rate_code' => 'STANDARD']);

        $this->actAsOrg()->delete("/payroll/deduction-rate-sets/{$older->id}")->assertSessionHas('success');

        $this->assertDatabaseMissing('deduction_rate_sets', ['id' => $older->id]);
    }

    public function test_payroll_permissions_govern_the_page_not_the_organization_settings(): void
    {
        $set = $this->set('2026-01-01', null);

        // Accountant: payroll.view/edit, no organization.edit.
        $accountant = $this->member('accountant');
        $this->actingAs($accountant)->withSession(['current_organization_id' => $this->org->id])
            ->get('/payroll/deduction-rates')->assertOk();
        $this->actingAs($accountant)->withSession(['current_organization_id' => $this->org->id])
            ->put("/payroll/deduction-rate-sets/{$set->id}", ['title' => 'Renamed', 'date_from' => '2026-01-01', 'date_to' => ''])
            ->assertRedirect();
        $this->assertSame('Renamed', $set->fresh()->title);

        // Viewer: payroll.view only — reads, cannot write.
        $viewer = $this->member('viewer');
        $this->actingAs($viewer)->withSession(['current_organization_id' => $this->org->id])
            ->get('/payroll/deduction-rates')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canManage', false));
        $this->actingAs($viewer)->withSession(['current_organization_id' => $this->org->id])
            ->delete("/payroll/deduction-rate-sets/{$set->id}")->assertForbidden();
        $this->actingAs($viewer)->withSession(['current_organization_id' => $this->org->id])
            ->post('/payroll/deduction-rate-sets/seed')->assertForbidden();

        // Employee role: no payroll.view.
        $this->actingAs($this->member('employee'))->withSession(['current_organization_id' => $this->org->id])
            ->get('/payroll/deduction-rates')->assertForbidden();
    }

    public function test_sets_of_another_organization_cannot_be_changed(): void
    {
        $other = Organization::factory()->create();
        $foreign = DeductionRateSet::withoutGlobalScopes()->create([
            'organization_id' => $other->id, 'code' => 'STANDARD', 'title' => 'Other', 'date_from' => '2026-01-01', 'date_to' => null,
        ]);

        $this->actAsOrg()->put("/payroll/deduction-rate-sets/{$foreign->id}", ['title' => 'Hijack', 'date_from' => '2026-01-01'])->assertNotFound();
        $this->actAsOrg()->post("/payroll/deduction-rate-sets/{$foreign->id}/duplicate", ['title' => 'Copy', 'date_from' => '2027-01-01'])->assertNotFound();
        $this->actAsOrg()->delete("/payroll/deduction-rate-sets/{$foreign->id}")->assertNotFound();

        $this->assertSame('Other', DeductionRateSet::withoutGlobalScopes()->find($foreign->id)->title);
    }

    public function test_it_creates_a_deduction_rate_set(): void
    {
        $response = $this->actAsOrg()->post('/payroll/deduction-rate-sets', [
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('deduction_rate_sets', [
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
        ]);
    }

    public function test_it_rejects_an_overlapping_period_for_the_same_code(): void
    {
        DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $response = $this->actAsOrg()->post('/payroll/deduction-rate-sets', [
            'code' => 'STANDARD',
            'title' => 'Standard mid-year change',
            'date_from' => '2026-06-01',
            'date_to' => '2026-12-31',
        ]);

        $response->assertSessionHasErrors('date_from');
        $this->assertDatabaseMissing('deduction_rate_sets', ['title' => 'Standard mid-year change']);
    }

    public function test_a_different_code_may_use_the_same_period(): void
    {
        DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $response = $this->actAsOrg()->post('/payroll/deduction-rate-sets', [
            'code' => 'CADRE',
            'title' => 'Cadre 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('deduction_rate_sets', ['code' => 'CADRE']);
    }

    public function test_it_updates_a_set_title_and_dates_without_changing_its_code(): void
    {
        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $response = $this->actAsOrg()->put("/payroll/deduction-rate-sets/{$set->id}", [
            'title' => 'Standard 2026 (révisé)',
            'date_from' => '2026-01-01',
            'date_to' => '2026-11-30',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('deduction_rate_sets', [
            'id' => $set->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026 (révisé)',
            'date_to' => '2026-11-30',
        ]);
    }

    public function test_it_rejects_updating_a_set_to_overlap_another_one_with_the_same_code(): void
    {
        DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2025',
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]);
        $set2026 = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $response = $this->actAsOrg()->put("/payroll/deduction-rate-sets/{$set2026->id}", [
            'title' => 'Standard 2026',
            'date_from' => '2025-06-01',
            'date_to' => '2026-12-31',
        ]);

        $response->assertSessionHasErrors('date_from');
    }

    public function test_it_deletes_a_set_and_its_lines(): void
    {
        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $rate = $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.3000',
            'type' => 'employee',
        ]);

        $response = $this->actAsOrg()->delete("/payroll/deduction-rate-sets/{$set->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('deduction_rate_sets', ['id' => $set->id]);
        $this->assertDatabaseMissing('deduction_rates', ['id' => $rate->id]);
    }

    public function test_it_duplicates_a_set_with_its_lines_for_a_new_period(): void
    {
        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'AVS',
            'code' => 'avs_employee',
            'rate' => '5.3000',
            'type' => 'employee',
        ]);
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'AVS',
            'code' => 'avs_employer',
            'rate' => '5.3000',
            'type' => 'employer',
        ]);

        $response = $this->actAsOrg()->post("/payroll/deduction-rate-sets/{$set->id}/duplicate", [
            'title' => 'Standard 2027',
            'date_from' => '2027-01-01',
            'date_to' => '2027-12-31',
        ]);

        $response->assertRedirect();

        $newSet = DeductionRateSet::where('title', 'Standard 2027')->first();
        $this->assertNotNull($newSet);
        $this->assertSame('STANDARD', $newSet->code);
        $this->assertSame(2, $newSet->rates()->count());
        $this->assertDatabaseHas('deduction_rates', [
            'deduction_rate_set_id' => $newSet->id,
            'code' => 'avs_employee',
            'rate' => '5.3000',
        ]);
        // The source set keeps its own lines untouched.
        $this->assertSame(2, $set->rates()->count());
    }

    public function test_it_rejects_duplicating_into_an_overlapping_period(): void
    {
        $set2026 = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard 2027',
            'date_from' => '2027-01-01',
            'date_to' => '2027-12-31',
        ]);

        $response = $this->actAsOrg()->post("/payroll/deduction-rate-sets/{$set2026->id}/duplicate", [
            'title' => 'Standard 2027 (again)',
            'date_from' => '2027-06-01',
            'date_to' => '2027-12-31',
        ]);

        $response->assertSessionHasErrors('date_from');
        $this->assertDatabaseMissing('deduction_rate_sets', ['title' => 'Standard 2027 (again)']);
    }

    private function set(string $from, ?string $to): DeductionRateSet
    {
        return DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'STANDARD',
            'title' => 'Standard '.$from,
            'date_from' => $from,
            'date_to' => $to,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function employee(array $attributes = []): Employee
    {
        return Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Anna',
            'last_name' => 'Klein',
            'entry_date' => '2020-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->org->users()->attach($user->id, ['role' => $role]);
        $this->assignOrganizationRole($user, $this->org, $role);

        return $user;
    }
}

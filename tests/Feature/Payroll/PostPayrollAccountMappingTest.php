<?php

namespace Tests\Feature\Payroll;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Payroll\Actions\GeneratePayrollRunAction;
use App\Domains\Payroll\Actions\PostPayrollAction;
use App\Domains\Payroll\Models\DeductionRateSet;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * PostPayrollAction must credit each deduction code to the account its rate
 * line is mapped to, not just the three fixed AVS/AC/LPP accounts — and
 * refuse to post (rather than silently drop it) when a code has neither a
 * mapping nor a legacy prefix match.
 */
class PostPayrollAccountMappingTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        foreach ([
            ['code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value],
            ['code' => '5000', 'name' => 'Salaries', 'type' => AccountType::Expense->value],
            ['code' => '5700', 'name' => 'Social Charges', 'type' => AccountType::Expense->value],
            ['code' => '6530', 'name' => 'General Expense', 'type' => AccountType::Expense->value],
            ['code' => '2270', 'name' => 'AVS Payable', 'type' => AccountType::Liability->value],
            ['code' => '2271', 'name' => 'AC Payable', 'type' => AccountType::Liability->value],
            ['code' => '2272', 'name' => 'LPP Payable', 'type' => AccountType::Liability->value],
            ['code' => '2273', 'name' => 'Withholding Tax Payable', 'type' => AccountType::Liability->value],
        ] as $account) {
            Account::create(array_merge($account, ['organization_id' => $this->org->id]));
        }

        $this->employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'entry_date' => '2025-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function it_credits_a_mapped_custom_deduction_to_its_own_account(): void
    {
        $apgmAccount = Account::create([
            'organization_id' => $this->org->id,
            'code' => '2274',
            'name' => 'APGM Payable',
            'type' => AccountType::Liability->value,
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
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'APGM',
            'code' => 'apgm_employee',
            'rate' => '0.6100',
            'type' => 'employee',
            'account_id' => $apgmAccount->id,
        ]);

        $this->employee->update(['deduction_rate_code' => 'SPECIAL']);

        $slip = app(PayrollCalculator::class)->calculate($this->employee->fresh(), 3, 2026);
        $slip->save();

        $postedSlip = app(PostPayrollAction::class)->execute($slip);

        $this->assertTrue($postedSlip->journalEntry->isBalanced());

        $apgmLine = $postedSlip->journalEntry->lines->firstWhere('account_id', $apgmAccount->id);
        $this->assertNotNull($apgmLine, 'expected a journal line on the APGM account');
        $this->assertSame($slip->deductions['apgm_employee'], (string) $apgmLine->credit);

        // The unmapped AVS lines still land on the legacy fixed account.
        $avsAccount = Account::where('organization_id', $this->org->id)->where('code', '2270')->first();
        $avsLine = $postedSlip->journalEntry->lines->firstWhere('account_id', $avsAccount->id);
        $this->assertNotNull($avsLine, 'expected a journal line on the legacy AVS account');
    }

    #[Test]
    public function it_refuses_to_post_a_deduction_with_no_account_mapping_and_no_legacy_match(): void
    {
        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'SPECIAL',
            'title' => 'Spécial 2026',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'Repas',
            'code' => 'meal_employee',
            'rate' => '1.0000',
            'type' => 'employee',
            // No account_id: "meal_" matches no legacy prefix.
        ]);

        $this->employee->update(['deduction_rate_code' => 'SPECIAL']);

        $slip = app(PayrollCalculator::class)->calculate($this->employee->fresh(), 3, 2026);
        $slip->save();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('meal_employee');

        app(PostPayrollAction::class)->execute($slip);
    }

    #[Test]
    public function it_sums_two_mapped_codes_sharing_the_same_account_into_one_line(): void
    {
        $sharedAccount = Account::create([
            'organization_id' => $this->org->id,
            'code' => '2275',
            'name' => 'Shared Payable',
            'type' => AccountType::Liability->value,
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
            'name' => 'Charge A',
            'code' => 'charge_a_employee',
            'rate' => '1.0000',
            'type' => 'employee',
            'account_id' => $sharedAccount->id,
        ]);
        $set->rates()->create([
            'organization_id' => $this->org->id,
            'name' => 'Charge B',
            'code' => 'charge_b_employee',
            'rate' => '2.0000',
            'type' => 'employee',
            'account_id' => $sharedAccount->id,
        ]);

        $this->employee->update(['deduction_rate_code' => 'SPECIAL']);

        $slip = app(PayrollCalculator::class)->calculate($this->employee->fresh(), 3, 2026);
        $slip->save();

        $postedSlip = app(PostPayrollAction::class)->execute($slip);

        $this->assertTrue($postedSlip->journalEntry->isBalanced());

        $sharedLines = $postedSlip->journalEntry->lines->where('account_id', $sharedAccount->id);
        $this->assertCount(1, $sharedLines, 'the two codes should merge into a single credit line');

        $expected = bcadd($slip->deductions['charge_a_employee'], $slip->deductions['charge_b_employee'], 2);
        $this->assertSame($expected, (string) $sharedLines->first()->credit);
    }

    #[Test]
    public function posting_uses_the_lines_of_the_calculation_not_later_changes_to_the_sets(): void
    {
        $first = Account::create(['organization_id' => $this->org->id, 'code' => '2274', 'name' => 'APGM Payable', 'type' => AccountType::Liability->value]);
        $second = Account::create(['organization_id' => $this->org->id, 'code' => '2279', 'name' => 'Other Payable', 'type' => AccountType::Liability->value]);
        $set = $this->specialSet(['name' => 'APGM', 'code' => 'apgm_employee', 'rate' => '0.6100', 'type' => 'employee', 'account_id' => $first->id]);
        $this->employee->update(['deduction_rate_code' => 'SPECIAL']);

        $slip = app(PayrollCalculator::class)->calculate($this->employee->fresh(), 3, 2026);
        $slip->save();

        // After the calculation: the line is remapped and renamed, the
        // employee's code changes and the set is deleted.
        $set->rates()->where('code', 'apgm_employee')->update(['account_id' => $second->id, 'name' => 'Renamed']);
        $this->employee->update(['deduction_rate_code' => 'OTHER']);
        $set->delete();

        $posted = app(PostPayrollAction::class)->execute($slip->fresh());

        $this->assertTrue($posted->journalEntry->isBalanced());
        $line = $posted->journalEntry->lines->firstWhere('account_id', $first->id);
        $this->assertNotNull($line, 'the account mapped at calculation is credited');
        $this->assertSame($slip->deductions['apgm_employee'], (string) $line->credit);
        $this->assertStringContainsString('APGM', (string) $line->description);
        $this->assertNull($posted->journalEntry->lines->firstWhere('account_id', $second->id));
    }

    #[Test]
    public function it_refuses_to_credit_a_mapped_account_that_is_not_a_liability(): void
    {
        $account = Account::create(['organization_id' => $this->org->id, 'code' => '2274', 'name' => 'APGM Payable', 'type' => AccountType::Liability->value]);
        $this->specialSet(['name' => 'APGM', 'code' => 'apgm_employee', 'rate' => '0.6100', 'type' => 'employee', 'account_id' => $account->id]);
        $this->employee->update(['deduction_rate_code' => 'SPECIAL']);
        $slip = app(PayrollCalculator::class)->calculate($this->employee->fresh(), 3, 2026);
        $slip->save();
        $account->update(['type' => AccountType::Expense->value]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('apgm_employee');

        app(PostPayrollAction::class)->execute($slip->fresh());
    }

    #[Test]
    public function it_posts_an_imported_slip_carrying_a_non_numeric_marker(): void
    {
        // The shape the Airtable import writes: no snapshot, a `source` marker.
        $slip = SalarySlip::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'period_month' => 2,
            'period_year' => 2026,
            'gross_salary' => '6000.00',
            'net_salary' => '5682.00',
            'deductions' => [
                'avs_employee' => '318.00', 'avs_employer' => '318.00',
                'total_employee' => '318.00', 'total_employer' => '318.00',
                'net_salary' => '5682.00', 'source' => 'imported',
            ],
            'adjustments' => ['base_salary' => '6000.00', 'thirteenth_salary' => '0.00', 'unpaid_leave_days' => 0, 'unpaid_leave_amount' => '0.00', 'reimbursement_amount' => '0.00'],
        ]);

        $posted = app(PostPayrollAction::class)->execute($slip);

        $this->assertTrue($posted->journalEntry->isBalanced());
        $avs = Account::where('organization_id', $this->org->id)->where('code', '2270')->first();
        $this->assertSame('636.00', (string) $posted->journalEntry->lines->firstWhere('account_id', $avs->id)->credit);
    }

    #[Test]
    public function a_payroll_run_that_fails_to_post_one_slip_leaves_no_slip_and_no_entry(): void
    {
        $this->specialSet(['name' => 'AVS bis', 'code' => 'avs_bis_employee', 'rate' => '1.0000', 'type' => 'employee']);
        $this->employee->update(['deduction_rate_code' => 'SPECIAL']);
        // Sorted after Muster: posts second, and fails ("meal_" has no account).
        $failing = DeductionRateSet::create(['organization_id' => $this->org->id, 'code' => 'MEAL', 'title' => 'Meal', 'date_from' => '2026-01-01', 'date_to' => null]);
        $failing->rates()->create(['organization_id' => $this->org->id, 'name' => 'Repas', 'code' => 'meal_employee', 'rate' => '1.0000', 'type' => 'employee']);
        Employee::create([
            'organization_id' => $this->org->id, 'first_name' => 'Zoe', 'last_name' => 'Zimmer', 'entry_date' => '2025-01-01',
            'gross_salary' => '5000.00', 'is_active' => true, 'deduction_rate_code' => 'MEAL',
        ]);

        try {
            app(GeneratePayrollRunAction::class)->execute($this->org->id, 3, 2026, shouldPost: true);
            $this->fail('expected a DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('meal_employee', $e->getMessage());
        }

        $this->assertSame(0, SalarySlip::where('organization_id', $this->org->id)->count());
        $this->assertSame(0, JournalEntry::where('organization_id', $this->org->id)->count());
    }

    /** @param array<string, mixed> $extra */
    private function specialSet(array $extra): DeductionRateSet
    {
        $set = DeductionRateSet::create([
            'organization_id' => $this->org->id,
            'code' => 'SPECIAL',
            'title' => 'Spécial',
            'date_from' => '2026-01-01',
            'date_to' => null,
        ]);
        foreach ([
            ['name' => 'AVS', 'code' => 'avs_employee', 'rate' => '5.3000', 'type' => 'employee'],
            ['name' => 'AVS', 'code' => 'avs_employer', 'rate' => '5.3000', 'type' => 'employer'],
            $extra,
        ] as $line) {
            $set->rates()->create(['organization_id' => $this->org->id, ...$line]);
        }

        return $set;
    }
}

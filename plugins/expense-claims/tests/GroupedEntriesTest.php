<?php

namespace Plugins\ExpenseClaims\Tests;

require_once __DIR__.'/ExpenseClaimsTestCase.php';

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Services\JournalEntryReferences;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Payroll\Actions\GeneratePayrollRunAction;
use App\Domains\Payroll\Contracts\ReimbursementSourceInterface;
use App\Domains\Payroll\Models\SalarySlip;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\DebtRecord;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Models\Setting;
use Plugins\ExpenseClaims\Services\Accounts;
use Plugins\ExpenseClaims\Services\Claims;
use Plugins\ExpenseClaims\Services\Debts;
use Plugins\ExpenseClaims\Services\Journal;

/**
 * Grouped accounting entries (docs/DESIGN-expense-claims.md §8.4).
 */
class GroupedEntriesTest extends ExpenseClaimsTestCase
{
    private Person $anna;

    private Person $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = Person::create(['organization_id' => $this->org->id, 'name' => 'Anna Muster', 'employee_id' => $this->employee->id]);
        $this->owner = Person::create(['organization_id' => $this->org->id, 'name' => 'Owner', 'is_owner' => true]);
    }

    private function draft(Person $person, string $date, string $amount): Claim
    {
        return app(Claims::class)->saveDraft($this->org->id, [
            'person_id' => $person->id, 'date' => $date, 'title' => 'Repas', 'lines' => [['type' => 'meal', 'amount' => $amount]],
        ]);
    }

    /** @return array<string, string> account code => balance (debit - credit), posted entries only */
    private function balances(): array
    {
        return TransactionLine::query()
            ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'transaction_lines.journal_entry_id')
            ->where('accounts.organization_id', $this->org->id)
            ->where('journal_entries.is_posted', true)
            ->selectRaw('accounts.code, SUM(transaction_lines.debit) - SUM(transaction_lines.credit) AS balance')
            ->groupBy('accounts.code')
            ->pluck('balance', 'code')
            ->map(fn ($b) => number_format((float) $b, 2, '.', ''))
            ->all();
    }

    /** @return Collection<int, array{code: string, debit: string, credit: string}> */
    private function lines(string $entryId): Collection
    {
        return TransactionLine::query()->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
            ->where('journal_entry_id', $entryId)->orderBy('accounts.code')
            ->get(['accounts.code', 'debit', 'credit'])
            ->map(fn ($l): array => ['code' => (string) $l->code, 'debit' => (string) $l->debit, 'credit' => (string) $l->credit]);
    }

    #[Test]
    public function approving_a_selection_books_nothing_and_records_the_approver(): void
    {
        $a = $this->draft($this->anna, '2026-03-10', '10.00');
        $b = $this->draft($this->owner, '2026-04-20', '20.00');

        app(Claims::class)->approve([$a, $b], $this->user->id);

        $this->assertSame(0, JournalEntry::count());
        $this->assertSame($this->user->id, $b->fresh()->approved_by);
        $this->assertNotNull($a->fresh()->approved_at);
        $this->assertNull($a->fresh()->liability_account_code);
    }

    #[Test]
    public function paying_a_batch_books_the_cost_in_one_entry_per_person_on_the_payment_date(): void
    {
        Account::create(['organization_id' => $this->org->id, 'code' => '1000', 'name' => 'Caisse', 'type' => AccountType::Asset->value]);
        $claims = [$this->draft($this->anna, '2025-03-10', '10.00'), $this->draft($this->owner, '2026-03-20', '20.00'), $this->draft($this->anna, '2026-04-01', '5.00')];
        app(Claims::class)->approve($claims);

        app(Claims::class)->pay($claims, '2026-04-30', '1000');

        $this->assertSame(2, JournalEntry::count());
        $anna = JournalEntry::where('reference', 'EC-PAY-20260430-ANN')->sole();
        $this->assertSame('2026-04-30', $anna->date->toDateString()); // the cost lands in the month of payment
        $this->assertEquals([
            ['code' => '1000', 'debit' => '0.00', 'credit' => '15.00'],
            ['code' => '6640', 'debit' => '15.00', 'credit' => '0.00'],
        ], $this->lines($anna->id)->all());
        $this->assertSame($anna->id, $claims[2]->fresh()->settlement_entry_id);

        // Cancelling reopens that person's payment only.
        $this->assertSame(2, app(Claims::class)->cancelPayment($claims[0]->fresh()));
        $this->assertSame(Claim::STATUS_APPROVED, $claims[2]->fresh()->status);
        $this->assertSame(Claim::STATUS_SETTLED, $claims[1]->fresh()->status);
        $balances = $this->balances();
        $this->assertSame('20.00', $balances['6640']);
        $this->assertSame('-20.00', $balances['1000']);
    }

    #[Test]
    public function passing_a_batch_to_debt_writes_one_entry_and_one_record_per_person(): void
    {
        // Repayment deferred beyond 12 months by agreement: long-term debt account (D47).
        Setting::forOrganization($this->org->id)->update(['owner_debt_code' => '2560']);
        $owner2 = Person::create(['organization_id' => $this->org->id, 'name' => 'Second owner', 'is_owner' => true]);
        $claims = [$this->draft($this->owner, '2025-05-10', '10.00'), $this->draft($owner2, '2025-06-10', '20.00'), $this->draft($this->anna, '2025-07-10', '5.00'), $this->draft($this->anna, '2025-08-10', '6.00')];
        app(Claims::class)->approve($claims);

        $records = app(Debts::class)->convert($claims, '2025-12-31');

        $this->assertCount(3, $records);
        $this->assertSame(3, JournalEntry::count());
        $this->assertSame(3, DebtRecord::query()->distinct()->count('journal_entry_id'));
        $anna = DebtRecord::where('person_id', $this->anna->id)->sole();
        $this->assertSame('11.00', (string) $anna->amount);
        $this->assertEquals([
            ['code' => '2210', 'debit' => '0.00', 'credit' => '11.00'], // staff debt stays on 2210 (D1)
            ['code' => '6640', 'debit' => '11.00', 'credit' => '0.00'],
        ], $this->lines((string) $anna->journal_entry_id)->all());
        $balances = $this->balances();
        $this->assertSame('41.00', $balances['6640']);
        $this->assertSame('-30.00', $balances['2560']);

        // Cancelling one owner's record undoes only their entry.
        app(Debts::class)->cancel(DebtRecord::where('person_id', $owner2->id)->sole());
        $balances = $this->balances();
        $this->assertSame('-10.00', $balances['2560']);
        $this->assertSame('21.00', $balances['6640']);
        $this->assertSame(Claim::STATUS_APPROVED, $claims[1]->fresh()->status);
        $this->assertSame(Claim::STATUS_DEBT, $claims[0]->fresh()->status);
    }

    #[Test]
    public function claims_dated_after_the_payment_or_debt_date_are_refused(): void
    {
        $claim = $this->draft($this->owner, '2026-01-10', '10.00');
        app(Claims::class)->approve($claim);

        try {
            app(Claims::class)->pay($claim, '2026-01-01');
            $this->fail('paid before its date');
        } catch (\DomainException) {
        }
        $this->expectException(\DomainException::class);
        app(Debts::class)->convert([$claim], '2025-12-31');
    }

    #[Test]
    public function the_salary_entry_books_the_cost_per_account_without_accrual(): void
    {
        $claims = [$this->draft($this->anna, '2026-03-10', '10.00'), $this->draft($this->anna, '2026-03-12', '15.00')];
        app(Claims::class)->approve($claims);

        /** @var SalarySlip $slip */
        $slip = app(GeneratePayrollRunAction::class)->execute($this->org->id, 3, 2026, true, [], [[
            'employee_id' => $this->employee->id,
            'reimbursement_item_ids' => array_map(fn (Claim $c): string => 'claim:'.$c->id, $claims),
        ]])->first();

        $lines = $this->lines((string) $slip->fresh()->journal_entry_id);
        $this->assertEquals([['code' => '6640', 'debit' => '25.00', 'credit' => '0.00']], $lines->where('code', '6640')->values()->all());
        $this->assertCount(0, $lines->where('code', '2210'));
        $this->assertSame('25.00', $this->balances()['6640']); // counted once, in the salary month
    }

    #[Test]
    public function the_salary_cannot_pay_a_claim_dated_after_its_month(): void
    {
        $claim = $this->draft($this->anna, '2026-04-02', '10.00');
        app(Claims::class)->approve($claim);

        $adjustment = [['employee_id' => $this->employee->id, 'reimbursement_item_ids' => ['claim:'.$claim->id]]];
        try {
            app(GeneratePayrollRunAction::class)->preview($this->org->id, 3, 2026, [], $adjustment);
            $this->fail('The preview accepted a claim dated after the month.');
        } catch (\DomainException) {
        }
        $this->assertSame(0, SalarySlip::count());

        $this->expectException(\DomainException::class);
        app(GeneratePayrollRunAction::class)->execute($this->org->id, 3, 2026, true, [], [[
            'employee_id' => $this->employee->id,
            'reimbursement_item_ids' => ['claim:'.$claim->id],
        ]]);
    }

    #[Test]
    public function a_claim_booked_before_the_change_is_paid_from_its_liability(): void
    {
        // Migrated without --book: its cost is already on 2210 in the migrated ledger.
        $claim = $this->draft($this->anna, '2026-03-10', '10.00');
        app(Claims::class)->approve($claim);
        $claim->forceFill(['liability_account_code' => '2210', 'source' => 'airtable'])->save();

        try {
            app(Claims::class)->unapprove($claim->fresh());
            $this->fail('a claim booked before Gäld went back to draft');
        } catch (\DomainException) {
        }

        app(Claims::class)->pay($claim->fresh(), '2026-03-31');
        $this->assertEquals([
            ['code' => '1020', 'debit' => '0.00', 'credit' => '10.00'],
            ['code' => '2210', 'debit' => '10.00', 'credit' => '0.00'],
        ], $this->lines((string) $claim->fresh()->settlement_entry_id)->all());
    }

    #[Test]
    public function a_legacy_approval_entry_is_undone_when_the_approval_is_cancelled(): void
    {
        $claim = $this->draft($this->anna, '2026-03-10', '10.00');
        app(Claims::class)->approve($claim);
        $entry = app(Journal::class)->book($this->org->id, new JournalEntryData('2026-03-10', 'EC-0001', 'Legacy approval', [
            new JournalLineData(app(Accounts::class)->id($this->org->id, '6640'), '10.00', '0'),
            new JournalLineData(app(Accounts::class)->id($this->org->id, '2210'), '0', '10.00'),
        ]));
        $claim->forceFill(['liability_account_code' => '2210', 'journal_entry_id' => $entry->id])->save();

        app(Claims::class)->unapprove($claim->fresh());

        $this->assertSame(Claim::STATUS_DRAFT, $claim->fresh()->status);
        $this->assertSame([], array_filter($this->balances(), fn (string $b): bool => $b !== '0.00'));
    }

    #[Test]
    public function repaying_a_draft_debt_writes_a_draft_repayment_owned_by_the_repayment_and_not_through_payroll(): void
    {
        $claim = $this->draft($this->owner, '2025-05-10', '30.00');
        app(Claims::class)->approve($claim);
        $debt = app(Debts::class)->convert([$claim], '2025-12-31', null, true)->sole();

        $repayment = app(Debts::class)->repay($debt, '2026-02-01', '10.00');

        $entry = JournalEntry::findOrFail($repayment->journal_entry_id);
        $this->assertFalse($entry->is_posted); // same state as the debt's draft entry
        $this->assertSame([], array_filter($this->balances(), fn (string $b): bool => $b !== '0.00')); // nothing posted yet
        $owner = app(JournalEntryReferences::class)->forMany([$entry->id])[$entry->id] ?? null;
        $this->assertNotNull($owner); // owned via ec_debt_repayments: locked in the journal

        // A draft debt is not offered for repayment with a salary.
        $this->assertSame([], app(ReimbursementSourceInterface::class)->openItems($this->org->id, (string) $this->employee->id));

        // Posting the debt entry posts its draft repayments with it; later repayments are posted at once.
        app(LedgerService::class)->postDraft(JournalEntry::findOrFail($debt->journal_entry_id));
        $this->assertTrue($entry->fresh()->is_posted);
        $this->assertSame('-20.00', $this->balances()[$debt->account_code] ?? null); // debt 30 less the repayment 10, in the ledger
        $second = app(Debts::class)->repay($debt->fresh(), '2026-03-01', '5.00');
        $this->assertTrue(JournalEntry::findOrFail($second->journal_entry_id)->is_posted);
        $this->assertSame('15.00', $debt->fresh()->load('repayments')->remaining());
    }

    #[Test]
    public function a_draft_repayment_is_neither_dated_before_its_debt_nor_posted_before_it(): void
    {
        $claim = $this->draft($this->owner, '2025-05-10', '30.00');
        app(Claims::class)->approve($claim);
        $debt = app(Debts::class)->convert([$claim], '2025-12-31', null, true)->sole();

        try {
            app(Debts::class)->repay($debt, '2025-12-30', '10.00');
            $this->fail('Repaid before the debt date.');
        } catch (\DomainException) {
        }
        $repayment = app(Debts::class)->repay($debt->fresh(), '2026-02-01', '10.00');
        try {
            app(LedgerService::class)->postDraft(JournalEntry::findOrFail($repayment->journal_entry_id));
            $this->fail('Repayment posted while its debt is a draft.');
        } catch (\DomainException) {
        }
        $this->assertFalse(JournalEntry::findOrFail($repayment->journal_entry_id)->is_posted);
        $this->assertSame([], array_filter($this->balances(), fn (string $b): bool => $b !== '0.00'));
    }

    #[Test]
    public function a_claim_whose_earlier_booking_is_a_draft_is_not_paid_until_it_is_posted(): void
    {
        $claim = $this->draft($this->anna, '2026-03-10', '10.00');
        app(Claims::class)->approve($claim);
        $entry = app(Journal::class)->book($this->org->id, new JournalEntryData('2026-03-10', 'EC-0001', 'Legacy approval', [
            new JournalLineData(app(Accounts::class)->id($this->org->id, '6640'), '10.00', '0'),
            new JournalLineData(app(Accounts::class)->id($this->org->id, '2210'), '0', '10.00'),
        ]), true);
        $claim->forceFill(['liability_account_code' => '2210', 'journal_entry_id' => $entry->id])->save();

        $this->assertSame([], app(ReimbursementSourceInterface::class)->openItems($this->org->id, (string) $this->employee->id));
        try {
            app(Claims::class)->pay($claim->fresh(), '2026-03-31');
            $this->fail('Paid against a draft booking.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('EC-0001', $e->getMessage());
        }
        $this->expectException(\DomainException::class);
        app(Debts::class)->convert([$claim->fresh()], '2026-12-31');
    }

    #[Test]
    public function a_draft_debt_entry_is_deleted_when_its_record_is_cancelled(): void
    {
        $claims = [$this->draft($this->owner, '2025-05-10', '10.00'), $this->draft($this->anna, '2025-06-10', '20.00')];
        app(Claims::class)->approve($claims);
        app(Debts::class)->convert($claims, '2025-12-31', null, true);
        $this->assertSame(2, JournalEntry::where('is_posted', false)->count());

        app(Debts::class)->cancel(DebtRecord::where('person_id', $this->owner->id)->sole());

        $this->assertSame(1, JournalEntry::count()); // the owner's draft is gone, Anna's stays
        $this->assertSame(JournalEntry::sole()->id, DebtRecord::sole()->journal_entry_id);
    }
}

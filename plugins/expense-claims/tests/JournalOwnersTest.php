<?php

namespace Plugins\ExpenseClaims\Tests;

require_once __DIR__.'/ExpenseClaimsTestCase.php';

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\JournalEntryReferences;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Accounting\Support\SystemReferencePrefixes;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\DebtRecord;
use Plugins\ExpenseClaims\Models\DebtRepayment;
use Plugins\ExpenseClaims\Models\Person;

/**
 * Journal entries of claims and debts are changed through the plugin, not in the journal.
 */
class JournalOwnersTest extends ExpenseClaimsTestCase
{
    #[Test]
    public function the_plugin_reference_prefix_cannot_be_typed_in_the_journal(): void
    {
        $this->assertSame('EC-', app(SystemReferencePrefixes::class)->matching('EC-PAY-20260310-AM'));
        $this->assertNull(app(SystemReferencePrefixes::class)->matching('ECO-1'));
    }

    #[Test]
    public function claim_and_debt_entries_name_their_owner_and_are_locked_in_the_journal(): void
    {
        $person = Person::create(['organization_id' => $this->org->id, 'name' => 'Anna Muster']);
        $shared = $this->draftEntry('EC-GROUP');
        $paid = $this->draftEntry('EC-PAY');
        $debtEntry = $this->draftEntry('EC-DEBT');
        $repaymentEntry = $this->draftEntry('EC-REPAY');
        $free = $this->draftEntry('FREE');

        foreach ([1, 2] as $number) {
            Claim::create([
                'organization_id' => $this->org->id, 'number' => $number, 'person_id' => $person->id, 'date' => '2026-03-10',
                'title' => 'Trip', 'status' => 'approved', 'total' => '10.00', 'journal_entry_id' => $shared->id,
                'settlement_entry_id' => $number === 2 ? $paid->id : null,
            ]);
        }
        $debt = DebtRecord::create([
            'organization_id' => $this->org->id, 'person_id' => $person->id, 'date' => '2025-12-31',
            'amount' => '20.00', 'account_code' => '2210', 'journal_entry_id' => $debtEntry->id,
        ]);
        DebtRepayment::create(['debt_record_id' => $debt->id, 'date' => '2026-02-01', 'amount' => '5.00', 'via' => 'bank', 'journal_entry_id' => $repaymentEntry->id]);

        $owners = app(JournalEntryReferences::class)->forMany([$shared->id, $paid->id, $debtEntry->id, $repaymentEntry->id, $free->id]);

        $this->assertSame('Expense claims EC-0001, EC-0002', $owners[$shared->id]->label);
        $this->assertSame('Expense claim EC-0002', $owners[$paid->id]->label);
        $this->assertSame('Expense debt record of Anna Muster (31.12.2025)', $owners[$debtEntry->id]->label);
        $this->assertSame('Repayment of an expense debt (01.02.2026)', $owners[$repaymentEntry->id]->label);
        $this->assertArrayNotHasKey($free->id, $owners);

        $denied = Gate::forUser($this->user)->inspect('delete', $shared);
        $this->assertTrue($denied->denied());
        $this->assertStringContainsString('EC-0001', (string) $denied->message());
        $this->assertTrue(Gate::forUser($this->user)->allows('delete', $free));
        $this->assertTrue(Gate::forUser($this->user)->allows('post', $shared));
    }

    private function draftEntry(string $reference): JournalEntry
    {
        $bank = Account::where('code', '1020')->firstOrFail();
        $cost = Account::where('code', '6530')->firstOrFail();

        return app(LedgerService::class)->createDraft($this->org->id, new JournalEntryData(
            date: '2026-03-10',
            reference: $reference,
            description: 'Test',
            lines: [
                new JournalLineData(accountId: (string) $cost->id, debit: '10.00', credit: '0'),
                new JournalLineData(accountId: (string) $bank->id, debit: '0', credit: '10.00'),
            ],
        ));
    }
}

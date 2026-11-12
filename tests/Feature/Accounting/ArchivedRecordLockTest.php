<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Policies\JournalEntryPolicy;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Expenses\Policies\ExpensePolicy;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Policies\InvoicePolicy;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Policies\SalarySlipPolicy;
use App\Exceptions\ArchivedRecordException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Phase 4: Once `archived_at` is persisted, the LocksArchivedRecord observer
 * blocks every subsequent update or delete (Swiss CO 10-year immutability).
 *
 * Policies also refuse `update`/`delete` so the UI can hide actions.
 */
class ArchivedRecordLockTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_invoice_lock_blocks_update_and_delete(): void
    {
        $invoice = Invoice::factory()->for($this->organization)->create();
        // Setting archived_at for the first time is allowed.
        $invoice->update(['archived_at' => now()]);
        $invoice->refresh();
        $this->assertNotNull($invoice->archived_at);

        $this->expectException(ArchivedRecordException::class);
        $invoice->update(['notes' => 'cannot change']);
    }

    public function test_invoice_lock_blocks_delete(): void
    {
        $invoice = Invoice::factory()->for($this->organization)->create();
        $invoice->update(['archived_at' => now()]);
        $invoice->refresh();

        $this->expectException(ArchivedRecordException::class);
        $invoice->delete();
    }

    public function test_expense_lock_blocks_update(): void
    {
        $expense = Expense::factory()->for($this->organization)->create();
        $expense->update(['archived_at' => now()]);
        $expense->refresh();

        $this->expectException(ArchivedRecordException::class);
        $expense->update(['description' => 'cannot change']);
    }

    public function test_journal_entry_lock_blocks_update(): void
    {
        $entry = JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => now()->toDateString(),
            'reference' => 'JE-LOCK-1',
            'description' => 'archived entry',
            'is_posted' => false,
            'archived_at' => now(),
        ]);
        $entry->refresh();

        $this->expectException(ArchivedRecordException::class);
        $entry->update(['description' => 'cannot change']);
    }

    public function test_archived_entry_lines_cannot_be_updated(): void
    {
        [, $line] = $this->archivedEntryWithLine();

        $this->expectException(ArchivedRecordException::class);
        $line->update(['debit' => '999.00']);
    }

    public function test_archived_entry_lines_cannot_be_deleted(): void
    {
        [, $line] = $this->archivedEntryWithLine();

        $this->expectException(ArchivedRecordException::class);
        $line->delete();
    }

    public function test_lines_cannot_be_added_to_an_archived_entry(): void
    {
        [$entry, $line] = $this->archivedEntryWithLine();

        $this->expectException(ArchivedRecordException::class);
        $entry->lines()->create([
            'account_id' => $line->account_id,
            'debit' => '0',
            'credit' => '1.00',
        ]);
    }

    public function test_a_line_cannot_be_moved_onto_an_archived_entry(): void
    {
        [$archived] = $this->archivedEntryWithLine();
        $open = JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => now()->toDateString(),
            'reference' => 'JE-LINE-MOVE',
            'description' => 'open entry',
            'is_posted' => false,
        ]);
        $line = $open->lines()->create(['account_id' => Account::query()->value('id'), 'debit' => '5.00', 'credit' => '0']);

        $this->expectException(ArchivedRecordException::class);
        $line->update(['journal_entry_id' => $archived->id]);
    }

    public function test_lines_of_a_non_archived_entry_remain_editable(): void
    {
        $account = $this->lockTestAccount();
        $entry = JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => now()->toDateString(),
            'reference' => 'JE-LINE-OPEN',
            'description' => 'open entry',
            'is_posted' => false,
        ]);
        $line = $entry->lines()->create(['account_id' => $account->id, 'debit' => '10.00', 'credit' => '0']);

        $line->update(['debit' => '20.00']);

        $this->assertSame('20.00', (string) $line->fresh()?->debit);
    }

    /** @return array{0: JournalEntry, 1: TransactionLine} */
    private function archivedEntryWithLine(): array
    {
        $account = $this->lockTestAccount();
        $entry = JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => now()->toDateString(),
            'reference' => 'JE-LINE-LOCK',
            'description' => 'archived entry',
            'is_posted' => true,
        ]);
        $line = $entry->lines()->create(['account_id' => $account->id, 'debit' => '10.00', 'credit' => '0']);
        $entry->update(['archived_at' => now()]);

        return [$entry->refresh(), $line->refresh()];
    }

    private function lockTestAccount(): Account
    {
        return Account::create([
            'organization_id' => $this->organization->id,
            'code' => '1020',
            'name' => 'Bank',
            'type' => AccountType::Asset->value,
        ]);
    }

    public function test_salary_slip_lock_blocks_update(): void
    {
        $employee = Employee::factory()->for($this->organization)->create();
        $slip = SalarySlip::create([
            'organization_id' => $this->organization->id,
            'employee_id' => $employee->id,
            'period_month' => 1,
            'period_year' => 2025,
            'gross_salary' => '6000.00',
            'net_salary' => '5200.00',
            'deductions' => [],
            'archived_at' => now(),
        ]);
        $slip->refresh();

        $this->expectException(ArchivedRecordException::class);
        $slip->update(['gross_salary' => '7000.00']);
    }

    public function test_non_archived_records_remain_editable(): void
    {
        $invoice = Invoice::factory()->for($this->organization)->create();
        $invoice->update(['notes' => 'fine']);
        $this->assertSame('fine', $invoice->fresh()->notes);
    }

    public function test_policies_refuse_update_and_delete_on_archived_record(): void
    {
        $invoice = Invoice::factory()->for($this->organization)->create(['archived_at' => now()]);
        $expense = Expense::factory()->for($this->organization)->create(['archived_at' => now()]);
        $entry = JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => now()->toDateString(),
            'reference' => 'JE-POL',
            'description' => 'x',
            'is_posted' => false,
            'archived_at' => now(),
        ]);
        $employee = Employee::factory()->for($this->organization)->create();
        $slip = SalarySlip::create([
            'organization_id' => $this->organization->id,
            'employee_id' => $employee->id,
            'period_month' => 1,
            'period_year' => 2025,
            'gross_salary' => '6000.00',
            'net_salary' => '5200.00',
            'deductions' => [],
            'archived_at' => now(),
        ]);

        $this->assertFalse((new InvoicePolicy)->update($this->user, $invoice));
        $this->assertFalse((new InvoicePolicy)->delete($this->user, $invoice));
        $this->assertFalse((new ExpensePolicy)->update($this->user, $expense));
        $this->assertFalse((new ExpensePolicy)->delete($this->user, $expense));
        $this->assertFalse((new JournalEntryPolicy)->update($this->user, $entry));
        $this->assertFalse((new JournalEntryPolicy)->delete($this->user, $entry));
        $this->assertFalse((new SalarySlipPolicy)->update($this->user, $slip));
        $this->assertFalse((new SalarySlipPolicy)->delete($this->user, $slip));
    }
}

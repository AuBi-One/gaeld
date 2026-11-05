<?php

namespace Tests\Feature\Expenses;

use App\Domains\Expenses\Actions\ApproveExpenseAction;
use App\Domains\Expenses\Actions\CreateExpenseAction;
use App\Domains\Expenses\Actions\UnapproveExpenseAction;
use App\Domains\Expenses\DTOs\CreateExpenseData;
use App\Domains\Expenses\Enums\ExpenseStatus;
use App\Domains\Expenses\Exceptions\InvalidExpenseStateException;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class ExpenseListActionsTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    private function createExpense(string $description): Expense
    {
        return (new CreateExpenseAction)->execute(CreateExpenseData::fromArray([
            'organization_id' => $this->org->id,
            'category' => 'Software',
            'description' => $description,
            'amount' => 100.00,
            'date' => '2026-03-16',
            'vendor' => 'Vendor text',
        ]));
    }

    /** @return array<string, array<string, bool>> */
    private function rowPermissions(User $user): array
    {
        $rows = [];
        $this->actingAs($user)->withSession(['current_organization_id' => $this->organization->id])
            ->get('/expenses')
            ->assertOk()
            ->assertInertia(function ($page) use (&$rows) {
                foreach ($page->toArray()['props']['expenses']['data'] as $row) {
                    $rows[$row['description']] = $row['can'];
                }

                return $page;
            });

        return $rows;
    }

    public function test_rows_offer_approve_and_post_by_status(): void
    {
        $this->createExpense('Pending one');
        app(ApproveExpenseAction::class)->execute($this->createExpense('Approved one'));

        $rows = $this->rowPermissions($this->user);

        $this->assertSame(['update' => true, 'delete' => true, 'approve' => true, 'post' => false], $rows['Pending one']);
        $this->assertSame(['update' => true, 'delete' => true, 'approve' => false, 'post' => true], $rows['Approved one']);
    }

    public function test_a_viewer_gets_no_row_actions(): void
    {
        $this->createExpense('Pending one');
        $viewer = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        $this->assignOrganizationRole($viewer, $this->organization, 'viewer');

        $rows = $this->rowPermissions($viewer);

        $this->assertSame(['update' => false, 'delete' => false, 'approve' => false, 'post' => false], $rows['Pending one']);
        $this->actingAs($viewer)->withSession(['current_organization_id' => $this->organization->id])
            ->post('/expenses/'.Expense::firstOrFail()->id.'/approve')
            ->assertForbidden();
    }

    public function test_approving_from_the_list_returns_to_the_list(): void
    {
        $expense = $this->createExpense('Pending one');

        $this->actAsOrg()
            ->from('/expenses?page=1')
            ->post("/expenses/{$expense->id}/approve")
            ->assertRedirect('/expenses?page=1');

        $this->assertSame(ExpenseStatus::Approved, $expense->fresh()->status);
    }

    public function test_a_stale_second_approval_is_refused(): void
    {
        $expense = $this->createExpense('Pending one');
        $stale = Expense::findOrFail($expense->id);
        app(ApproveExpenseAction::class)->execute($expense);

        $this->expectException(InvalidExpenseStateException::class);
        app(ApproveExpenseAction::class)->execute($stale);
    }

    public function test_an_expense_deleted_in_another_tab_cannot_be_approved(): void
    {
        $expense = $this->createExpense('Pending one');
        $stale = Expense::findOrFail($expense->id);
        $expense->delete();

        $this->expectException(InvalidExpenseStateException::class);
        app(ApproveExpenseAction::class)->execute($stale);
    }

    public function test_a_stale_unapprove_does_not_reset_a_posted_expense(): void
    {
        $expense = app(ApproveExpenseAction::class)->execute($this->createExpense('Approved one'));
        $stale = Expense::findOrFail($expense->id);
        $expense->update(['status' => ExpenseStatus::Posted]);

        try {
            app(UnapproveExpenseAction::class)->execute($stale);
            $this->fail('Unapprove of a posted expense must be refused.');
        } catch (InvalidExpenseStateException) {
        }
        $this->assertSame(ExpenseStatus::Posted, $expense->fresh()->status);
    }
}

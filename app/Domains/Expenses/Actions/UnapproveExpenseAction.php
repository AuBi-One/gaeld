<?php

namespace App\Domains\Expenses\Actions;

use App\Domains\Expenses\Enums\ExpenseStatus;
use App\Domains\Expenses\Exceptions\InvalidExpenseStateException;
use App\Domains\Expenses\Models\Expense;
use Illuminate\Support\Facades\DB;

/**
 * Reverts an approved expense back to pending, e.g. when an approval was
 * made by mistake. No ledger effect: posting only happens once approved
 * expenses are explicitly posted, so there is nothing to reverse here.
 */
class UnapproveExpenseAction
{
    public function execute(Expense $expense): Expense
    {
        // Locked: an unapprove waiting on a concurrent post must not reset a posted expense.
        return DB::transaction(function () use ($expense): Expense {
            $locked = $expense->lockForWorkflow();
            if (! $locked->status->canTransitionTo(ExpenseStatus::Pending)) {
                throw new InvalidExpenseStateException('Only approved expenses can be reverted to pending.');
            }

            $locked->update(['status' => ExpenseStatus::Pending]);
            $expense->setRawAttributes($locked->getAttributes(), true);

            return $locked->fresh() ?? $locked;
        });
    }
}

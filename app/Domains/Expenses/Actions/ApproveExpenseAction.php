<?php

namespace App\Domains\Expenses\Actions;

use App\Domains\Expenses\Enums\ExpenseStatus;
use App\Domains\Expenses\Exceptions\InvalidExpenseStateException;
use App\Domains\Expenses\Models\Expense;
use Illuminate\Support\Facades\DB;

/**
 * Transitions an expense from pending to approved status.
 */
class ApproveExpenseAction
{
    public function execute(Expense $expense): Expense
    {
        return DB::transaction(function () use ($expense): Expense {
            $locked = $expense->lockForWorkflow();
            if (! $locked->status->canTransitionTo(ExpenseStatus::Approved)) {
                throw new InvalidExpenseStateException('Only pending expenses can be approved.');
            }

            $locked->update(['status' => ExpenseStatus::Approved]);
            $expense->setRawAttributes($locked->getAttributes(), true);

            return $locked->fresh() ?? $locked;
        });
    }
}

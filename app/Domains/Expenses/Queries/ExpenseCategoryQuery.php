<?php

namespace App\Domains\Expenses\Queries;

use App\Domains\Expenses\Models\ExpenseCategory;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class ExpenseCategoryQuery
{
    /**
     * @return Collection<int, ExpenseCategory>
     */
    public static function forSelect(): Collection
    {
        $orgId = app(CurrentOrganization::class)->id();

        // The label follows each user's language, so it is set after the cache, not inside it.
        return Cache::tags(["org:{$orgId}:reference"])->remember(
            "expense_categories_select:v2:{$orgId}",
            3600,
            fn () => ExpenseCategory::active()
                ->with('defaultExpenseAccount')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'translation_key', 'is_active', 'is_system', 'default_expense_account_id'])
        )->each(fn (ExpenseCategory $category) => $category->setAttribute('label', $category->displayName()));
    }

    /**
     * @return Collection<int, ExpenseCategory>
     */
    public static function all(): Collection
    {
        $orgId = app(CurrentOrganization::class)->id();

        return Cache::tags(["org:{$orgId}:reference"])->remember(
            "expense_categories_all:v2:{$orgId}",
            3600,
            fn () => ExpenseCategory::with('defaultExpenseAccount')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        )->each(fn (ExpenseCategory $category) => $category->setAttribute('label', $category->displayName()));
    }
}

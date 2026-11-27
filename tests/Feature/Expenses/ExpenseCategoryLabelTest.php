<?php

namespace Tests\Feature\Expenses;

use App\Domains\Expenses\Models\ExpenseCategory;
use App\Domains\Expenses\Queries\ExpenseCategoryQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class ExpenseCategoryLabelTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_a_system_category_is_labelled_in_the_users_language(): void
    {
        $this->actAsOrg();
        app()->setLocale('fr');
        ExpenseCategory::create([
            'organization_id' => $this->organization->id, 'name' => 'Office Supplies',
            'code' => 'office_supplies', 'translation_key' => 'cat_office_supplies', 'is_active' => true,
        ]);
        ExpenseCategory::create(['organization_id' => $this->organization->id, 'name' => 'Formation', 'is_active' => true]);

        $labels = collect(ExpenseCategoryQuery::forSelect())->pluck('label', 'name');
        $this->assertSame('Fournitures de bureau', $labels['Office Supplies']);
        $this->assertSame('Formation', $labels['Formation']);
        $this->assertSame('Fournitures de bureau', collect(ExpenseCategoryQuery::all())->pluck('label', 'name')['Office Supplies']);

        // Served from the cache to a user of another language: labelled in theirs.
        app()->setLocale('de');
        $this->assertSame('Büromaterial', collect(ExpenseCategoryQuery::forSelect())->pluck('label', 'name')['Office Supplies']);
        $this->assertSame('Büromaterial', collect(ExpenseCategoryQuery::all())->pluck('label', 'name')['Office Supplies']);
    }
}

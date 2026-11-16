<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The quick-create contact form stored category codes (travel, office, …) as a contact's
 * default expense category, while expenses and the contact form use the category name.
 * Replace such codes with the name of the organisation's category of that code.
 */
return new class extends Migration
{
    private const CODES = [
        'office' => 'office_supplies',
        'travel' => 'travel',
        'software' => 'software',
        'utilities' => 'utilities',
        'marketing' => 'marketing',
        'professional_services' => 'professional_services',
        'other' => 'other',
    ];

    public function up(): void
    {
        foreach (self::CODES as $stored => $code) {
            $names = DB::table('expense_categories')->where('code', $code)->pluck('name', 'organization_id');
            foreach ($names as $organizationId => $name) {
                DB::table('contacts')
                    ->where('organization_id', $organizationId)
                    ->where('default_expense_category', $stored)
                    ->update(['default_expense_category' => $name]);
            }
        }
    }

    public function down(): void
    {
        // The codes are not restored: the names are what the forms expect.
    }
};

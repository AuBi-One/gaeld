<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The account invoices book their revenue to, per organisation (null = the
 * default services account, AccountCode::REVENUE). Organisations that booked
 * invoices to 3000 before keep doing so — no account is renamed and no revenue
 * moves to another account in the middle of a fiscal year. Their marker is the
 * system flag on 3000, which every chart template and ensureSystemAccounts()
 * set there until the services account moved to 3400.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('default_revenue_account_code', 10)->nullable();
        });

        DB::table('organizations')
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('accounts')
                ->whereColumn('accounts.organization_id', 'organizations.id')
                ->where('accounts.code', '3000')
                ->where('accounts.is_system', true))
            ->update(['default_revenue_account_code' => '3000']);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('default_revenue_account_code');
        });
    }
};

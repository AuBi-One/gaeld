<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional name of the holder of the contact's bank account, for when it differs from the
 * contact name (trading name vs. registered name). Payment files use it as the creditor
 * name; when empty they fall back to the contact name. 70 = the pain.001 Nm limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('account_holder', 70)->nullable()->after('bic');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('account_holder');
        });
    }
};

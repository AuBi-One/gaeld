<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the scheduled job sends payment reminders for an invoice. On by
 * default (existing invoices keep being reminded); a manual reminder from
 * the invoice page is always possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('auto_reminders')->default(true)->after('last_reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('auto_reminders');
        });
    }
};

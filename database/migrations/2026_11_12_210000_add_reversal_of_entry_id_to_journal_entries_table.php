<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reversal names the entry it reverses (LedgerService::reverseEntry and the
 * guided correction set it). Older reversals keep only their `REV-` reference
 * and are still found by it. At most one entry reverses a given original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->uuid('reversal_of_entry_id')->nullable()->after('is_posted');
            // Null on delete: a purge (migration re-run) removes originals and
            // reversals in one go; the link must not block it.
            $table->foreign('reversal_of_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->unique('reversal_of_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['reversal_of_entry_id']);
            $table->dropUnique(['reversal_of_entry_id']);
            $table->dropColumn('reversal_of_entry_id');
        });
    }
};

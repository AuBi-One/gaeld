<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A guided correction of a posted journal entry: the original stays as it is
 * (D70), a reversal and a replacement are prepared as drafts and posted (or
 * discarded) together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('original_journal_entry_id')->unique();
            $table->uuid('reversal_journal_entry_id')->unique();
            $table->uuid('replacement_journal_entry_id')->unique();
            $table->string('status', 20)->default('draft');
            $table->text('reason');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            // Posted entries are never deleted by the application; a purge of the
            // organisation's journal (migration re-run) takes the corrections with it.
            $table->foreign('original_journal_entry_id')->references('id')->on('journal_entries')->cascadeOnDelete();
            $table->foreign('reversal_journal_entry_id')->references('id')->on('journal_entries')->cascadeOnDelete();
            $table->foreign('replacement_journal_entry_id')->references('id')->on('journal_entries')->cascadeOnDelete();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_corrections');
    }
};

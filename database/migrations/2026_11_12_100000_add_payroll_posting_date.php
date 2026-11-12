<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A salary slip carries the date its entry is posted on (the payday), and an
 * organization may name its usual payday (day of the month). Both optional:
 * without them a slip posts on the last day of its month, as before.
 *
 * Idea from Ideall-Engineering/Gaeld 6abd6364.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_slips', function (Blueprint $table): void {
            $table->date('posting_date')->nullable();
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('payroll_payday')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salary_slips', function (Blueprint $table): void {
            $table->dropColumn('posting_date');
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('payroll_payday');
        });
    }
};

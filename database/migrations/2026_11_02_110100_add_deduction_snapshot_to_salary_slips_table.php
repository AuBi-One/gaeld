<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rate set and its lines (name, side, rate, credited account, certificate
 * box) as they were when the slip was calculated, so posting, the slip page,
 * the PDFs and the certificate never depend on later changes to the sets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_slips', function (Blueprint $table) {
            $table->json('deduction_snapshot')->nullable()->after('employee_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('salary_slips', function (Blueprint $table) {
            $table->dropColumn('deduction_snapshot');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employee's date of birth, printed on the salary certificate (Form 11
 * header) and required before one is issued. Stored encrypted (text column).
 *
 * Idea from Ideall-Engineering/Gaeld 0c63f233.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            // Y-m-d, encrypted at rest (cast `encrypted`) like ahv_number and iban.
            $table->text('date_of_birth')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('date_of_birth');
        });
    }
};

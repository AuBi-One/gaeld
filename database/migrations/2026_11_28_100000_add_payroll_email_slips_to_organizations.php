<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether posting a salary slip e-mails it to the employee. Off by default: the organisation
 * decides when its mail is ready for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('payroll_email_slips')->default(false)->after('payroll_payday');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('payroll_email_slips');
        });
    }
};

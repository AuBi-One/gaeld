<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap between two automatically numbered invoices: the next number is the
 * highest number of the year plus this step (1 = consecutive, the default).
 * Credit notes keep consecutive numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedSmallInteger('invoice_number_step')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('invoice_number_step');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap between two offer numbers: the next number is the highest number of the
 * year plus this step (1 = consecutive, the default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('of_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('number_step')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('of_settings', function (Blueprint $table) {
            $table->dropColumn('number_step');
        });
    }
};

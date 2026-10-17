<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Shown on offers, invoices and salary slips (the Word-model letterhead);
            // independent of any user's own contact details.
            $table->string('invoice_phone', 50)->nullable()->after('vat_number');
            $table->string('invoice_email')->nullable()->after('invoice_phone');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['invoice_phone', 'invoice_email']);
        });
    }
};

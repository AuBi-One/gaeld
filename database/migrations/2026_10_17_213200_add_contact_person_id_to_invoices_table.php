<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // The named recipient printed under the customer name ("Attention: ...").
            $table->foreignUuid('contact_person_id')->nullable()->after('customer_id')
                ->constrained('contact_persons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_person_id');
        });
    }
};

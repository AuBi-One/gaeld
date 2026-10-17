<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The sender e-mail/phone shown on offers, invoices and salary slips moved
 * from this plugin's own settings to the organisation record (the same
 * contact details are used by all three documents, so they no longer belong
 * to the offers plugin specifically).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('of_settings')
            ->where(fn ($q) => $q->whereNotNull('sender_email')->orWhereNotNull('sender_phone'))
            ->orderBy('id')
            ->get()
            ->each(function (object $setting): void {
                DB::table('organizations')->where('id', $setting->organization_id)->update([
                    'invoice_phone' => $setting->sender_phone,
                    'invoice_email' => $setting->sender_email,
                ]);
            });

        Schema::table('of_settings', function (Blueprint $table) {
            $table->dropColumn(['sender_email', 'sender_phone']);
        });
    }

    public function down(): void
    {
        Schema::table('of_settings', function (Blueprint $table) {
            $table->string('sender_email')->nullable();
            $table->string('sender_phone', 50)->nullable();
        });

        DB::table('organizations')
            ->where(fn ($q) => $q->whereNotNull('invoice_email')->orWhereNotNull('invoice_phone'))
            ->orderBy('id')
            ->get()
            ->each(function (object $organization): void {
                DB::table('of_settings')->where('organization_id', $organization->id)->update([
                    'sender_phone' => $organization->invoice_phone,
                    'sender_email' => $organization->invoice_email,
                ]);
            });
    }
};

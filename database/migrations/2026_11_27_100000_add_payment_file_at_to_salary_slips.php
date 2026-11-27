<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a posted salary slip's net pay went into a bank payment file (pain.001), so it leaves the
 * list of payments to make; cleared to put it back (e.g. the bank rejected the file).
 *
 * Slips posted for months before the current one were paid before payment files existed: they are
 * marked on their payday (else the month's last day), so only the current month waits for a file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_slips', function (Blueprint $table) {
            $table->timestamp('payment_file_at')->nullable()->after('posting_date');
            // Which salary file run it went into (SAL-…); none for slips paid before payment files.
            $table->string('payment_file_ref', 40)->nullable()->after('payment_file_at');
        });

        $current = Carbon::now()->year * 12 + Carbon::now()->month;
        DB::table('salary_slips')
            ->whereNotNull('posted_at')
            ->whereRaw('period_year * 12 + period_month < ?', [$current])
            ->orderBy('id')
            ->select(['id', 'period_year', 'period_month', 'posting_date'])
            ->each(function (object $slip): void {
                $paid = $slip->posting_date !== null
                    ? Carbon::parse($slip->posting_date)
                    : Carbon::createFromDate((int) $slip->period_year, (int) $slip->period_month, 1)->endOfMonth();
                DB::table('salary_slips')->where('id', $slip->id)->update(['payment_file_at' => $paid->startOfDay()]);
            });
    }

    public function down(): void
    {
        Schema::table('salary_slips', function (Blueprint $table) {
            $table->dropColumn(['payment_file_at', 'payment_file_ref']);
        });
    }
};

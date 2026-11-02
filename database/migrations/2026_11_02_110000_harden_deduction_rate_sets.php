<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rate sets no longer fall back silently to the built-in defaults:
 *  - a set may be open-ended (date_to null = still valid), and the latest set
 *    of each code becomes open-ended, so the next year keeps using it until
 *    a new set is created;
 *  - the earliest STANDARD set starts no later than the organisation's
 *    earliest salary-slip period, so past months can be recalculated;
 *  - employees without a code in an organisation that has a STANDARD set
 *    (the backfill of the former flat rates, or the seeded defaults) get it;
 *  - each employee line says which box of the salary certificate it counts in;
 *  - one code per set, and the foreign keys are indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deduction_rate_sets', function (Blueprint $table) {
            $table->date('date_to')->nullable()->change();
        });

        $latest = DB::table('deduction_rate_sets')
            ->select('organization_id', 'code', DB::raw('max(date_from) as date_from'))
            ->groupBy('organization_id', 'code')
            ->get();
        foreach ($latest as $row) {
            DB::table('deduction_rate_sets')
                ->where('organization_id', $row->organization_id)
                ->where('code', $row->code)
                ->where('date_from', $row->date_from)
                ->update(['date_to' => null]);
        }

        $earliestStandard = DB::table('deduction_rate_sets')
            ->where('code', 'STANDARD')
            ->select('organization_id', DB::raw('min(date_from) as date_from'))
            ->groupBy('organization_id')
            ->get();
        foreach ($earliestStandard as $row) {
            $firstSlip = DB::table('salary_slips')
                ->where('organization_id', $row->organization_id)
                ->orderBy('period_year')
                ->orderBy('period_month')
                ->first(['period_year', 'period_month']);
            if ($firstSlip === null) {
                continue;
            }
            $start = sprintf('%04d-%02d-01', $firstSlip->period_year, $firstSlip->period_month);
            if ($start < substr((string) $row->date_from, 0, 10)) {
                DB::table('deduction_rate_sets')
                    ->where('organization_id', $row->organization_id)
                    ->where('code', 'STANDARD')
                    ->where('date_from', $row->date_from)
                    ->update(['date_from' => $start]);
            }
        }

        $organizationIds = $earliestStandard->pluck('organization_id');
        DB::table('employees')
            ->whereIn('organization_id', $organizationIds)
            ->whereNull('deduction_rate_code')
            ->update(['deduction_rate_code' => 'STANDARD']);

        Schema::table('deduction_rates', function (Blueprint $table) {
            $table->string('certificate_box', 2)->nullable()->after('account_id');
            $table->unique(['deduction_rate_set_id', 'code']);
            $table->index('account_id');
        });

        // The legacy codes: AVS/AI/APG, AC and AANP in box 9, LPP in box 10.
        foreach (['avs_' => '9', 'ac_' => '9', 'aanp_' => '9', 'lpp_' => '10'] as $prefix => $box) {
            DB::table('deduction_rates')
                ->where('type', 'employee')
                ->whereRaw('left(code, ?) = ?', [strlen($prefix), $prefix])
                ->update(['certificate_box' => $box]);
        }
    }

    public function down(): void
    {
        Schema::table('deduction_rates', function (Blueprint $table) {
            $table->dropUnique(['deduction_rate_set_id', 'code']);
            $table->dropIndex(['account_id']);
            $table->dropColumn('certificate_box');
        });

        DB::table('deduction_rate_sets')->whereNull('date_to')->update(['date_to' => '9999-12-31']);
        Schema::table('deduction_rate_sets', function (Blueprint $table) {
            $table->date('date_to')->nullable(false)->change();
        });
    }
};

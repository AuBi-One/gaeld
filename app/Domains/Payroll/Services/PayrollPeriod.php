<?php

namespace App\Domains\Payroll\Services;

use Carbon\Carbon;

/**
 * The calendar month a salary slip pays.
 */
final class PayrollPeriod
{
    /** Whether a Y-m-d date lies within the given payroll month. */
    public static function contains(string $date, int $month, int $year): bool
    {
        $start = Carbon::create($year, $month, 1)->toDateString();
        $end = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $day = Carbon::parse($date)->toDateString();

        return $day >= $start && $day <= $end;
    }
}

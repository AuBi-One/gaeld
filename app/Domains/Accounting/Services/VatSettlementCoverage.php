<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Which VAT periods of a date range are settled, for the year-end closing
 * (YearEndClosingAction and the wizard in YearEndClosingController).
 *
 * Gäld has no VAT periodicity setting, and organisations settle quarterly,
 * by semester (AuBi-One, TDFN: D22) or yearly. So the periods come from the
 * settlements themselves: a date with VAT activity is settled while a posted
 * `vat_settlement` entry whose period (vat_period_start/end, else the
 * `VAT-SETTLEMENT-<from>-<to>[-vN]` reference) contains it has no posted
 * reversal. A reversed settlement reopens its period for corrections, so it
 * counts only once settled again (`-vN`).
 */
final class VatSettlementCoverage
{
    private const REFERENCE_PATTERN = '/^VAT-SETTLEMENT-(\d{4}-\d{2}-\d{2})-(\d{4}-\d{2}-\d{2})(?:-v\d+)?$/';

    /**
     * Quarter labels ("Q3 2025") of the VAT activity between the two dates
     * that no active settlement covers.
     *
     * @return list<string>
     */
    public function unsettledPeriods(string $organizationId, string $fromDate, string $toDate): array
    {
        $activityDates = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereBetween('date', [$fromDate, $toDate])
            ->whereExists(fn (QueryBuilder $query) => $query->selectRaw('1')
                ->from('vat_entries')
                ->whereColumn('vat_entries.journal_entry_id', 'journal_entries.id'))
            ->distinct()
            ->pluck('date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->unique()
            ->sort()
            ->values();

        if ($activityDates->isEmpty()) {
            return [];
        }

        $periods = $this->activeSettlementPeriods($organizationId);
        $unsettled = [];
        foreach ($activityDates as $date) {
            $covered = false;
            foreach ($periods as [$start, $end]) {
                if ($start <= $date && $date <= $end) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                $day = Carbon::parse($date);
                $unsettled['Q'.$day->quarter.' '.$day->year] = true;
            }
        }

        return array_keys($unsettled);
    }

    /** @return list<array{0: string, 1: string}> [start, end] of each posted, unreversed settlement */
    private function activeSettlementPeriods(string $organizationId): array
    {
        $settlements = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('type', 'vat_settlement')
            ->where('is_posted', true)
            ->get(['id', 'reference', 'vat_period_start', 'vat_period_end']);

        if ($settlements->isEmpty()) {
            return [];
        }

        $reversed = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('is_posted', true)
            ->whereIn('reference', $settlements->pluck('reference')->filter()->map(fn (string $reference): string => 'REV-'.$reference)->values()->all())
            ->pluck('reference')
            ->flip();

        $periods = [];
        foreach ($settlements as $settlement) {
            if ($settlement->reference !== null && $reversed->has('REV-'.$settlement->reference)) {
                continue;
            }

            $start = $settlement->vat_period_start;
            $end = $settlement->vat_period_end;
            if (($start === null || $end === null)
                && preg_match(self::REFERENCE_PATTERN, (string) $settlement->reference, $matches) === 1) {
                [$start, $end] = [$matches[1], $matches[2]];
            }
            if ($start !== null && $end !== null) {
                $periods[] = [Carbon::parse($start)->toDateString(), Carbon::parse($end)->toDateString()];
            }
        }

        return $periods;
    }
}

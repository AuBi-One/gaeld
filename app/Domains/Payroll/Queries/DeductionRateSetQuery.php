<?php

namespace App\Domains\Payroll\Queries;

use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\DeductionRateSet;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Services\SwissDeductionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves and lists deduction rate sets ("barèmes") for the current
 * organization.
 */
class DeductionRateSetQuery
{
    /**
     * The set whose code matches and whose date range covers the given date
     * (e.g. the payroll period), for an organization. A set without an end
     * date is open-ended. If several sets of the same code somehow overlap
     * that date, the most recently starting one wins — but the requests
     * reject overlaps, so that should not normally happen.
     */
    public static function resolve(string $organizationId, ?string $code, string $date): ?DeductionRateSet
    {
        if ($code === null || $code === '') {
            return null;
        }

        return DeductionRateSet::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->where('date_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('date_to')->orWhere('date_to', '>=', $date))
            ->orderByDesc('date_from')
            ->first();
    }

    /**
     * The set an employee's slip for the given date is calculated with. Null
     * only when the organization has no set at all: the built-in defaults
     * then apply. Once an organization has a set, a missing code, a code
     * without a set covering the date or a set without active lines is an
     * error, never a silent fallback to the defaults.
     *
     * @throws \DomainException
     */
    public static function forEmployee(Employee $employee, string $date): ?DeductionRateSet
    {
        $organizationId = (string) $employee->organization_id;
        if (! DeductionRateSet::withoutGlobalScopes()->where('organization_id', $organizationId)->exists()) {
            return null;
        }

        $code = $employee->deduction_rate_code;
        $replace = [
            'name' => $employee->fullName(),
            'code' => (string) $code,
            'period' => Carbon::parse($date)->format('m.Y'),
        ];

        if ($code === null || $code === '') {
            throw new \DomainException(__('app.deduction_rate_code_missing', $replace));
        }

        $set = self::resolve($organizationId, $code, $date);
        if ($set === null) {
            throw new \DomainException(__('app.deduction_rate_set_not_found', $replace));
        }

        if (! $set->rates()->where('is_active', true)->exists()) {
            throw new \DomainException(__('app.deduction_rate_set_empty', $replace));
        }

        return $set;
    }

    /**
     * The lines a slip is calculated with, as stored in its snapshot: the
     * set's active lines already loaded for the calculation, or the
     * built-in defaults when there is no set (null).
     *
     * @param  Collection<int, DeductionRate>|null  $rates
     * @return array<string, array{name: string, type: string, rate: string, account_id: int|null, certificate_box: string|null}>
     */
    public static function snapshotLines(?Collection $rates): array
    {
        if ($rates === null) {
            return self::defaultLines();
        }

        $lines = [];
        foreach ($rates as $rate) {
            $lines[$rate->code] = self::line($rate);
        }

        return $lines;
    }

    /**
     * @return array<string, array{name: string, type: string, rate: string, account_id: int|null, certificate_box: string|null}>
     */
    public static function defaultLines(): array
    {
        $lines = [];
        foreach (SwissDeductionService::defaults() as $default) {
            $lines[$default['code']] = [
                'name' => $default['name'],
                'type' => $default['type'],
                'rate' => $default['rate'],
                'account_id' => null,
                'certificate_box' => DeductionRate::defaultCertificateBox($default['code'], $default['type']),
            ];
        }

        return $lines;
    }

    /**
     * @return array{name: string, type: string, rate: string, account_id: int|null, certificate_box: string|null}
     */
    public static function line(DeductionRate $rate): array
    {
        return [
            'name' => $rate->name,
            'type' => $rate->type,
            'rate' => (string) $rate->rate,
            'account_id' => $rate->account_id,
            'certificate_box' => $rate->certificate_box,
        ];
    }

    /**
     * Sets of the code whose period overlaps [$from, $to] ($to null = open-ended).
     *
     * @return Collection<int, DeductionRateSet>
     */
    public static function overlapping(string $organizationId, string $code, string $from, ?string $to): Collection
    {
        return DeductionRateSet::where('organization_id', $organizationId)
            ->where('code', $code)
            ->when($to !== null && $to !== '', fn ($query) => $query->where('date_from', '<=', $to))
            ->where(fn ($query) => $query->whereNull('date_to')->orWhere('date_to', '>=', $from))
            ->get();
    }

    /**
     * The open-ended set of the code that a new open-ended set starting on
     * $from takes over from: it is closed on the day before $from. A new set
     * with an end date takes over nothing (inside an open-ended set it is an
     * overlap, which the requests refuse).
     */
    public static function supersededBy(string $organizationId, string $code, string $from, ?string $to): ?DeductionRateSet
    {
        if ($to !== null && $to !== '') {
            return null;
        }

        return DeductionRateSet::where('organization_id', $organizationId)
            ->where('code', $code)
            ->whereNull('date_to')
            ->where('date_from', '<', $from)
            ->first();
    }

    /**
     * The first day of the organisation's earliest salary-slip period, so a
     * set created for existing payroll also covers recalculating past months;
     * 1 January of the current year when there is no slip yet.
     */
    public static function earliestPayrollStart(string $organizationId): string
    {
        $first = DB::table('salary_slips')
            ->where('organization_id', $organizationId)
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->first(['period_year', 'period_month']);

        return $first === null
            ? Carbon::now()->startOfYear()->toDateString()
            : Carbon::create((int) $first->period_year, (int) $first->period_month, 1)->toDateString();
    }

    /**
     * All sets for the current organization, each with its lines and their
     * mapped account, for the settings page — newest period first within
     * each code.
     *
     * @return Collection<int, DeductionRateSet>
     */
    public static function all(): Collection
    {
        return DeductionRateSet::query()
            ->with(['rates' => fn ($query) => $query->with('account:id,code,name')->orderBy('name')->orderBy('type')])
            ->orderBy('code')
            ->orderByDesc('date_from')
            ->get();
    }

    /**
     * Distinct codes already in use for the current organization, for the
     * employee "deduction rate code" dropdown.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        $codes = DeductionRateSet::query()
            ->orderBy('code')
            ->distinct()
            ->pluck('code')
            ->map(fn (mixed $code): string => (string) $code)
            ->all();

        return array_values($codes);
    }
}

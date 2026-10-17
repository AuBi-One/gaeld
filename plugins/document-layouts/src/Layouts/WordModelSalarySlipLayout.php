<?php

namespace Plugins\DocumentLayouts\Layouts;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Contracts\SalarySlipPdfLayoutInterface;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\SalarySlip;
use App\Support\Money;
use App\Support\Pdf\PdfFooter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Plugins\DocumentLayouts\Support\Carlito;
use Plugins\DocumentLayouts\Support\Format;
use Plugins\DocumentLayouts\Support\Letterhead;

/**
 * The salary slip after AuBi-One's Word model (MODELE_SALAIRE.docx): logo,
 * sender and employee side by side, "Décompte salaire" + month, date and AVS
 * number, then one table (Position | employer rate | employee rate | amount):
 * gross salary, social charges per insurance with both rates, total charges,
 * other items, net salary; the employee's IBAN below. Blade + dompdf, Carlito.
 */
final class WordModelSalarySlipLayout implements SalarySlipPdfLayoutInterface
{
    /**
     * Deduction codes are `<insurance>_employee` / `<insurance>_employer`; codes of the
     * same insurance share one row (e.g. accident insurance: AANP paid by the employee,
     * LAA by the employer). Unknown insurances get a row of their own after these.
     */
    private const INSURANCES = [
        'avs' => ['avs'],
        'ac' => ['ac'],
        'laa' => ['aanp', 'laa', 'aap'],
        'lpp' => ['lpp'],
        'apgm' => ['apgm'],
        'family_allowance' => ['family_allowance', 'af'],
    ];

    /** Slip data that sits next to the deductions and is not a charge. */
    private const NOT_CHARGES = [
        'total_employee', 'total_employer', 'net_salary', 'source_tax', 'source',
        'base_salary', 'thirteenth_salary', 'unpaid_leave_days', 'unpaid_leave_amount', 'reimbursement_amount',
    ];

    public function key(): string
    {
        return 'word-model';
    }

    public function label(): string
    {
        return (string) trans('document-layouts::dl.layout_label');
    }

    public function renderSalarySlip(SalarySlip $slip, Organization $organization): string
    {
        // Only data: URIs (the logo) and the Carlito files may be loaded (chroot), as for offers.
        File::ensureDirectoryExists(storage_path('fonts'));

        return Pdf::loadHTML($this->html($slip, $organization))->setPaper('A4', 'portrait')
            ->setOption([
                'isFontSubsettingEnabled' => true,
                'isRemoteEnabled' => false,
                'allowedProtocols' => ['data://' => [], 'file://' => []],
                'chroot' => [Carlito::ttfPath()],
            ])
            ->output();
    }

    public function html(SalarySlip $slip, Organization $organization): string
    {
        $language = Format::language($organization->locale);
        $letterhead = Letterhead::for($organization);
        $employee = $slip->employeeDocumentData();
        $period = Carbon::create($slip->period_year, $slip->period_month, 1);
        /** @var Carbon $localized */
        $localized = $period->copy()->locale($language);
        $t = fn (string $key, array $replace = []): string => (string) trans('document-layouts::dl.'.$key, $replace, $language);

        return view('document-layouts::salary-slip', [
            'letterhead' => $letterhead,
            'logo' => $letterhead->logoDataUri(),
            'logoSize' => $letterhead->logoSize(60, 24),
            'footer' => trim((string) $organization->pdf_footer_text) !== '' ? PdfFooter::text($organization) : null,
            'language' => $language,
            'employeeName' => trim($employee['first_name'].' '.$employee['last_name']),
            'ahvNumber' => $employee['ahv_number'],
            'month' => mb_convert_case($localized->translatedFormat('F Y'), MB_CASE_TITLE),
            'date' => ($slip->posted_at ?? $period->copy()->endOfMonth())->format('d.m.Y'),
            'earnings' => $this->earnings($slip, $t),
            'gross' => Format::money((string) $slip->gross_salary),
            'charges' => $charges = $this->charges($slip, $t),
            'totalCharges' => $this->totals($slip, $charges),
            'sourceTax' => $this->sourceTax($slip),
            'other' => $this->other($slip, $t),
            'net' => Format::money((string) $slip->net_salary),
            'iban' => $this->iban($employee),
            't' => $t,
            'fonts' => 'file://'.Carlito::ttfPath(),
        ])->render();
    }

    /**
     * Base salary, 13th salary and unpaid leave, when the gross is not just the base salary.
     *
     * @param  callable(string, array<string, mixed>=): string  $t
     * @return list<array{label: string, amount: string}>
     */
    private function earnings(SalarySlip $slip, callable $t): array
    {
        $a = $slip->adjustments ?? [];
        $base = (string) ($a['base_salary'] ?? $slip->gross_salary);
        $thirteenth = (string) ($a['thirteenth_salary'] ?? '0');
        $unpaid = (string) ($a['unpaid_leave_amount'] ?? '0');
        if (Money::compare($base, (string) $slip->gross_salary) === 0 && Money::isZero($thirteenth) && Money::isZero($unpaid)) {
            return [];
        }
        $rows = [['label' => $t('base_salary'), 'amount' => Format::money($base)]];
        if (! Money::isZero($thirteenth)) {
            $rows[] = ['label' => $t('thirteenth_salary'), 'amount' => Format::money($thirteenth)];
        }
        if (! Money::isZero($unpaid)) {
            $rows[] = ['label' => $t('unpaid_leave', ['days' => (string) ($a['unpaid_leave_days'] ?? '')]), 'amount' => '-'.Format::money($unpaid)];
        }

        return $rows;
    }

    /**
     * One row per insurance: employer rate, employee rate, employee amount. The rate
     * is the organisation's configured one when it gives the slip's amount, otherwise
     * the amount's share of the gross salary (e.g. migrated slips or changed rates).
     *
     * @param  callable(string, array<string, mixed>=): string  $t
     * @return list<array{label: string, employer_rate: ?string, employee_rate: ?string, amount: ?string, employer_rate_value: string, employee_rate_value: string, amount_value: string}>
     */
    private function charges(SalarySlip $slip, callable $t): array
    {
        $gross = (string) $slip->gross_salary;
        $rates = DeductionRate::query()->withoutGlobalScopes()
            ->where('organization_id', $slip->organization_id)->where('is_active', true)
            ->get()->keyBy('code');

        $groups = [];
        foreach ((array) $slip->deductions as $code => $amount) {
            if (in_array($code, self::NOT_CHARGES, true) || ! is_numeric($amount)) {
                continue;
            }
            // The side is the configured rate's type; the code's suffix for codes without a
            // configured rate (e.g. migrated slips); an employee deduction otherwise.
            $configured = $rates->get($code);
            $suffix = preg_match('/\A(.+)_(employee|employer)\z/', $code, $m) === 1 ? $m : null;
            $side = in_array($configured?->type, ['employee', 'employer'], true) ? $configured->type : ($suffix[2] ?? 'employee');
            $insurance = $suffix[1] ?? $code;
            foreach (self::INSURANCES as $key => $prefixes) {
                if (in_array($insurance, $prefixes, true)) {
                    $insurance = $key;
                }
            }
            $amount = Money::normalize((string) $amount);
            $rate = $configured !== null && Money::compare(Money::percentage($gross, (string) $configured->rate), $amount) === 0
                ? (string) $configured->rate
                : (Money::isZero($gross) ? '0' : (string) round((float) $amount / (float) $gross * 100, 3));

            $groups[$insurance] ??= ['name' => $configured?->name, 'employer' => '0.00', 'employee' => '0.00', 'employer_rate' => '0', 'employee_rate' => '0'];
            $groups[$insurance][$side] = Money::add($groups[$insurance][$side], $amount);
            $groups[$insurance][$side.'_rate'] = (string) ((float) $groups[$insurance][$side.'_rate'] + (float) $rate);
        }

        $order = array_flip(array_keys(self::INSURANCES));
        uksort($groups, fn (string $a, string $b): int => [$order[$a] ?? 99, $a] <=> [$order[$b] ?? 99, $b]);

        $rows = [];
        foreach ($groups as $insurance => $group) {
            if (Money::isZero($group['employer']) && Money::isZero($group['employee'])) {
                continue;
            }
            $label = isset(self::INSURANCES[$insurance]) ? $t('insurance_'.$insurance) : ($group['name'] ?? strtoupper($insurance));
            $rows[] = [
                'label' => $label,
                'employer_rate' => Money::isZero($group['employer']) ? null : Format::rate($group['employer_rate']),
                'employee_rate' => Money::isZero($group['employee']) ? null : Format::rate($group['employee_rate']),
                'amount' => Money::isZero($group['employee']) ? null : Format::money($group['employee']),
                'employer_rate_value' => Money::isZero($group['employer']) ? '0' : $group['employer_rate'],
                'employee_rate_value' => Money::isZero($group['employee']) ? '0' : $group['employee_rate'],
                'amount_value' => $group['employee'],
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{employer_rate_value: string, employee_rate_value: string, amount_value: string}>  $charges
     * @return array{employer_rate: string, employee_rate: string, amount: string}
     */
    private function totals(SalarySlip $slip, array $charges): array
    {
        $employerRate = array_sum(array_map(fn (array $c): float => (float) $c['employer_rate_value'], $charges));
        $employeeRate = array_sum(array_map(fn (array $c): float => (float) $c['employee_rate_value'], $charges));
        $amount = (string) ($slip->deductions['total_employee']
            ?? array_reduce($charges, fn (string $sum, array $c): string => Money::add($sum, $c['amount_value']), '0.00'));

        return [
            'employer_rate' => Format::rate((string) $employerRate),
            'employee_rate' => Format::rate((string) $employeeRate),
            'amount' => Format::money($amount),
        ];
    }

    /** @return array{rate: ?string, amount: string}|null */
    private function sourceTax(SalarySlip $slip): ?array
    {
        $amount = (string) ($slip->deductions['source_tax'] ?? $slip->source_tax_amount ?? '0');
        if (! is_numeric($amount) || Money::isZero(Money::normalize($amount))) {
            return null;
        }

        return [
            'rate' => $slip->source_tax_rate !== null ? Format::rate((string) $slip->source_tax_rate) : null,
            'amount' => Format::money($amount),
        ];
    }

    /**
     * Reimbursements paid with the salary: each claim, and the rest of the amount.
     *
     * @param  callable(string, array<string, mixed>=): string  $t
     * @return list<array{label: string, amount: string}>
     */
    private function other(SalarySlip $slip, callable $t): array
    {
        $a = $slip->adjustments ?? [];
        $total = (string) ($a['reimbursement_amount'] ?? '0');
        if (! is_numeric($total) || Money::isZero(Money::normalize($total))) {
            return [];
        }
        $rows = [];
        $rest = Money::normalize($total);
        foreach ($a['reimbursement_items'] ?? [] as $item) {
            $rows[] = ['label' => (string) $item['label'], 'amount' => Format::money((string) $item['amount'])];
            $rest = Money::subtract($rest, (string) $item['amount']);
        }
        if (! Money::isZero($rest)) {
            $rows[] = ['label' => $t('reimbursement'), 'amount' => Format::money($rest)];
        }

        return $rows;
    }

    /**
     * @param  array{iban: string|null, ...}  $employee
     */
    private function iban(array $employee): ?string
    {
        $iban = $employee['iban'];

        return is_string($iban) && trim($iban) !== '' ? Format::iban($iban) : null;
    }
}

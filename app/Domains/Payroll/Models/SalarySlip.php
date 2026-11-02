<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Queries\DeductionRateSetQuery;
use App\Domains\Payroll\Services\SwissDeductionService;
use App\Support\Money;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Monthly salary slip for an employee, including gross/net amounts and deductions.
 *
 * Optionally linked to a posted journal entry for accounting integration.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $organization_id
 * @property int $period_month
 * @property int $period_year
 * @property string $gross_salary
 * @property string $net_salary
 * @property string|null $journal_entry_id
 * @property array<string, mixed> $deductions
 * @property array{base_salary: string, thirteenth_salary: string, unpaid_leave_days: int, unpaid_leave_amount: string, reimbursement_amount: string, reimbursement_items?: list<array{id: string, date: string, label: string, amount: string, account_code: string}>}|null $adjustments
 * @property array<string, mixed>|null $employee_snapshot
 * @property array{set_id: int|null, set_code: string|null, lines: array<string, array{name: string, type: string, rate: string, account_id: int|null, certificate_box: string|null}>}|null $deduction_snapshot
 * @property string|null $source_tax_base
 * @property string|null $source_tax_rate
 * @property string|null $source_tax_amount
 * @property Carbon|null $posted_at
 * @property Carbon|null $email_sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 */
class SalarySlip extends Model
{
    use Auditable, BelongsToOrganization, HasUuids;

    protected $appends = ['status', 'month_label', 'employee_name'];

    protected $hidden = ['employee_snapshot'];

    protected $fillable = [
        'employee_id',
        'organization_id',
        'period_month',
        'period_year',
        'gross_salary',
        'net_salary',
        'journal_entry_id',
        'deductions',
        'adjustments',
        'employee_snapshot',
        'deduction_snapshot',
        'source_tax_base',
        'source_tax_rate',
        'source_tax_amount',
        'posted_at',
        'email_sent_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'integer',
            'period_year' => 'integer',
            'gross_salary' => 'decimal:2',
            'net_salary' => 'decimal:2',
            'deductions' => 'array',
            'adjustments' => 'array',
            'employee_snapshot' => 'array',
            'deduction_snapshot' => 'array',
            'posted_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }

    public function getStatusAttribute(): string
    {
        return $this->isPosted() ? 'posted' : 'draft';
    }

    public function getMonthLabelAttribute(): string
    {
        $monthName = Carbon::createFromDate($this->period_year, $this->period_month, 1)
            ->translatedFormat('F Y');

        return $monthName;
    }

    public function getEmployeeNameAttribute(): string
    {
        $snapshot = $this->employee_snapshot;
        if (is_array($snapshot) && isset($snapshot['first_name'], $snapshot['last_name'])) {
            return "{$snapshot['first_name']} {$snapshot['last_name']}";
        }

        // Only resolve when relation is already loaded — avoids lazy-loading
        // violations when the slip is serialized in contexts where Employee
        // wasn't eager-loaded (e.g. EmployeeController::show via $employee->salarySlips).
        $employee = $this->relationLoaded('employee') ? $this->getRelation('employee') : null;

        return $employee instanceof Employee ? $employee->fullName() : '';
    }

    /**
     * Keys {@see SwissDeductionService::calculateDeductions()} and
     * the calculator add to `deductions` that are not themselves a per-charge
     * amount. Readers also skip any non-numeric value (e.g. a marker written
     * by an import).
     */
    public const NON_RATE_DEDUCTION_KEYS = [
        'total_employee', 'total_employer', 'net_salary', 'base_salary',
        'thirteenth_salary', 'unpaid_leave_days', 'unpaid_leave_amount',
        'reimbursement_amount', 'source_tax',
    ];

    /**
     * The per-charge amounts of `deductions` (code => amount), without the
     * totals, adjustments and non-numeric values.
     *
     * @return array<string, string>
     */
    public function chargeAmounts(): array
    {
        $amounts = [];
        foreach ((array) $this->deductions as $code => $amount) {
            if (in_array($code, self::NON_RATE_DEDUCTION_KEYS, true) || ! is_numeric($amount)) {
                continue;
            }
            $amounts[(string) $code] = Money::normalize((string) $amount);
        }

        return $amounts;
    }

    /**
     * The rate lines (name, side, rate, credited account, certificate box)
     * this slip was calculated with, by code: the snapshot taken at
     * calculation. Slips calculated before the snapshot existed fall back to
     * the employee's current set for the period (deleted employees included),
     * completed with the built-in defaults.
     *
     * @return array<string, array{name: string, type: string, rate: string, account_id: int|null, certificate_box: string|null}>
     */
    public function rateLines(): array
    {
        $snapshot = $this->deduction_snapshot;
        if ($snapshot !== null) {
            return $snapshot['lines'];
        }

        // The relation is null for a deleted employee: read it with the trashed ones.
        $employee = $this->employee ?? Employee::withTrashed()
            ->withoutGlobalScopes()
            ->where('organization_id', $this->organization_id)
            ->find($this->employee_id);
        $periodDate = Carbon::create($this->period_year, $this->period_month, 1)->toDateString();
        $set = DeductionRateSetQuery::resolve((string) $this->organization_id, $employee?->deduction_rate_code, $periodDate);

        $lines = [];
        foreach ($set === null ? [] : $set->rates as $rate) {
            $line = DeductionRateSetQuery::line($rate);
            $line['certificate_box'] ??= DeductionRate::defaultCertificateBox($rate->code, $rate->type);
            $lines[$rate->code] = $line;
        }

        return $lines + DeductionRateSetQuery::defaultLines();
    }

    /**
     * Every social-charge deduction actually on this slip, one row per
     * charge (whatever its code — not a fixed AVS/AC/AANP/LPP list), with
     * its employee and employer amounts and the name configured for it in
     * Payroll > Charges sociales (or the built-in default's name, when the
     * slip was calculated without a rate set). Used by both the standard
     * PDF and the e-mail attachment.
     *
     * @return list<array{name: string, employee: string, employer: string}>
     */
    public function deductionRows(): array
    {
        $lines = $this->rateLines();

        $rows = [];
        foreach ($this->chargeAmounts() as $code => $amount) {
            $key = (string) preg_replace('/_(employee|employer)$/', '', $code);
            $rows[$key] ??= ['name' => $lines[$code]['name'] ?? $key, 'employee' => '0.00', 'employer' => '0.00'];
            $type = $lines[$code]['type'] ?? (str_ends_with($code, '_employer') ? 'employer' : 'employee');
            $side = $type === 'employer' ? 'employer' : 'employee';
            $rows[$key][$side] = Money::add($rows[$key][$side], $amount);
        }

        return array_values($rows);
    }

    /**
     * Return the employee identity captured for generated documents.
     *
     * @return array{first_name: string, last_name: string, email: string|null, ahv_number: string|null, iban: string|null}
     */
    public function employeeDocumentData(): array
    {
        $snapshot = $this->employee_snapshot;
        if (is_array($snapshot) && isset($snapshot['first_name'], $snapshot['last_name'])) {
            return [
                'first_name' => (string) $snapshot['first_name'],
                'last_name' => (string) $snapshot['last_name'],
                'email' => isset($snapshot['email']) ? (string) $snapshot['email'] : null,
                'ahv_number' => $this->decryptSnapshotField($snapshot, 'ahv_number'),
                // Snapshots taken before the IBAN was added to them have no
                // `iban` key: read it from the employee, as those slips did.
                'iban' => array_key_exists('iban', $snapshot)
                    ? $this->decryptSnapshotField($snapshot, 'iban')
                    : $this->documentEmployee()->iban,
            ];
        }

        $employee = $this->documentEmployee();

        return [
            'first_name' => (string) $employee->first_name,
            'last_name' => (string) $employee->last_name,
            'email' => $employee->email,
            'ahv_number' => $employee->ahv_number,
            'iban' => $employee->iban,
        ];
    }

    /**
     * The slip's employee, deleted ones included.
     */
    private function documentEmployee(): Employee
    {
        $employee = $this->relationLoaded('employee') ? $this->getRelation('employee') : null;
        $employee ??= Employee::withTrashed()
            ->withoutGlobalScopes()
            ->where('organization_id', $this->organization_id)
            ->find($this->employee_id);

        if (! $employee instanceof Employee) {
            throw new \RuntimeException('The employee record for this salary slip is unavailable.');
        }

        return $employee;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function decryptSnapshotField(array $snapshot, string $key): ?string
    {
        if (! isset($snapshot[$key])) {
            return null;
        }

        $value = (string) $snapshot[$key];
        if (($snapshot["{$key}_encrypted"] ?? false) !== true) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
}

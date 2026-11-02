<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Accounting\Models\Account;
use App\Domains\Organizations\Models\Organization;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line (e.g. AVS employee, AVS employer) of a deduction rate set.
 *
 * @property int $id
 * @property string $organization_id
 * @property int|null $deduction_rate_set_id
 * @property string $name
 * @property string $code
 * @property string $rate
 * @property string $type
 * @property int|null $account_id
 * @property string|null $certificate_box
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DeductionRate extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'deduction_rate_set_id',
        'name',
        'code',
        'rate',
        'type',
        'account_id',
        'certificate_box',
        'is_active',
    ];

    /**
     * Boxes of the Swiss salary certificate an employee line can count in:
     * 9 = AVS/AI/APG/AC/AANP contributions, 10 = occupational pension (LPP),
     * 15 = remarks (other employee charges the certificate lists one by one,
     * e.g. PC Familles or a sickness daily-allowance contribution).
     * Null = not a certificate deduction (and always null for employer lines).
     */
    public const CERTIFICATE_BOXES = ['9', '10', '15'];

    /**
     * The box the built-in and legacy codes count in, for lines (and slips)
     * that carry no explicit box.
     */
    public static function defaultCertificateBox(string $code, string $type): ?string
    {
        if ($type !== 'employee') {
            return null;
        }

        foreach (['avs_' => '9', 'ac_' => '9', 'aanp_' => '9', 'lpp_' => '10'] as $prefix => $box) {
            if (str_starts_with($code, $prefix)) {
                return $box;
            }
        }

        return null;
    }

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<DeductionRateSet, $this> */
    public function set(): BelongsTo
    {
        return $this->belongsTo(DeductionRateSet::class, 'deduction_rate_set_id');
    }

    /**
     * The chart-of-accounts entry this deduction is posted to (e.g. the AVS
     * payable liability account), so payroll posting can be mapped per rate
     * instead of to a fixed set of accounts.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

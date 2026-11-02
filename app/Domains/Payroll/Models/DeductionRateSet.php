<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Organizations\Models\Organization;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named, dated set of deduction rates ("barème") — e.g. code "STANDARD"
 * valid 01.01.2026–31.12.2026. An employee is attached only to a `code`
 * (Employee::$deduction_rate_code); the set whose date range covers the
 * payroll period being calculated is resolved automatically, so the same
 * code can have a different set (and different rates) each year without
 * changing anything on the employee record. An open-ended set (no end
 * date) keeps applying to later months until a newer set replaces it.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $code
 * @property string $title
 * @property Carbon $date_from
 * @property Carbon|null $date_to Null = open-ended (still valid)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DeductionRateSet extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'code',
        'title',
        'date_from',
        'date_to',
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<DeductionRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(DeductionRate::class);
    }
}

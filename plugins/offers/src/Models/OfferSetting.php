<?php

namespace Plugins\Offers\Models;

use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Offer settings of an organisation: default validity of a new offer and the
 * step between two offer numbers (1 = consecutive).
 *
 * @property string $id
 * @property string $organization_id
 * @property int $validity_months
 * @property int $number_step
 */
class OfferSetting extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $table = 'of_settings';

    protected $fillable = ['organization_id', 'validity_months', 'number_step'];

    protected function casts(): array
    {
        return ['validity_months' => 'integer', 'number_step' => 'integer'];
    }

    public static function for(string $organizationId): self
    {
        return self::query()->withoutGlobalScopes()->where('organization_id', $organizationId)->first()
            ?? new self(['organization_id' => $organizationId, 'validity_months' => (int) config('offers.default_validity_months', 2), 'number_step' => 1]);
    }
}

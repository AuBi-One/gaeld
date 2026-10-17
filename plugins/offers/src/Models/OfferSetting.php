<?php

namespace Plugins\Offers\Models;

use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Offer settings of an organisation: default validity of a new offer.
 *
 * @property string $id
 * @property string $organization_id
 * @property int $validity_months
 */
class OfferSetting extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $table = 'of_settings';

    protected $fillable = ['organization_id', 'validity_months'];

    protected function casts(): array
    {
        return ['validity_months' => 'integer'];
    }

    public static function for(string $organizationId): self
    {
        return self::query()->withoutGlobalScopes()->where('organization_id', $organizationId)->first()
            ?? new self(['organization_id' => $organizationId, 'validity_months' => (int) config('offers.default_validity_months', 2)]);
    }
}

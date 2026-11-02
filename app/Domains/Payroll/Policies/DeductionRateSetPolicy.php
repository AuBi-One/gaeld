<?php

namespace App\Domains\Payroll\Policies;

use App\Domains\Organizations\Enums\Permission;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/**
 * Social-charge rate sets and their lines follow the payroll permissions:
 * payroll.view to see them, payroll.edit to change them. Records are scoped
 * to the current organization by route model binding.
 */
class DeductionRateSetPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::PayrollView);
    }

    public function manage(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::PayrollEdit);
    }
}

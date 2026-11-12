<?php

namespace App\Domains\Accounting\Policies;

use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/**
 * Guided corrections: viewing with the journal, changing (replacement, post,
 * discard) with journal edit rights while the correction is a draft.
 * Starting one is {@see JournalEntryPolicy::correct()} on the original.
 */
class JournalCorrectionPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::AccountingView);
    }

    public function view(User $user, JournalCorrection $correction): bool
    {
        return $this->belongsToOrganization($user, $correction)
            && $user->hasPermissionTo(Permission::AccountingView);
    }

    public function update(User $user, JournalCorrection $correction): bool
    {
        return $this->belongsToOrganization($user, $correction)
            && $user->hasPermissionTo(Permission::AccountingEdit)
            && $correction->isDraft();
    }

    public function delete(User $user, JournalCorrection $correction): bool
    {
        return $this->update($user, $correction);
    }
}

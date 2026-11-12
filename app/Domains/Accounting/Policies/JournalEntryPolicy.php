<?php

namespace App\Domains\Accounting\Policies;

use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\JournalCorrectionEligibility;
use App\Domains\Accounting\Services\JournalEntryReferences;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Authorization policy for journal entry operations.
 */
class JournalEntryPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::AccountingView);
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $this->belongsToOrganization($user, $entry)
            && $user->hasPermissionTo(Permission::AccountingView);
    }

    public function create(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::AccountingCreate);
    }

    public function update(User $user, JournalEntry $entry): Response|bool
    {
        if ($entry->archived_at !== null) {
            return false;
        }

        $allowed = $this->belongsToOrganization($user, $entry)
            && $user->hasPermissionTo(Permission::AccountingEdit)
            && ! $entry->is_posted;

        return $allowed ? $this->unlessOwned($entry) : false;
    }

    public function delete(User $user, JournalEntry $entry): Response|bool
    {
        if ($entry->archived_at !== null) {
            return false;
        }

        $allowed = $this->belongsToOrganization($user, $entry)
            && $user->hasPermissionTo(Permission::AccountingDelete)
            && ! $entry->is_posted;

        return $allowed ? $this->unlessOwned($entry) : false;
    }

    /**
     * Attach a justificatif to an entry that has none, posted or not: the voucher does not change
     * the booking (OR 957a). Replacing or removing one stays a draft-only edit (update).
     */
    public function attachJustificatif(User $user, JournalEntry $entry): Response|bool
    {
        if ($entry->archived_at !== null || $entry->justificatif_path !== null) {
            return false;
        }

        $allowed = $this->belongsToOrganization($user, $entry)
            && $user->hasPermissionTo(Permission::AccountingEdit);

        return $allowed ? $this->unlessOwned($entry) : false;
    }

    public function post(User $user, JournalEntry $entry): Response|bool
    {
        $allowed = $this->belongsToOrganization($user, $entry)
            && $user->hasPermissionTo(Permission::AccountingEdit)
            && ! $entry->is_posted;

        // A correction's drafts are posted together, by the correction.
        if ($allowed && JournalCorrection::openFor((string) $entry->id) !== null) {
            return Response::deny(__('app.journal_correction_drafts_locked'));
        }

        return $allowed;
    }

    /**
     * Start a guided correction (D70: the posted entry itself never changes).
     * Denied with the reason when the entry is corrected elsewhere (its owner,
     * a system workflow) or already reversed or corrected.
     */
    public function correct(User $user, JournalEntry $entry): Response|bool
    {
        if (! $this->belongsToOrganization($user, $entry) || ! $user->hasPermissionTo(Permission::AccountingEdit)) {
            return false;
        }

        $refusal = app(JournalCorrectionEligibility::class)->refusal($entry);

        return $refusal === null ? true : Response::deny($refusal);
    }

    /**
     * An entry created by another feature (e.g. a salary slip) is changed, deleted or reversed there,
     * not in the journal; posting a draft stays allowed.
     */
    private function unlessOwned(JournalEntry $entry): Response|bool
    {
        $owner = app(JournalEntryReferences::class)->for($entry);

        return $owner === null
            ? true
            : Response::deny(__('app.journal_entry_owned', ['source' => $owner->label]));
    }

    public function reverse(User $user, JournalEntry $entry): Response|bool
    {
        $allowed = $this->belongsToOrganization($user, $entry)
            && $user->hasPermissionTo(Permission::AccountingEdit)
            && $entry->is_posted;

        // A closed year is changed by reopening and closing it again.
        if ($allowed && $entry->type === JournalEntry::TYPE_YEAR_END_CLOSING) {
            return Response::deny(__('app.journal_closing_not_reversible'));
        }

        return $allowed ? $this->unlessOwned($entry) : false;
    }
}

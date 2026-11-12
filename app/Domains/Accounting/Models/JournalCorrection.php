<?php

namespace App\Domains\Accounting\Models;

use App\Domains\Accounting\Enums\JournalCorrectionStatus;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A guided correction of a posted {@see JournalEntry}: the original stays
 * unchanged (D70); a locked reversal draft and an editable replacement draft
 * take effect together when the correction is posted.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $original_journal_entry_id
 * @property string $reversal_journal_entry_id
 * @property string $replacement_journal_entry_id
 * @property JournalCorrectionStatus $status
 * @property string $reason
 * @property int|null $user_id
 * @property Carbon|null $posted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read JournalEntry $original
 * @property-read JournalEntry $reversal
 * @property-read JournalEntry $replacement
 */
class JournalCorrection extends Model
{
    use Auditable, BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'original_journal_entry_id',
        'reversal_journal_entry_id',
        'replacement_journal_entry_id',
        'status',
        'reason',
        'user_id',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => JournalCorrectionStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'original_journal_entry_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function reversal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function replacement(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'replacement_journal_entry_id');
    }

    public function isDraft(): bool
    {
        return $this->status === JournalCorrectionStatus::Draft;
    }

    /** The open (draft) correction one of whose drafts is $entryId, if any. */
    public static function openFor(string $entryId): ?self
    {
        return self::withoutGlobalScopes()
            ->where('status', JournalCorrectionStatus::Draft->value)
            ->where(fn ($query) => $query
                ->where('reversal_journal_entry_id', $entryId)
                ->orWhere('replacement_journal_entry_id', $entryId))
            ->first();
    }
}

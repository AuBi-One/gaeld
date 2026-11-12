<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\Exceptions\JournalCorrectionException;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use Illuminate\Support\Facades\DB;

/**
 * Discards an open correction: both drafts and the correction record go;
 * the original stays as it is. A posted correction is final.
 */
final class CancelJournalCorrectionAction
{
    public function __construct(
        private readonly LedgerService $ledger,
    ) {}

    /** @throws JournalCorrectionException When the correction is already posted */
    public function execute(JournalCorrection $correction): void
    {
        DB::transaction(function () use ($correction): void {
            $locked = JournalCorrection::withoutGlobalScopes()->whereKey($correction->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isDraft()) {
                throw JournalCorrectionException::notDraft();
            }

            $drafts = JournalEntry::withoutGlobalScopes()
                ->whereKey([$locked->reversal_journal_entry_id, $locked->replacement_journal_entry_id])
                ->get();
            $locked->delete();
            foreach ($drafts as $draft) {
                $this->ledger->deleteDraft($draft);
            }
        });
    }
}

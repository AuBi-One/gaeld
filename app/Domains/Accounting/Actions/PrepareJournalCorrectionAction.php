<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\JournalCorrectionStatus;
use App\Domains\Accounting\Exceptions\FiscalYearClosedException;
use App\Domains\Accounting\Exceptions\JournalCorrectionException;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Services\JournalCorrectionEligibility;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Accounting\Services\VatPeriodLockService;
use Illuminate\Support\Facades\DB;

/**
 * Starts a guided correction of a posted entry: a reversal draft (the
 * original's lines swapped; locked) and a replacement draft (a copy of the
 * original, edited by the user) are created together. The original is never
 * changed (D70); neither draft affects the ledger until
 * {@see PostJournalCorrectionAction} posts both.
 */
final class PrepareJournalCorrectionAction
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly JournalCorrectionEligibility $eligibility,
        private readonly VatPeriodLockService $vatPeriodLocks,
    ) {}

    /** @throws JournalCorrectionException When the entry cannot be corrected here */
    public function execute(JournalEntry $original, string $reason, string $correctionDate, ?int $userId = null): JournalCorrection
    {
        return DB::transaction(function () use ($original, $reason, $correctionDate, $userId): JournalCorrection {
            $locked = JournalEntry::withoutGlobalScopes()
                ->whereKey($original->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $locked->load(['lines' => fn ($query) => $query->orderBy('id')]); // copy in the original's line order

            $this->eligibility->assertCorrectable($locked);
            $this->assertDateOpen($locked->organization_id, $correctionDate);

            $reversal = $this->ledger->createDraft($locked->organization_id, new JournalEntryData(
                date: $correctionDate,
                reference: (string) $locked->reference !== '' ? 'REV-'.$locked->reference : null,
                description: __('app.journal_correction_reversal_description', ['reference' => $locked->reference ?? $locked->description ?? '']),
                lines: $this->lines($locked, swap: true),
                type: 'reversal',
            ));
            $reversal->forceFill(['reversal_of_entry_id' => $locked->id])->save();

            $replacement = $this->ledger->createDraft($locked->organization_id, new JournalEntryData(
                date: $correctionDate,
                reference: null,
                description: $locked->description,
                lines: $this->lines($locked, swap: false),
                type: $locked->type,
            ));

            return JournalCorrection::create([
                'organization_id' => $locked->organization_id,
                'original_journal_entry_id' => $locked->id,
                'reversal_journal_entry_id' => $reversal->id,
                'replacement_journal_entry_id' => $replacement->id,
                'status' => JournalCorrectionStatus::Draft,
                'reason' => $reason,
                'user_id' => $userId,
            ]);
        });
    }

    /** @throws JournalCorrectionException When the date is in a closed year or a settled VAT period */
    private function assertDateOpen(string $organizationId, string $date): void
    {
        try {
            $this->ledger->guardClosedFiscalYear($organizationId, $date);
        } catch (FiscalYearClosedException) {
            throw JournalCorrectionException::closedYear();
        }

        if ($this->vatPeriodLocks->isLocked($organizationId, $date)) {
            throw JournalCorrectionException::settledVatPeriod();
        }
    }

    /** @return list<JournalLineData> */
    private function lines(JournalEntry $original, bool $swap): array
    {
        return array_values($original->lines->map(fn (TransactionLine $line): JournalLineData => new JournalLineData(
            accountId: (string) $line->account_id,
            debit: (string) ($swap ? $line->credit : $line->debit),
            credit: (string) ($swap ? $line->debit : $line->credit),
            description: $line->description,
        ))->all());
    }
}

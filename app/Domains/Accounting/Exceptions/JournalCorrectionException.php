<?php

namespace App\Domains\Accounting\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A guided correction cannot be prepared, changed, posted or discarded; the
 * message is shown to the user.
 */
class JournalCorrectionException extends DomainException
{
    public static function notCorrectable(string $reason): self
    {
        return new self(__('app.journal_correction_not_possible', ['reason' => $reason]));
    }

    public static function notDraft(): self
    {
        return new self(__('app.journal_correction_not_draft'));
    }

    public static function closedYear(): self
    {
        return new self(__('app.journal_correction_date_closed'));
    }

    public static function settledVatPeriod(): self
    {
        return new self(__('app.journal_correction_date_vat_settled'));
    }
}

<?php

namespace App\Domains\Accounting\Enums;

/**
 * A correction is a draft (reversal and replacement prepared, no ledger
 * effect) until both entries are posted together; a draft is posted or
 * discarded, a posted correction is final.
 */
enum JournalCorrectionStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
}

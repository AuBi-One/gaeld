<?php

namespace App\Domains\Payroll\Exceptions;

use DomainException;

/**
 * A salary slip posts only within the month it pays. The form says so too,
 * but the ledger does not refuse an entry in an already settled VAT period
 * (only a closed fiscal year), so posting enforces it for every caller.
 */
final class PostingDateOutsidePeriodException extends DomainException {}

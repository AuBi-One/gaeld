<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Models\Organization;

/**
 * Generates sequential invoice numbers scoped to an organization and year.
 *
 * Default format: {PREFIX}-YYYY-NNN (e.g. INV-2026-001, CN-2026-001).
 */
class InvoiceNumberGenerator
{
    /**
     * Generate the next invoice number for a given organization.
     *
     * Format: {PREFIX}-YYYY-NNN (e.g. INV-2026-001, CN-2026-001)
     *
     * Invoice numbers (the configured invoice prefix) advance by the organization's
     * invoice_number_step (default 1): with step 6 the first number of a year is
     * INV-YYYY-006 and the one after INV-YYYY-001 is INV-YYYY-007. Other prefixes
     * (credit notes) stay consecutive.
     *
     * Pass $forYear to pin the year (e.g. for a recurring invoice scheduled in a past year
     * that is generated today). Defaults to the current year when null.
     */
    public function next(string $organizationId, ?string $prefix = null, ?int $forYear = null): string
    {
        $year = $forYear ?? now()->year;
        $invoicePrefix = config('accounting.invoice_number_prefix', 'INV');
        $configPrefix = $prefix ?? $invoicePrefix;
        $fullPrefix = "{$configPrefix}-{$year}-";
        $prefixLen = strlen($fullPrefix);

        $maxSequence = Invoice::where('organization_id', $organizationId)
            ->where('number', 'like', "{$fullPrefix}%")
            ->withTrashed()
            ->pluck('number')
            ->map(fn (string $number) => (int) substr($number, $prefixLen))
            ->max() ?? 0;

        $step = $configPrefix === $invoicePrefix ? $this->invoiceStep($organizationId) : 1;
        $next = $maxSequence + $step;

        return $fullPrefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    private function invoiceStep(string $organizationId): int
    {
        $step = Organization::query()->whereKey($organizationId)->value('invoice_number_step');

        return max(1, (int) ($step ?? 1));
    }
}

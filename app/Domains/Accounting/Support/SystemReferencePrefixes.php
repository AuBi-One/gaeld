<?php

namespace App\Domains\Accounting\Support;

use App\Domains\Accounting\Rules\NotSystemReference;

/**
 * The reference prefixes Gäld gives its own journal entries.
 *
 * Several are how the system recognises its entries again: `REV-` + a
 * reference is that entry's reversal (VAT period locks, invoice cleanup),
 * `VAT-SETTLEMENT-` a settled VAT period, `OPENING-` the opening balances.
 * A hand-entered `REV-VAT-SETTLEMENT-…` would unlock a settled period, so
 * manual references must not start with any of them
 * ({@see NotSystemReference}).
 *
 * The one list: core prefixes below, plugins add theirs with {@see extend()}
 * (singleton, like JournalEntryReferences). Suffixes such as `-vN` on a
 * re-posted settlement or invoice entry keep their prefix and need no entry.
 */
final class SystemReferencePrefixes
{
    /** Core generators, by the class that writes them. */
    public const CORE = [
        'REV-',             // LedgerService::reverseEntry
        'VAT-SETTLEMENT-',  // PostVatSettlementAction (and the migration's TDFN settlements)
        'OPENING-',         // Record/GenerateOpeningBalancesAction, YearEndClosingAction (incl. OPENING-RESTATEMENT-)
        'HIST-',            // OpeningBalancesController historical summary (default)
        'DISP-',            // DisposeAssetAction
        'DEP-',             // DepreciateAssetAction
        'PAY-',             // PostPayrollAction
        'PAYDATE-',         // InvoiceAccountingService payment date change
        'EXP-',             // PostExpenseAction
        'EXPCN-',           // PostExpenseAction (credit note)
        'REC-',             // Invoice/ExpenseReconciler
        'BNK-',             // BankingService (transaction without a bank reference)
    ];

    /** @var list<string> */
    private array $extra = [];

    public function extend(string ...$prefixes): self
    {
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && ! in_array($prefix, [...self::CORE, ...$this->extra], true)) {
                $this->extra[] = $prefix;
            }
        }

        return $this;
    }

    /** @return list<string> */
    public function all(): array
    {
        return [...self::CORE, ...$this->extra];
    }

    /** The reserved prefix $reference starts with (case-insensitive), or null. */
    public function matching(string $reference): ?string
    {
        foreach ($this->all() as $prefix) {
            if (stripos($reference, $prefix) === 0) {
                return $prefix;
            }
        }

        return null;
    }
}

<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Services\ChartTemplateService;
use App\Domains\Accounting\Services\CurrencyConversionService;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Accounting\Services\VatPeriodLockService;
use App\Domains\Banking\Models\BankTransaction;
use App\Domains\Invoicing\DTOs\RecordPaymentData;
use App\Domains\Invoicing\Enums\InvoiceLineType;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Enums\PaymentMethod;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Exceptions\InvalidPaymentException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoiceLine;
use App\Domains\Invoicing\Models\InvoicePayment;
use App\Support\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Handles write operations for invoice accounting: posting ledger entries
 * and recording payments. Coordinates with LedgerService and applies
 * Swiss rounding to totals.
 */
class InvoiceAccountingService
{
    public function __construct(
        private LedgerService $ledgerService,
        private LedgerQueryService $ledgerQuery,
        private ChartTemplateService $chartTemplates,
        private VatPeriodLockService $vatPeriodLocks,
        private CurrencyConversionService $currencies,
    ) {}

    /**
     * Post the ledger entry for an invoice.
     *
     * Accounting effect (multi-VAT aware):
     *   Debit  1100 Accounts Receivable  (invoice total incl. VAT)
     *   Credit revenue account            (net amount per VAT group; the organisation's
     *                                      setting, by default 3400 Revenue from Services)
     *   Credit 2200 VAT Output Tax        (VAT amount per VAT group)
     *
     * For credit notes, debit/credit are reversed, and the revenue is taken back from
     * the account the original invoice credited:
     *   Credit 1100 Accounts Receivable
     *   Debit  revenue account
     *   Debit  2200 VAT Output Tax
     *
     * Marks the invoice as Sent.
     */
    public function postToLedger(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $orgId = $invoice->organization_id;
            // A second (stale or concurrent) finalization must not book the invoice twice.
            $stored = Invoice::query()->withoutGlobalScopes()->whereKey($invoice->id)->lockForUpdate()->first();
            if ($stored === null || $stored->status !== InvoiceStatus::Draft) {
                throw new InvalidInvoiceStateException('Only a draft invoice can be posted to the ledger.');
            }
            $invoice->setRawAttributes($stored->getAttributes(), true);
            $invoice->load('lines.vatRate', 'organization');

            $isCreditNote = $invoice->type === InvoiceType::CreditNote;
            $ledgerCurrency = strtoupper((string) ($invoice->organization->currency ?: 'CHF'));
            $documentCurrency = strtoupper((string) ($invoice->currency ?: $ledgerCurrency));
            $rate = $this->currencies->rate(
                $orgId,
                $documentCurrency,
                $ledgerCurrency,
                $invoice->issue_date->toDateString(),
            );
            $toLedger = fn (string $amount): string => Money::round(Money::multiply($amount, $rate));

            $ar = $this->ledgerQuery->resolveAccount($orgId, AccountCode::ACCOUNTS_RECEIVABLE);
            $revenue = $this->revenueAccount($invoice, $isCreditNote);

            $lines = [];

            // Group invoice lines by VAT rate to create separate revenue + VAT entries.
            // The receivable is the sum of those converted lines so rounding cannot
            // leave the entry unbalanced.
            $groupedByVat = $invoice->lines->groupBy(fn ($line) => $line->vat_rate_id ?? 'none');

            foreach ($groupedByVat as $vatRateId => $invoiceLines) {
                ['netAmount' => $netAmount, 'vatAmount' => $vatAmount] = $this->calculateGroupTotals($invoiceLines);
                $netAmount = $toLedger($netAmount);
                $vatAmount = $toLedger($vatAmount);

                // Revenue line: Credit for invoice, Debit for credit note
                if (! Money::isZero($netAmount)) {
                    $vatLabel = $vatRateId !== 'none' && $invoiceLines->first()->vatRate
                        ? " ({$invoiceLines->first()->vatRate->name})"
                        : '';
                    $lines[] = new JournalLineData(
                        accountId: (string) $revenue->id,
                        debit: $isCreditNote !== Money::isNegative($netAmount) ? Money::absoluteAmount($netAmount) : '0',
                        credit: $isCreditNote !== Money::isNegative($netAmount) ? '0' : Money::absoluteAmount($netAmount),
                        description: "Revenue{$vatLabel}",
                    );
                }

                // VAT line: Credit for invoice, Debit for credit note
                if (! Money::isZero($vatAmount)) {
                    $vatOutputAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::VAT_OUTPUT);
                    $rateName = $invoiceLines->first()->vatRate->name ?? 'VAT';
                    $lines[] = new JournalLineData(
                        accountId: (string) $vatOutputAccount->id,
                        debit: $isCreditNote !== Money::isNegative($vatAmount) ? Money::absoluteAmount($vatAmount) : '0',
                        credit: $isCreditNote !== Money::isNegative($vatAmount) ? '0' : Money::absoluteAmount($vatAmount),
                        description: "VAT Output — {$rateName}",
                    );
                }
            }

            $invoiceTotal = '0';
            foreach ($lines as $line) {
                $invoiceTotal = Money::add($invoiceTotal, Money::subtract($line->credit, $line->debit));
            }
            array_unshift($lines, new JournalLineData(
                accountId: (string) $ar->id,
                debit: Money::isPositive($invoiceTotal) ? $invoiceTotal : '0',
                credit: Money::isNegative($invoiceTotal) ? Money::absoluteAmount($invoiceTotal) : '0',
                description: 'Accounts Receivable',
            ));

            $docType = $isCreditNote ? 'Credit Note' : 'Invoice';
            $fxNote = $documentCurrency === $ledgerCurrency
                ? ''
                : sprintf(' [%s %s @ %s %s]', $invoice->total, $documentCurrency, $rate, $ledgerCurrency);
            // Finalized again after a revert to draft: the reversed entry keeps the number, so
            // this posting takes {number}-v2, -v3, ... and its date follows correctionDate().
            $issueDate = $invoice->issue_date->toDateString();
            $journalEntry = $this->ledgerService->postEntry($orgId, new JournalEntryData(
                date: $invoice->journal_entry_id === null ? $issueDate : $this->correctionDate($orgId, $issueDate),
                reference: $this->ledgerQuery->nextReferenceAfterReversals($orgId, (string) $invoice->number),
                description: "{$docType} {$invoice->number} — ".($invoice->customer->name ?? 'N/A').$fxNote,
                lines: $lines,
            ));

            // Create VatEntry records for the VAT report
            foreach ($groupedByVat as $vatRateId => $invoiceLines) {
                if ($vatRateId === 'none') {
                    continue;
                }

                ['netAmount' => $netAmount, 'vatAmount' => $vatAmount] = $this->calculateGroupTotals($invoiceLines);
                $netAmount = $toLedger($netAmount);
                $vatAmount = $toLedger($vatAmount);

                if (! Money::isZero($vatAmount)) {
                    VatEntry::create([
                        'journal_entry_id' => $journalEntry->id,
                        'vat_rate_id' => $vatRateId,
                        'base_amount' => $isCreditNote ? Money::negate($netAmount) : $netAmount,
                        'vat_amount' => $isCreditNote ? Money::negate($vatAmount) : $vatAmount,
                        'type' => VatEntryType::Output,
                    ]);
                }
            }

            $invoice->update([
                'status' => InvoiceStatus::Sent,
                'journal_entry_id' => $journalEntry->id,
            ]);

            return $invoice->fresh(['lines', 'customer', 'journalEntry.lines']);
        });
    }

    /**
     * The account the revenue lines go to. A credit note reverses the revenue of its
     * invoice on the account that invoice credited (the setting may have changed since).
     * A missing configured account is created (only that one), so an organisation whose
     * chart lacks it can still finalise.
     */
    private function revenueAccount(Invoice $invoice, bool $isCreditNote): Account
    {
        if ($isCreditNote && $invoice->related_invoice_id !== null) {
            $originalEntryId = Invoice::query()->withoutGlobalScopes()
                ->where('organization_id', $invoice->organization_id)
                ->whereKey($invoice->related_invoice_id)
                ->value('journal_entry_id');
            $account = $this->creditedRevenueAccount($invoice->organization_id, $originalEntryId);
            if ($account !== null) {
                return $account;
            }
        }

        return $this->chartTemplates->ensureRevenueAccount($invoice->organization);
    }

    /** The revenue account an invoice's journal entry credited (the largest credit). */
    private function creditedRevenueAccount(string $organizationId, ?string $journalEntryId): ?Account
    {
        $accountId = $journalEntryId === null ? null : TransactionLine::query()
            ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
            ->where('transaction_lines.journal_entry_id', $journalEntryId)
            ->where('accounts.organization_id', $organizationId)
            ->where('accounts.type', AccountType::Revenue->value)
            ->where('transaction_lines.credit', '>', 0)
            ->orderByDesc('transaction_lines.credit')
            ->value('transaction_lines.account_id');

        return $accountId === null ? null : Account::query()->withoutGlobalScopes()->whereKey($accountId)->first();
    }

    /**
     * The date a correction of an issued invoice (the reversal on a revert to draft, the new
     * posting on finalizing again) is posted on: $date itself while its VAT period is not
     * settled and its fiscal year is open, so that period shows the corrected invoice only;
     * otherwise today, and the settled period stays as filed.
     */
    public function correctionDate(string $organizationId, string $date): string
    {
        if ($this->vatPeriodLocks->isLocked($organizationId, $date)
            || $this->ledgerService->isFiscalYearClosedOn($organizationId, $date)) {
            return now()->toDateString();
        }

        return $date;
    }

    /**
     * @param  iterable<int, InvoiceLine>  $invoiceLines
     * @return array{netAmount: numeric-string, vatAmount: numeric-string}
     */
    private function calculateGroupTotals(iterable $invoiceLines): array
    {
        $netAmount = '0';
        $vatAmount = '0';

        foreach ($invoiceLines as $line) {
            if ($line->type === InvoiceLineType::Text) {
                continue;
            }

            $lineAmount = Money::absoluteAmount((string) $line->amount);
            $lineVatAmount = Money::absoluteAmount((string) ($line->vat_amount ?? '0'));

            if ($line->type === InvoiceLineType::Discount) {
                $netAmount = Money::subtract($netAmount, $lineAmount);
                $vatAmount = Money::subtract($vatAmount, $lineVatAmount);
            } else {
                $netAmount = Money::add($netAmount, $lineAmount);
                $vatAmount = Money::add($vatAmount, $lineVatAmount);
            }
        }

        return [
            'netAmount' => $netAmount,
            'vatAmount' => $vatAmount,
        ];
    }

    /**
     * Record a payment for an invoice with full payment tracking.
     *
     * Creates an InvoicePayment record and posts to ledger:
     *   Debit: Bank Account (1020)
     *   Credit: Accounts Receivable (1100)
     *
     * Supports partial payments. Invoice status is updated to PAID
     * when the full amount has been received.
     *
     * @throws ModelNotFoundException When account code not found
     */
    public function recordPayment(Invoice $invoice, RecordPaymentData $data): InvoicePayment
    {
        // A write-off is no money received: writeOffRemainder() books it.
        if ($data->paymentMethod === PaymentMethod::WriteOff) {
            throw new InvalidPaymentException('A write-off is not a payment.');
        }

        $bankAccountCode = $data->bankAccountCode ?? AccountCode::BANK_CASH;

        return DB::transaction(function () use ($invoice, $data, $bankAccountCode) {
            $orgId = $invoice->organization_id;

            $bankAccount = $this->ledgerQuery->resolveAccount($orgId, $bankAccountCode);
            $accountsReceivable = $this->ledgerQuery->resolveAccount($orgId, AccountCode::ACCOUNTS_RECEIVABLE);

            $invoice->loadMissing('organization');
            $ledgerCurrency = strtoupper((string) ($invoice->organization->currency ?: 'CHF'));
            $documentCurrency = strtoupper((string) ($invoice->currency ?: $ledgerCurrency));
            $receivableAmount = $data->amount;
            $ledgerAmount = $data->amount;
            $fxNote = '';

            if ($documentCurrency !== $ledgerCurrency) {
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
                $invoice->load('journalEntry.lines', 'payments.journalEntry.lines');
                $amountDue = $invoice->amountDue();
                if (! Money::isPositive($data->amount) || Money::compare($data->amount, $amountDue) > 0) {
                    throw new InvalidPaymentException('Payment must be positive and cannot exceed the invoice balance.');
                }
                if ($invoice->journalEntry === null) {
                    throw new InvalidPaymentException('Cannot settle a foreign invoice without its original ledger entry.');
                }

                $remainingReceivable = '0.00';
                $hasReceivable = false;
                foreach ($invoice->journalEntry->lines as $line) {
                    if ((string) $line->account_id === (string) $accountsReceivable->id) {
                        $hasReceivable = true;
                        $remainingReceivable = Money::add($remainingReceivable, Money::subtract($line->debit, $line->credit));
                    }
                }
                if (! $hasReceivable) {
                    throw new InvalidPaymentException('Cannot settle a foreign invoice without its original receivable posting.');
                }
                foreach ($invoice->payments as $payment) {
                    if ($payment->journalEntry === null) {
                        throw new InvalidPaymentException('Cannot settle a foreign invoice with a payment missing its ledger entry.');
                    }
                    foreach ($payment->journalEntry->lines as $line) {
                        if ((string) $line->account_id === (string) $accountsReceivable->id) {
                            $remainingReceivable = Money::subtract($remainingReceivable, Money::subtract($line->credit, $line->debit));
                        }
                    }
                }
                $receivableAmount = Money::compare($data->amount, $amountDue) === 0
                    ? $remainingReceivable
                    : Money::divideRounded(Money::multiply($remainingReceivable, $data->amount), $amountDue);

                try {
                    $converted = $this->currencies->convert($orgId, $documentCurrency, $ledgerCurrency, $data->amount, $data->paymentDate);
                } catch (\DomainException $exception) {
                    throw new InvalidPaymentException($exception->getMessage(), previous: $exception);
                }
                $ledgerAmount = $converted['amount'];
                $fxNote = sprintf(' [%s %s @ %s]', $data->amount, $documentCurrency, $converted['rate']);
            }

            $lines = [
                new JournalLineData(accountId: (string) $bankAccount->id, debit: $ledgerAmount, credit: '0', description: 'Bank deposit'),
                new JournalLineData(accountId: (string) $accountsReceivable->id, debit: '0', credit: $receivableAmount, description: 'Clear receivable'),
            ];
            $difference = Money::subtract($ledgerAmount, $receivableAmount);
            if (! Money::isZero($difference)) {
                try {
                    $financialExpenses = $this->ledgerQuery->resolveAccount($orgId, '6900');
                } catch (ModelNotFoundException $exception) {
                    throw new InvalidPaymentException('Add financial expenses account 6900 before recording a payment with an exchange difference.', previous: $exception);
                }
                $lines[] = new JournalLineData(
                    accountId: (string) $financialExpenses->id,
                    debit: Money::isNegative($difference) ? Money::absoluteAmount($difference) : '0',
                    credit: Money::isPositive($difference) ? $difference : '0',
                    description: 'Realized exchange difference',
                );
            }

            // A removed payment leaves its reversed entry under its reference.
            $paymentRef = $data->reference
                ?? $this->ledgerQuery->nextFreeReference($orgId, 'PAY-'.$invoice->number.'-'.($invoice->payments()->count() + 1));
            $journalEntry = $this->ledgerService->postEntry($orgId, new JournalEntryData(
                date: $data->paymentDate,
                reference: $paymentRef,
                description: "Payment received for {$invoice->number}{$fxNote}",
                lines: $lines,
            ));

            $payment = InvoicePayment::create([
                'organization_id' => $orgId,
                'invoice_id' => $invoice->id,
                'journal_entry_id' => $journalEntry->id,
                'amount' => $data->amount,
                'payment_date' => $data->paymentDate,
                'payment_method' => $data->paymentMethod->value,
                'reference' => $paymentRef,
            ]);

            // Check if invoice is fully paid
            if ($invoice->fresh()->isFullyPaid()) {
                $invoice->update(['status' => InvoiceStatus::Paid]);
            }

            return $payment->load('journalEntry');
        });
    }

    /**
     * Settle the open rest of an invoice nobody will pay (cash discount, rounding, bad debt) as a
     * reduction of its revenue, booked the way the invoice itself was booked. The rest is split
     * over the invoice's VAT rates in proportion to their gross amounts:
     *   Debit  the revenue account the invoice credited  (net share per rate)
     *   Debit  2200 VAT Output Tax                       (VAT share per rate, with a negative
     *                                                     output VAT entry for the VAT report)
     *   Credit 1100 Accounts Receivable                  (the rest)
     * The VAT share leaves the output tax of the VAT period of $date, as a reduction of the
     * consideration does; with the net tax rate method the period's settlement then moves the
     * lower 2200 balance. Recorded as a payment of the kind "write-off", so the invoice counts as
     * settled everywhere. Only for an invoice in the ledger currency. The rest is the open amount
     * less the posted credit notes for the invoice; when they cover it, the invoice is marked paid
     * without a booking and null is returned.
     */
    public function writeOffRemainder(Invoice $invoice, string $date, string $reason): ?InvoicePayment
    {
        return DB::transaction(function () use ($invoice, $date, $reason): ?InvoicePayment {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $orgId = $invoice->organization_id;
            if ($invoice->type !== InvoiceType::Invoice
                || ! in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Overdue], true)) {
                throw new InvalidInvoiceStateException(__('app.write_off_needs_open_invoice'));
            }
            $rest = $invoice->amountDue();
            if (! Money::isPositive($rest)) {
                throw new InvalidPaymentException(__('app.write_off_nothing_open'));
            }
            // A credit note already reduced revenue, VAT and the receivable, but not the invoice's
            // open amount: only what it leaves is written off. Nothing left: the invoice is settled.
            $credited = Money::absoluteAmount((string) Invoice::query()->withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('related_invoice_id', $invoice->id)
                ->where('type', InvoiceType::CreditNote)
                ->whereNotNull('journal_entry_id')
                ->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Cancelled])
                ->sum('total'));
            $rest = Money::subtract($rest, $credited);
            if (! Money::isPositive($rest)) {
                $invoice->update(['status' => InvoiceStatus::Paid]);

                return null;
            }
            $invoice->load('lines.vatRate', 'organization');
            $ledgerCurrency = strtoupper((string) ($invoice->organization->currency ?: 'CHF'));
            if (strtoupper((string) ($invoice->currency ?: $ledgerCurrency)) !== $ledgerCurrency) {
                throw new InvalidPaymentException(__('app.write_off_foreign_currency'));
            }

            $receivable = $this->ledgerQuery->resolveAccount($orgId, AccountCode::ACCOUNTS_RECEIVABLE);
            $revenue = $this->creditedRevenueAccount($orgId, $invoice->journal_entry_id)
                ?? $this->chartTemplates->ensureRevenueAccount($invoice->organization);

            // Booked like the original posting: VAT off 2200 only if it credited 2200, VAT entries
            // only for the rates it has VAT entries for (a migrated invoice may have none).
            $vatOutput = $this->ledgerQuery->resolveAccount($orgId, AccountCode::VAT_OUTPUT);
            $originalHasVatLines = $invoice->journal_entry_id !== null && TransactionLine::query()
                ->where('journal_entry_id', $invoice->journal_entry_id)
                ->where('account_id', $vatOutput->id)
                ->exists();
            $originalVatRates = $invoice->journal_entry_id === null ? [] : VatEntry::query()
                ->where('journal_entry_id', $invoice->journal_entry_id)
                ->pluck('vat_rate_id')
                ->map(fn ($id): string => (string) $id)
                ->all();

            $lines = [];
            $vatShares = [];
            foreach ($this->writeOffShares($invoice, $rest) as $share) {
                if (! $originalHasVatLines) {
                    $share['net'] = Money::add($share['net'], $share['vat']);
                    $share['vat'] = '0.00';
                }
                $label = $share['name'] !== null ? " ({$share['name']})" : '';
                if (! Money::isZero($share['net'])) {
                    $lines[] = new JournalLineData(
                        accountId: (string) $revenue->id,
                        debit: Money::isPositive($share['net']) ? $share['net'] : '0',
                        credit: Money::isNegative($share['net']) ? Money::absoluteAmount($share['net']) : '0',
                        description: "Write-off{$label}",
                    );
                }
                if (Money::isPositive($share['vat'])) {
                    $lines[] = new JournalLineData(
                        accountId: (string) $vatOutput->id,
                        debit: $share['vat'],
                        credit: '0',
                        description: "VAT Output — {$share['name']}",
                    );
                    if (in_array((string) $share['vat_rate_id'], $originalVatRates, true)) {
                        $vatShares[] = $share;
                    }
                }
            }
            $lines[] = new JournalLineData(accountId: (string) $receivable->id, debit: '0', credit: $rest, description: 'Clear receivable');

            $entry = $this->ledgerService->postEntry($orgId, new JournalEntryData(
                date: $date,
                reference: $this->ledgerQuery->nextFreeReference($orgId, 'WO-'.$invoice->number),
                description: "Write-off of the rest of {$invoice->number}: {$reason}",
                lines: $lines,
            ));

            foreach ($vatShares as $share) {
                VatEntry::create([
                    'journal_entry_id' => $entry->id,
                    'vat_rate_id' => $share['vat_rate_id'],
                    'base_amount' => Money::negate($share['net']),
                    'vat_amount' => Money::negate($share['vat']),
                    'type' => VatEntryType::Output,
                ]);
            }

            $payment = InvoicePayment::create([
                'organization_id' => $orgId,
                'invoice_id' => $invoice->id,
                'journal_entry_id' => $entry->id,
                'amount' => $rest,
                'payment_date' => $date,
                'payment_method' => PaymentMethod::WriteOff->value,
                'reference' => Str::limit($reason, 100, ''),
            ]);

            $invoice->update(['status' => InvoiceStatus::Paid]);

            return $payment->load('journalEntry');
        });
    }

    /**
     * The rest of an invoice split over its VAT rates by their gross amount; the last rate takes
     * what rounding leaves, so the shares add up to the rest.
     *
     * @return list<array{vat_rate_id: string|null, name: string|null, net: string, vat: string}>
     */
    private function writeOffShares(Invoice $invoice, string $rest): array
    {
        $groups = [];
        foreach ($invoice->lines->groupBy(fn ($line) => $line->vat_rate_id ?? 'none') as $vatRateId => $invoiceLines) {
            ['netAmount' => $net, 'vatAmount' => $vat] = $this->calculateGroupTotals($invoiceLines);
            $gross = Money::add($net, $vat);
            if (Money::isPositive($gross)) {
                $groups[] = [
                    'vat_rate_id' => $vatRateId === 'none' ? null : (string) $vatRateId,
                    'name' => $vatRateId === 'none' ? null : ($invoiceLines->first()->vatRate->name ?? 'VAT'),
                    'gross' => $gross,
                    'vat' => Money::isPositive($vat) ? $vat : '0.00',
                ];
            }
        }

        if ($groups === []) {
            return [['vat_rate_id' => null, 'name' => null, 'net' => Money::of($rest), 'vat' => '0.00']];
        }

        $grossTotal = array_reduce($groups, fn (string $sum, array $group): string => Money::add($sum, $group['gross']), '0.00');
        $left = $rest;
        $shares = [];
        foreach ($groups as $index => $group) {
            $share = $index === array_key_last($groups)
                ? $left
                : Money::divideRounded(Money::multiply($rest, $group['gross']), $grossTotal);
            $left = Money::subtract($left, $share);
            $vat = Money::divideRounded(Money::multiply($share, $group['vat']), $group['gross']);
            $shares[] = [
                'vat_rate_id' => $group['vat_rate_id'],
                'name' => $group['name'],
                'net' => Money::subtract($share, $vat),
                'vat' => $vat,
            ];
        }

        return $shares;
    }

    /**
     * Take back a payment or write-off entered by mistake: its entry is reversed (on its own date
     * while that period is open, else today; a write-off's VAT entries are reversed with it) and
     * the invoice is open again. A payment that came from the bank reconciliation is taken back
     * there, so the bank line stays in step.
     */
    public function removePayment(Invoice $invoice, InvoicePayment $payment): void
    {
        if ($payment->invoice_id !== $invoice->id || $payment->organization_id !== $invoice->organization_id) {
            throw new \DomainException('The payment does not belong to this invoice.');
        }

        if ($this->isFromBankReconciliation($payment)) {
            throw new \DomainException(__('app.payment_remove_bank_linked'));
        }

        DB::transaction(function () use ($invoice, $payment): void {
            $payment->loadMissing('journalEntry.lines');

            if ($payment->journalEntry !== null) {
                $reversal = $this->ledgerService->reverseEntry(
                    $payment->journalEntry,
                    "Removal of a payment for {$invoice->number}",
                    'payment_removal',
                    $this->correctionDate($payment->organization_id, $payment->payment_date->toDateString()),
                );
                $this->ledgerService->postDraft($reversal);
            }

            $payment->delete();

            $invoice->refresh();
            if ($invoice->status === InvoiceStatus::Paid && ! $invoice->isFullyPaid()) {
                $isOverdue = $invoice->due_date?->isBefore(now()->startOfDay()) ?? false;
                $invoice->update(['status' => $isOverdue ? InvoiceStatus::Overdue : InvoiceStatus::Sent]);
            }
        });
    }

    /**
     * Whether a bank line was reconciled with this payment (it is taken back there). The bank line
     * points at the payment's entry, or, for a payment whose date was corrected before the line
     * followed the correction, at its original entry (the one under the payment's reference).
     */
    public function isFromBankReconciliation(InvoicePayment $payment): bool
    {
        $entryIds = array_filter([$payment->journal_entry_id]);
        if ($payment->payment_method !== PaymentMethod::WriteOff && filled($payment->reference)) {
            $entryIds = [...$entryIds, ...JournalEntry::query()
                ->where('organization_id', $payment->organization_id)
                ->where('reference', $payment->reference)
                ->pluck('id')
                ->all()];
        }

        return $entryIds !== [] && BankTransaction::query()
            ->where('matched_invoice_id', $payment->invoice_id)
            ->whereIn('journal_entry_id', $entryIds)
            ->exists();
    }

    /**
     * Correct a payment date by reversing and reposting its ledger entry.
     */
    public function updatePaymentDate(Invoice $invoice, InvoicePayment $payment, string $paymentDate): InvoicePayment
    {
        if ($payment->invoice_id !== $invoice->id || $payment->organization_id !== $invoice->organization_id) {
            throw new \DomainException('The payment does not belong to this invoice.');
        }

        $payment->loadMissing('journalEntry.lines');

        if ($payment->journalEntry === null) {
            throw new \DomainException('Cannot correct a payment without a journal entry.');
        }

        // Reposting copies the lines, not the VAT entries of a write-off: it is removed and
        // written off again instead.
        if ($payment->payment_method === PaymentMethod::WriteOff) {
            throw new \DomainException(__('app.write_off_cannot_be_redated'));
        }

        $oldDate = $payment->payment_date->toDateString();
        if ($oldDate === $paymentDate) {
            return $payment->fresh(['journalEntry']);
        }

        $this->vatPeriodLocks->assertPeriodUnlocked($payment->organization_id, $oldDate, $oldDate);
        $this->vatPeriodLocks->assertPeriodUnlocked($payment->organization_id, $paymentDate, $paymentDate);

        return DB::transaction(function () use ($invoice, $payment, $paymentDate, $oldDate): InvoicePayment {
            $original = $payment->journalEntry;

            if ($original->archived_at !== null) {
                throw new \DomainException('Archived payment entries cannot be corrected.');
            }

            $reversal = $this->ledgerService->reverseEntry(
                $original,
                "Correction of payment date for {$invoice->number}",
                'payment_date_correction',
                $oldDate,
            );
            $this->ledgerService->postDraft($reversal);

            $replacement = $this->ledgerService->postEntry(
                $payment->organization_id,
                new JournalEntryData(
                    date: $paymentDate,
                    reference: 'PAYDATE-'.$payment->id.'-'.Str::lower(Str::replace('-', '', Str::uuid()->toString())),
                    description: "Payment received for {$invoice->number} (date corrected)",
                    lines: $original->lines->map(fn ($line) => new JournalLineData(
                        accountId: (string) $line->account_id,
                        debit: (string) $line->debit,
                        credit: (string) $line->credit,
                        description: $line->description,
                    ))->all(),
                ),
            );

            // A bank line reconciled with this payment follows it, so it stays linked to the
            // live entry (see isFromBankReconciliation()).
            BankTransaction::query()
                ->where('journal_entry_id', $original->id)
                ->update(['journal_entry_id' => $replacement->id]);

            $payment->update([
                'journal_entry_id' => $replacement->id,
                'payment_date' => $paymentDate,
            ]);

            return $payment->fresh(['journalEntry']);
        });
    }
}

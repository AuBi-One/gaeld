<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Services\ChartTemplateService;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Invoicing\DTOs\RecordPaymentData;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoicePayment;
use App\Support\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

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
            $invoice->load('lines.vatRate');

            $isCreditNote = $invoice->type === InvoiceType::CreditNote;

            $ar = $this->ledgerQuery->resolveAccount($orgId, AccountCode::ACCOUNTS_RECEIVABLE);
            $revenue = $this->revenueAccount($invoice, $isCreditNote);

            $lines = [];

            // For credit notes, amounts are negative — use absolute values and swap debit/credit
            $invoiceTotal = $isCreditNote
                ? Money::absoluteAmount((string) $invoice->total)
                : (string) $invoice->total;

            // AR line: Debit for invoice, Credit for credit note
            $lines[] = new JournalLineData(
                accountId: (string) $ar->id,
                debit: $isCreditNote ? '0' : $invoiceTotal,
                credit: $isCreditNote ? $invoiceTotal : '0',
                description: 'Accounts Receivable',
            );

            // Group invoice lines by VAT rate to create separate revenue + VAT entries
            $groupedByVat = $invoice->lines->groupBy(fn ($line) => $line->vat_rate_id ?? 'none');

            foreach ($groupedByVat as $vatRateId => $invoiceLines) {
                $netAmount = '0';
                $vatAmount = '0';

                foreach ($invoiceLines as $line) {
                    $netAmount = Money::add($netAmount, Money::absoluteAmount((string) $line->amount));
                    $vatAmount = Money::add($vatAmount, Money::absoluteAmount((string) ($line->vat_amount ?? '0')));
                }

                // Revenue line: Credit for invoice, Debit for credit note
                if (Money::isPositive($netAmount)) {
                    $vatLabel = $vatRateId !== 'none' && $invoiceLines->first()->vatRate
                        ? " ({$invoiceLines->first()->vatRate->name})"
                        : '';
                    $lines[] = new JournalLineData(
                        accountId: (string) $revenue->id,
                        debit: $isCreditNote ? $netAmount : '0',
                        credit: $isCreditNote ? '0' : $netAmount,
                        description: "Revenue{$vatLabel}",
                    );
                }

                // VAT line: Credit for invoice, Debit for credit note
                if (Money::isPositive($vatAmount)) {
                    $vatOutputAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::VAT_OUTPUT);
                    $rateName = $invoiceLines->first()->vatRate->name ?? 'VAT';
                    $lines[] = new JournalLineData(
                        accountId: (string) $vatOutputAccount->id,
                        debit: $isCreditNote ? $vatAmount : '0',
                        credit: $isCreditNote ? '0' : $vatAmount,
                        description: "VAT Output — {$rateName}",
                    );
                }
            }

            $docType = $isCreditNote ? 'Credit Note' : 'Invoice';
            $journalEntry = $this->ledgerService->postEntry($orgId, new JournalEntryData(
                date: $invoice->issue_date->toDateString(),
                reference: $invoice->number,
                description: "{$docType} {$invoice->number} — ".($invoice->customer->name ?? 'N/A'),
                lines: $lines,
            ));

            // Create VatEntry records for the VAT report
            foreach ($groupedByVat as $vatRateId => $invoiceLines) {
                if ($vatRateId === 'none') {
                    continue;
                }

                $netAmount = '0';
                $vatAmount = '0';
                foreach ($invoiceLines as $line) {
                    $netAmount = Money::add($netAmount, Money::absoluteAmount((string) $line->amount));
                    $vatAmount = Money::add($vatAmount, Money::absoluteAmount((string) ($line->vat_amount ?? '0')));
                }

                if (Money::isPositive($vatAmount)) {
                    VatEntry::create([
                        'journal_entry_id' => $journalEntry->id,
                        'vat_rate_id' => $vatRateId,
                        'base_amount' => $netAmount,
                        'vat_amount' => $vatAmount,
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
            $accountId = $originalEntryId === null ? null : TransactionLine::query()
                ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
                ->where('transaction_lines.journal_entry_id', $originalEntryId)
                ->where('accounts.organization_id', $invoice->organization_id)
                ->where('accounts.type', AccountType::Revenue->value)
                ->where('transaction_lines.credit', '>', 0)
                ->orderByDesc('transaction_lines.credit')
                ->value('transaction_lines.account_id');
            $account = $accountId === null ? null : Account::query()->withoutGlobalScopes()->whereKey($accountId)->first();
            if ($account !== null) {
                return $account;
            }
        }

        return $this->chartTemplates->ensureRevenueAccount($invoice->organization);
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
        $bankAccountCode = $data->bankAccountCode ?? AccountCode::BANK_CASH;

        return DB::transaction(function () use ($invoice, $data, $bankAccountCode) {
            $orgId = $invoice->organization_id;

            $bankAccount = $this->ledgerQuery->resolveAccount($orgId, $bankAccountCode);
            $accountsReceivable = $this->ledgerQuery->resolveAccount($orgId, AccountCode::ACCOUNTS_RECEIVABLE);

            $paymentRef = $data->reference ?? 'PAY-'.$invoice->number.'-'.($invoice->payments()->count() + 1);

            $journalEntry = $this->ledgerService->postEntry($orgId, new JournalEntryData(
                date: $data->paymentDate,
                reference: $paymentRef,
                description: "Payment received for {$invoice->number}",
                lines: [
                    new JournalLineData(accountId: (string) $bankAccount->id, debit: $data->amount, credit: '0', description: 'Bank deposit'),
                    new JournalLineData(accountId: (string) $accountsReceivable->id, debit: '0', credit: $data->amount, description: 'Clear receivable'),
                ],
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
}

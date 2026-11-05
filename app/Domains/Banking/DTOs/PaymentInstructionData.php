<?php

namespace App\Domains\Banking\DTOs;

use App\Domains\Expenses\Models\Expense;
use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Single payment instruction inside a pain.001 batch.
 *
 * Provider-agnostic: carries the data needed to build either an ISO 20022
 * pain.001 transaction (file download) or a bLink REST payment payload.
 */
readonly class PaymentInstructionData
{
    public function __construct(
        public string $endToEndId,
        public string $creditorName,
        public string $creditorIban,
        public string $amount,
        public string $currency,
        public Carbon $executionDate,
        public ?string $structuredReference,
        public ?string $unstructuredRemittance,
        public ?string $creditorBic = null,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
        // Creditor postal address (SPS 2025: structured, town and country required).
        public ?string $creditorStreet = null,
        public ?string $creditorPostalCode = null,
        public ?string $creditorTown = null,
        public ?string $creditorCountry = null,
    ) {}

    /**
     * SPS 2025: a creditor needs at least a town and an ISO country code, or the bank rejects the file.
     */
    public static function hasPostalAddress(?string $town, ?string $country): bool
    {
        return trim((string) $town) !== ''
            && preg_match('/^[A-Za-z]{2}$/', trim((string) $country)) === 1;
    }

    /**
     * The bank account holder when given, otherwise the contact name.
     */
    public static function creditorName(?string $accountHolder, string $name): string
    {
        $holder = trim((string) $accountHolder);

        return $holder !== '' ? $holder : $name;
    }

    public static function fromExpense(Expense $expense, ?Carbon $executionDate = null): self
    {
        $supplier = $expense->supplier;
        $iban = $supplier?->iban;

        if (! $supplier || ! $iban) {
            throw new \InvalidArgumentException("Expense {$expense->id} has no supplier IBAN.");
        }

        return new self(
            endToEndId: self::generateEndToEndId($expense->id),
            creditorName: self::creditorName($supplier->account_holder, $supplier->name),
            creditorIban: self::normalizeIban($iban),
            amount: number_format((float) $expense->amount, 2, '.', ''),
            currency: $expense->currency,
            executionDate: $executionDate ?? Carbon::tomorrow(),
            structuredReference: null,
            unstructuredRemittance: $expense->description ?? $expense->vendor ?? $expense->category,
            creditorBic: $supplier->bic,
            sourceType: 'expense',
            sourceId: $expense->id,
            creditorStreet: $supplier->address,
            creditorPostalCode: $supplier->postal_code,
            creditorTown: $supplier->city,
            creditorCountry: $supplier->country,
        );
    }

    public static function fromInvoice(Invoice $invoice, ?Carbon $executionDate = null): self
    {
        $supplier = $invoice->customer;
        $iban = $invoice->qr_iban ?: $supplier?->iban;

        if (! $supplier || ! $iban) {
            throw new \InvalidArgumentException("Invoice {$invoice->id} has no creditor IBAN.");
        }

        return new self(
            endToEndId: self::generateEndToEndId($invoice->id),
            creditorName: self::creditorName($supplier->account_holder, $supplier->name),
            creditorIban: self::normalizeIban($iban),
            amount: number_format((float) $invoice->total, 2, '.', ''),
            currency: $invoice->currency,
            executionDate: $executionDate ?? Carbon::tomorrow(),
            structuredReference: $invoice->qr_reference,
            unstructuredRemittance: $invoice->number ? "Invoice {$invoice->number}" : null,
            creditorBic: $supplier->bic,
            sourceType: 'invoice',
            sourceId: $invoice->id,
            creditorStreet: $supplier->address,
            creditorPostalCode: $supplier->postal_code,
            creditorTown: $supplier->city,
            creditorCountry: $supplier->country,
        );
    }

    public function isQrReference(): bool
    {
        return $this->structuredReference !== null
            && preg_match('/^\d{27}$/', $this->structuredReference) === 1;
    }

    public function isScorReference(): bool
    {
        return $this->structuredReference !== null
            && preg_match('/^RF\d{2}[A-Z0-9]{1,21}$/', $this->structuredReference) === 1;
    }

    private static function normalizeIban(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
    }

    private static function generateEndToEndId(string $sourceId): string
    {
        // pain.001 limits EndToEndId to 35 chars. Use short hash + short id slice.
        $short = substr(str_replace('-', '', $sourceId), 0, 12);

        return 'GAELD-'.$short.'-'.Str::upper(Str::random(8));
    }
}

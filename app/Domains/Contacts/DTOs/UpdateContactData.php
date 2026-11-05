<?php

namespace App\Domains\Contacts\DTOs;

use App\Support\AddressData;

/**
 * DTO for updating an existing unified contact record.
 */
readonly class UpdateContactData
{
    public function __construct(
        public string $name,
        public ?string $type = null,
        public ?AddressData $addressData = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $vatNumber = null,
        public ?string $defaultExpenseCategory = null,
        public ?string $currency = null,
        public ?string $iban = null,
        public ?string $bic = null,
        public ?string $accountHolder = null,
        public ?string $paymentTerms = null,
        public ?string $internalNotes = null,
        public ?string $notes = null,
        // The form sends an empty account holder to fall back to the contact name: keep the null.
        public bool $accountHolderGiven = false,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            type: $data['type'] ?? null,
            addressData: AddressData::fromArray($data),
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            vatNumber: $data['vat_number'] ?? null,
            defaultExpenseCategory: $data['default_expense_category'] ?? null,
            currency: $data['currency'] ?? null,
            iban: $data['iban'] ?? null,
            bic: $data['bic'] ?? null,
            accountHolder: $data['account_holder'] ?? null,
            paymentTerms: $data['payment_terms'] ?? null,
            internalNotes: $data['internal_notes'] ?? null,
            notes: $data['notes'] ?? null,
            accountHolderGiven: array_key_exists('account_holder', $data),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'type' => $this->type,
            'email' => $this->email,
            'phone' => $this->phone,
            'vat_number' => $this->vatNumber,
            'default_expense_category' => $this->defaultExpenseCategory,
            'currency' => $this->currency,
            'iban' => $this->iban,
            'bic' => $this->bic,
            'payment_terms' => $this->paymentTerms,
            'internal_notes' => $this->internalNotes,
            'notes' => $this->notes ? ['default' => $this->notes] : null,
        ] + ($this->addressData?->toArray() ?? AddressData::empty()->toArray()), fn ($value) => $value !== null)
            + ($this->accountHolderGiven ? ['account_holder' => $this->accountHolder] : []);
    }
}

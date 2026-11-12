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
        public ?string $salutation = null,
        public ?string $countryName = null,
        private bool $hasSalutation = false,
        private bool $hasCountryName = false,
        // The form sends an empty account holder to fall back to the contact name: keep the null.
        public bool $accountHolderGiven = false,
        /** @var list<string> the clearable fields the form sent: an emptied one is cleared */
        private array $clearableGiven = [],
    ) {}

    /** Optional columns an empty input clears; a field not sent keeps its value. */
    private const CLEARABLE = [
        'email', 'phone', 'vat_number', 'default_expense_category', 'iban', 'bic',
        'payment_terms', 'internal_notes', 'notes', 'address', 'city', 'postal_code',
    ];

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
            salutation: $data['salutation'] ?? null,
            countryName: $data['country_name'] ?? null,
            hasSalutation: array_key_exists('salutation', $data),
            hasCountryName: array_key_exists('country_name', $data),
            accountHolderGiven: array_key_exists('account_holder', $data),
            clearableGiven: array_values(array_intersect(self::CLEARABLE, array_keys($data))),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = array_filter([
            'name' => $this->name,
            'salutation' => $this->salutation,
            'country_name' => $this->countryName,
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
        ] + ($this->addressData?->toArray() ?? AddressData::empty()->toArray()),
            fn ($value, $key) => $value !== null || in_array($key, $this->clearableGiven, true),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($this->hasSalutation) {
            $data['salutation'] = $this->salutation;
        }

        if ($this->hasCountryName) {
            $data['country_name'] = $this->countryName;
        }

        if ($this->accountHolderGiven) {
            $data['account_holder'] = $this->accountHolder;
        }

        return $data;
    }
}

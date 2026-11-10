<?php

namespace App\Domains\Organizations\DTOs;

readonly class UpdateInvoiceSettingsData
{
    public function __construct(
        public ?string $invoiceHeaderText = null,
        public ?string $invoiceFooterText = null,
        public ?string $defaultInvoiceNotes = null,
        public ?int $invoiceNumberStep = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            invoiceHeaderText: $data['invoice_header_text'] ?? null,
            invoiceFooterText: $data['invoice_footer_text'] ?? null,
            defaultInvoiceNotes: $data['default_invoice_notes'] ?? null,
            invoiceNumberStep: isset($data['invoice_number_step']) ? (int) $data['invoice_number_step'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'invoice_header_text' => $this->invoiceHeaderText,
            'invoice_footer_text' => $this->invoiceFooterText,
            'default_invoice_notes' => $this->defaultInvoiceNotes,
        ];

        // Absent = keep the organisation's current step.
        if ($this->invoiceNumberStep !== null) {
            $data['invoice_number_step'] = $this->invoiceNumberStep;
        }

        return $data;
    }
}

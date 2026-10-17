<?php

namespace App\Domains\Invoicing\Requests\Concerns;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use App\Domains\Invoicing\Enums\InvoiceLineType;
use App\Domains\Invoicing\Enums\InvoiceTaxTreatment;
use App\Domains\Invoicing\Models\InvoiceLine;
use App\Domains\Invoicing\Services\InvoiceLineSources;
use Illuminate\Validation\Rule;

trait InvoiceValidationRules
{
    /** @return array<string, mixed> */
    protected function sharedRules(string $orgId, ?string $ignoreInvoiceId = null): array
    {
        $finalize = $this->boolean('finalize');

        return [
            'number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('invoices', 'number')
                    ->where('organization_id', $orgId)
                    ->when($ignoreInvoiceId !== null, fn ($rule) => $rule->ignore($ignoreInvoiceId)),
            ],
            'issue_date' => 'required|date',
            'due_date' => [$finalize ? 'required' : 'nullable', 'date', 'after_or_equal:issue_date'],
            'currency' => 'string|size:3',
            'tax_treatment' => [
                'nullable',
                Rule::enum(InvoiceTaxTreatment::class),
                function (string $attribute, mixed $value, \Closure $fail) use ($orgId): void {
                    if ($value !== InvoiceTaxTreatment::ReverseCharge->value) {
                        return;
                    }

                    $customer = Contact::withoutGlobalScope('organization')
                        ->where('organization_id', $orgId)
                        ->whereKey($this->input('customer_id'))
                        ->first();

                    if ($customer === null || ! InvoiceTaxTreatment::isEuCountry($customer->country)) {
                        $fail(__('app.invoice_reverse_charge_eu_customer_required'));
                    } elseif (! InvoiceTaxTreatment::hasValidEuVatNumber($customer->country, $customer->vat_number)) {
                        $fail(__('app.invoice_reverse_charge_vat_number_required'));
                    }
                },
            ],
            'notes' => 'nullable|string',
            'introduction' => 'nullable|string|max:5000',
            'payment_terms' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.type' => ['nullable', Rule::enum(InvoiceLineType::class)],
            'lines.*.discount_type' => ['nullable', 'in:flat,percentage'],
            'lines.*.description' => 'required|string',
            // A text line has no quantity (the form sends 0); an amount line needs one.
            'lines.*.quantity' => [
                'required_unless:lines.*.type,text',
                'numeric',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $type = $this->input(str_replace('.quantity', '.type', $attribute)) ?? 'item';
                    if ($type !== 'text' && (float) $value < 0.01) {
                        $fail('validation.min.numeric')->translate(['min' => '0.01']);
                    }
                },
            ],
            'lines.*.unit_price' => 'required_unless:lines.*.type,text|numeric',
            // Optional record the line was taken from (registered source, a record of this organisation).
            'lines.*.source_type' => ['nullable', 'string', 'max:50', 'required_with:lines.*.source_id'],
            'lines.*.source_id' => [
                'nullable',
                'string',
                'max:64',
                'required_with:lines.*.source_type',
                function (string $attribute, mixed $value, \Closure $fail) use ($orgId, $ignoreInvoiceId): void {
                    $type = $this->input(str_replace('.source_id', '.source_type', $attribute));
                    if (! is_string($type) || $type === '' || ! is_string($value) || $value === '') {
                        return;
                    }
                    $attributeName = __('app.line_items');
                    // Only amount lines are taken from a record (text and discount lines are not).
                    if (($this->input(str_replace('.source_id', '.type', $attribute)) ?? 'item') !== 'item') {
                        $fail(__('validation.prohibited', ['attribute' => $attributeName]));

                        return;
                    }
                    // A reference the invoice already has is kept even if its record is gone or no
                    // longer offered (e.g. the source's plugin was removed); new ones must be known.
                    $unchanged = $ignoreInvoiceId !== null && InvoiceLine::query()
                        ->where('invoice_id', $ignoreInvoiceId)->where('source_type', $type)->where('source_id', $value)->exists();
                    if (! $unchanged && ! app(InvoiceLineSources::class)->exists($orgId, $type, $value)) {
                        $fail(__('validation.exists', ['attribute' => $attributeName]));
                    }
                },
            ],
            'customer_id' => [
                $finalize ? 'required' : 'nullable',
                Rule::exists('contacts', 'id')->where('organization_id', $orgId),
            ],
            'contact_person_id' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, \Closure $fail) use ($orgId): void {
                    $belongsToCustomer = ContactPerson::query()
                        ->whereKey($value)
                        ->where('contactable_type', Contact::class)
                        ->where('contactable_id', $this->input('customer_id'))
                        ->whereHas('contactable', fn ($q) => $q->where('organization_id', $orgId))
                        ->exists();
                    if (! $belongsToCustomer) {
                        $fail(__('validation.exists', ['attribute' => $attribute]));
                    }
                },
            ],
            'lines.*.vat_rate_id' => [
                'nullable',
                Rule::exists('vat_rates', 'id')->where('organization_id', $orgId),
            ],
            'justificatif' => 'nullable|file|mimes:'.config('uploads.allowed_mimes.document').'|max:'.config('uploads.max_size.document'),
        ];
    }
}

<?php

namespace App\Domains\Invoicing\Requests;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Models\RecurringInvoice;
use App\Domains\Organizations\Services\CurrentOrganization;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecurringInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $recurring = $this->route('recurring');

        if ($this->isMethod('POST')) {
            return $this->user()?->can('create', RecurringInvoice::class) ?? false;
        }

        return $recurring instanceof RecurringInvoice
            && ($this->user()?->can('update', $recurring) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $orgId = app(CurrentOrganization::class)->id();

        $rules = [
            'customer_id' => [
                'required',
                Rule::exists('contacts', 'id')->where('organization_id', $orgId),
            ],
            'frequency' => ['required', 'string', 'in:weekly,monthly,quarterly,yearly'],
            'next_issue_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:next_issue_date'],
            'template_data' => ['required', 'array'],
            'template_data.lines' => ['required', 'array', 'min:1'],
            'template_data.lines.*.description' => ['required', 'string', 'max:500'],
            'template_data.lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'template_data.lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'template_data.notes' => ['nullable', 'string', 'max:2000'],
            'template_data.payment_terms' => ['nullable', 'string', 'max:255'],
            'template_data.currency' => ['nullable', 'string', 'size:3'],
            // The "Attention: ..." person of the generated invoices: one of the customer's own.
            'template_data.contact_person_id' => ['bail', 'nullable', 'uuid', function (string $attribute, mixed $value, Closure $fail) use ($orgId): void {
                $customer = Contact::query()->where('organization_id', $orgId)->whereKey($this->input('customer_id'))->first();
                if ($customer === null || ! $customer->contactPersons()->whereKey($value)->exists()) {
                    $fail(__('validation.exists', ['attribute' => __('app.contact_person')]));
                }
            }],
        ];

        if ($this->isMethod('POST')) {
            $rules['next_issue_date'][] = 'after_or_equal:today';
        }

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['is_active'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }
}

<?php

namespace App\Domains\Payroll\Requests;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Payroll\Queries\DeductionRateSetQuery;
use App\Support\FeatureFlag;
use App\Support\Rules\Iban;
use Illuminate\Validation\Rule;

trait EmployeeRules
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $currentOrganization = app(CurrentOrganization::class);
        // The code must name an existing rate set; once the organization has
        // one, every employee needs a code (no silent built-in defaults).
        $rateCodes = $currentOrganization->isBound() ? DeductionRateSetQuery::codes() : [];

        $rules = [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'iban' => ['nullable', 'string', 'max:34', new Iban],
            'ahv_number' => ['nullable', 'string', 'max:16'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before:today'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'entry_date' => ['required', 'date'],
            'exit_date' => ['nullable', 'date', 'after_or_equal:entry_date'],
            'gross_salary' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'deduction_rate_code' => [$rateCodes === [] ? 'nullable' : 'required', 'string', 'max:50', Rule::in($rateCodes)],
            'is_source_tax_subject' => ['boolean'],
            'has_thirteenth_salary' => ['boolean'],
        ];

        $withholdingTaxEnabled = $currentOrganization->isBound()
            ? FeatureFlag::enabledForOrg('withholding_tax', $currentOrganization->get())
            : FeatureFlag::enabled('withholding_tax');

        if ($withholdingTaxEnabled) {
            $rules += [
                'source_tax_canton' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
                'source_tax_tariff' => ['nullable', 'string', 'size:1', 'in:A,B,C,D,E,F,G,H'],
                'source_tax_municipality_code' => ['nullable', 'string', 'max:10'],
            ];
        }

        return $rules;
    }
}

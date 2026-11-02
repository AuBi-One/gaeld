<?php

namespace App\Domains\Payroll\Requests;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\SalarySlip;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeductionRateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();

        return [
            'deduction_rate_set_id' => [
                'required',
                'integer',
                Rule::exists('deduction_rate_sets', 'id')->where('organization_id', $organizationId),
            ],
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z0-9_]+$/',
                // Not a key the calculator writes itself (total_employee,
                // net_salary, ...), and its suffix is the line's side.
                Rule::notIn(SalarySlip::NON_RATE_DEDUCTION_KEYS),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! str_ends_with((string) $value, '_'.$this->input('type'))) {
                        $fail(__('validation.deduction_rate_code_suffix'));
                    }
                },
                Rule::unique('deduction_rates')
                    ->where('organization_id', $organizationId)
                    ->where('deduction_rate_set_id', $this->input('deduction_rate_set_id')),
            ],
            // decimal(6,4) column: at most 99.9999, four decimals.
            'rate' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99.9999'],
            'type' => ['required', 'string', Rule::in(['employee', 'employer'])],
            'account_id' => [
                'nullable',
                'integer',
                // Only liabilities: the mapped account is credited.
                Rule::exists('accounts', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('type', AccountType::Liability->value),
            ],
            'certificate_box' => ['nullable', 'string', Rule::in(DeductionRate::CERTIFICATE_BOXES)],
        ];
    }
}

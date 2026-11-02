<?php

namespace App\Domains\Payroll\Requests;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Payroll\Models\DeductionRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeductionRateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();
        $rate = $this->route('deductionRate');

        return [
            'name' => ['required', 'string', 'max:100'],
            // decimal(6,4) column: at most 99.9999, four decimals.
            'rate' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99.9999'],
            'is_active' => ['sometimes', 'boolean'],
            // The side is fixed by the code's suffix (StoreDeductionRateRequest).
            'type' => ['sometimes', 'string', Rule::in([$rate instanceof DeductionRate ? $rate->type : 'employee'])],
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

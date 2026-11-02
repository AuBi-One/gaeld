<?php

namespace App\Domains\Payroll\Requests;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Payroll\Models\DeductionRateSet;
use App\Domains\Payroll\Queries\DeductionRateSetQuery;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDeductionRateSetRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'date_from' => ['required', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var DeductionRateSet $set */
                $set = $this->route('deductionRateSet');
                $organizationId = app(CurrentOrganization::class)->id();

                $overlaps = DeductionRateSetQuery::overlapping($organizationId, $set->code, (string) $this->input('date_from'), $this->input('date_to') ?: null)
                    ->reject(fn (DeductionRateSet $other): bool => $other->is($set))
                    ->isNotEmpty();

                if ($overlaps) {
                    $validator->errors()->add('date_from', __('validation.deduction_rate_set_overlap'));
                }
            },
        ];
    }
}

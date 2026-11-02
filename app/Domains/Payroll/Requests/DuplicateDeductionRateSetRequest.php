<?php

namespace App\Domains\Payroll\Requests;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Payroll\Models\DeductionRateSet;
use App\Domains\Payroll\Queries\DeductionRateSetQuery;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class DuplicateDeductionRateSetRequest extends FormRequest
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

                /** @var DeductionRateSet $source */
                $source = $this->route('deductionRateSet');
                $organizationId = app(CurrentOrganization::class)->id();

                $from = (string) $this->input('date_from');
                // A new open-ended set closes the open-ended set starting earlier: not an overlap.
                $superseded = DeductionRateSetQuery::supersededBy($organizationId, $source->code, $from, $this->input('date_to') ?: null);
                $overlaps = DeductionRateSetQuery::overlapping($organizationId, $source->code, $from, $this->input('date_to') ?: null)
                    ->reject(fn (DeductionRateSet $set): bool => $set->is($superseded))
                    ->isNotEmpty();

                if ($overlaps) {
                    $validator->errors()->add('date_from', __('validation.deduction_rate_set_overlap'));
                }
            },
        ];
    }
}

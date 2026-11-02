<?php

namespace App\Domains\Payroll\Requests;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Payroll\Models\DeductionRateSet;
use App\Domains\Payroll\Queries\DeductionRateSetQuery;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeductionRateSetRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'title' => ['required', 'string', 'max:100'],
            'date_from' => ['required', 'date'],
            // Empty = open-ended: still valid until a newer set of the code starts.
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

                $organizationId = app(CurrentOrganization::class)->id();
                $code = (string) $this->input('code');
                $from = (string) $this->input('date_from');
                // A new open-ended set closes the open-ended set starting earlier: not an overlap.
                $superseded = DeductionRateSetQuery::supersededBy($organizationId, $code, $from, $this->input('date_to') ?: null);
                $overlaps = DeductionRateSetQuery::overlapping($organizationId, $code, $from, $this->input('date_to') ?: null)
                    ->reject(fn (DeductionRateSet $set): bool => $set->is($superseded))
                    ->isNotEmpty();

                if ($overlaps) {
                    $validator->errors()->add('date_from', __('validation.deduction_rate_set_overlap'));
                }
            },
        ];
    }
}

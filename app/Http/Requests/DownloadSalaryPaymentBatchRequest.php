<?php

namespace App\Http\Requests;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A salary payment file: posted slips not yet in a file, paid from one of the organisation's accounts.
 */
class DownloadSalaryPaymentBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // Building the file marks the salaries as paid: payroll edit rights, not only viewing.
        return $user !== null && $user->can('viewAny', BankAccount::class) && $user->hasPermissionTo(Permission::PayrollEdit);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();

        return [
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists('bank_accounts', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->where('currency', 'CHF')
                    ->whereNotNull('iban'),
            ],
            'salary_slip_ids' => ['required', 'array', 'min:1', 'max:500'],
            'salary_slip_ids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('salary_slips', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNotNull('posted_at')
                    ->whereNull('payment_file_at'),
            ],
            'execution_date' => ['required', 'date', 'after_or_equal:today'],
        ];
    }
}

<?php

namespace App\Domains\Accounting\Requests;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $journalEntry = $this->route('journalEntry');
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        if ($journalEntry instanceof JournalEntry) {
            $check = Gate::forUser($user)->inspect('update', $journalEntry);

            // An entry owned by another record (a denial with a message) is refused by the
            // controller, which names the owner.
            return $check->allowed() || $check->message() !== null;
        }

        return $user->can('create', JournalEntry::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = app(CurrentOrganization::class)->id();

        return [
            'date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_posted' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')
                    ->where('organization_id', $orgId)
                    ->where('is_active', true),
            ],
            'lines.*.debit' => ['required', 'numeric', 'min:0', 'max:99999999999.99'],
            'lines.*.credit' => ['required', 'numeric', 'min:0', 'max:99999999999.99'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'justificatif' => ['nullable', 'file', 'mimes:'.config('uploads.allowed_mimes.document'), 'max:'.config('uploads.max_size.document')],
        ];
    }
}

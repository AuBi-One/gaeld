<?php

namespace App\Domains\Invoicing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WriteOffInvoiceRemainderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordPayment', $this->route('invoice'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:100'],
        ];
    }
}

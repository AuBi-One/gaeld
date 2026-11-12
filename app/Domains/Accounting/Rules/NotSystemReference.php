<?php

namespace App\Domains\Accounting\Rules;

use App\Domains\Accounting\Support\SystemReferencePrefixes;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A hand-entered journal reference must not start with a prefix Gäld gives
 * its own entries ({@see SystemReferencePrefixes}). The reference an entry
 * already carries may stay, so editing such a draft keeps working.
 */
class NotSystemReference implements ValidationRule
{
    public function __construct(
        private readonly ?string $currentReference = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '' || $value === $this->currentReference) {
            return;
        }

        $prefix = app(SystemReferencePrefixes::class)->matching($value);
        if ($prefix !== null) {
            $fail(__('app.journal_reference_reserved_prefix', ['prefix' => $prefix]));
        }
    }
}

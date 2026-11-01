<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** 3–30 chars of a-z, 0-9, underscore; not reserved. Values are normalized to lowercase before validation. */
class Username implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[a-z0-9_]{3,30}$/', $value) !== 1) {
            $fail(__('api.validation.username_format'));

            return;
        }
        if (in_array($value, (array) config('linklibrary.usernames.reserved'), true)) {
            $fail(__('api.validation.username_reserved'));
        }
    }
}

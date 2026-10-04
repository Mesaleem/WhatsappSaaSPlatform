<?php

namespace App\Rules;

use App\Support\Security\OutboundUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Validation rule: a customer-supplied URL our servers will fetch must be a public http(s) URL (see OutboundUrlGuard). */
class PublicOutboundUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (app(OutboundUrlGuard::class)->violation($value) !== null) {
            $fail('The :attribute must be a public http(s) URL — private, loopback, link-local and internal addresses are not allowed.');
        }
    }
}

<?php

namespace App\Rules\Bcms;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ADR 0024 §3.1 — no operator-supplied template variable may itself contain
 * `{{` or `}}`.
 *
 * A value that did would be substituted into the body and then either trip
 * `TemplateRenderer`'s fail-closed check on the NEXT pass (a `{{` an
 * operator typed reading as an unresolved placeholder) or, worse, be
 * substituted a second time and pick up a later variable's value — the one
 * shape of injection this free-text field can carry.
 */
class NoTemplatePlaceholder implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (str_contains($value, '{{') || str_contains($value, '}}')) {
            $fail('The :attribute may not contain "{{" or "}}".');
        }
    }
}

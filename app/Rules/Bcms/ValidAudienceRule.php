<?php

namespace App\Rules\Bcms;

use App\Support\Bcms\AudienceRule;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * The G0 audience grammar (`AudienceRule`, ADR 0003), enforced at the
 * request boundary rather than only when `AudienceResolver` first tries to
 * read a malformed rule. `AudienceRule::fromArray()` is the single
 * validator — the same one `AlertService::estimate()`/`release()` use — so
 * a shape this rule accepts is a shape the rest of the pipeline already
 * knows how to resolve. It does NOT walk saved-group references for a
 * cycle: that needs a database read per group and belongs to
 * `AudienceResolver`'s own guard, unchanged by this request-level check.
 */
class ValidAudienceRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return; // the `array` rule already failed this
        }

        try {
            AudienceRule::fromArray($value);
        } catch (InvalidArgumentException $e) {
            $fail('The :attribute is not a valid audience rule: '.$e->getMessage());
        }
    }
}

<?php

namespace App\Http\Requests\Tprm;

use App\Enums\Tprm\RiskTier;
use App\Models\Tprm\Engagement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Overriding a computed tier — FR-TIER-04.
 *
 * The rationale and the expiry are both REQUIRED, and neither is a formality.
 * A rationale is what a risk committee reads six months later when it asks why
 * this vendor is being watched more closely than the model says; an expiry is
 * what stops the answer being "nobody remembers".
 *
 * `authorize()` asks for the engagement's own `overrideTier` ability rather
 * than a bare permission, so the tenancy check in the policy runs too — a
 * permission alone would let a holder override an engagement belonging to
 * another organisation if one ever reached the route.
 */
class OverrideTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof Engagement
            && ($this->user()?->can('overrideTier', $engagement) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tier' => ['required', Rule::in(RiskTier::values())],

            // Long enough that "risk accepted" does not pass. The number is a
            // judgement, but the intent is not: a one-word rationale is not a
            // rationale, and this is the only place the module can insist.
            'rationale' => ['required', 'string', 'min:20', 'max:2000'],

            'approver_role' => ['nullable', 'string', 'max:120'],

            // Mandatory, and bounded. An override running for five years is a
            // permanent exception wearing a date.
            'expires_at' => ['required', 'date', 'after:today', 'before_or_equal:'.now()->addYear()->toDateString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rationale.min' => 'Give a rationale a risk committee could act on — at least a sentence.',
            'expires_at.required' => 'An override must expire. Without an end date it is a permanent exception.',
            'expires_at.before_or_equal' => 'An override may run for at most a year before it is reconsidered.',
        ];
    }
}

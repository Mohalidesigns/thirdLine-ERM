<?php

namespace App\Http\Requests\Rcsa;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The reason on a review decision — §9.1's "every transition writes ... reason".
 *
 * MANDATORY ON A RETURN AND AN ESCALATION, optional on a validation, and that
 * asymmetry is the plan's: §9.2 says "Return for Rework (with mandatory
 * reason)". Sending somebody's quarter of work back without saying why is the
 * behaviour that makes people stop reading review comments; accepting it needs
 * no defence.
 *
 * Which of the two applies is decided by the ROUTE, not by a flag in the
 * payload — a client that omitted the flag would otherwise turn a mandatory
 * field optional.
 */
class ReviewDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller authorises against the specific ability (validate,
        // return, escalate), because they are three different permissions on
        // the same payload.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->routeIs('rcsa.review.return', 'rcsa.review.escalate');

        return [
            'reason' => [$required ? 'required' : 'nullable', 'string', 'min:10', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why. This is what the assessor reads when the assessment comes back to them.',
            'reason.min' => 'A few more words — enough for somebody to act on.',
        ];
    }
}

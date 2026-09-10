<?php

namespace App\Http\Requests\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
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
    /**
     * Authorised HERE, not only in the controller.
     *
     * A Form Request that returns true runs its RULES first, so a reviewer with
     * no authority over this business unit was answered with a 302 and a
     * validation error rather than a 403 — the request was still refused, but
     * the refusal said "you forgot the reason" to somebody who was never going
     * to be allowed to give one. Found by the route walk in
     * BusinessUnitScopeTest, which could not tell the two apart, and it is the
     * more honest ordering regardless: authority first, then the payload.
     *
     * The three abilities are three different permissions on the same payload,
     * so which one applies is decided by the ROUTE.
     */
    public function authorize(): bool
    {
        $assessment = $this->route('assessment');

        if (! $assessment instanceof RcsaAssessment) {
            return false;
        }

        $ability = match (true) {
            $this->routeIs('rcsa.review.validate') => 'validate',
            $this->routeIs('rcsa.review.return') => 'returnForRework',
            default => 'escalate',
        };

        return $this->user()?->can($ability, $assessment) === true;
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

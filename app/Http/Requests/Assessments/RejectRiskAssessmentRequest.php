<?php

namespace App\Http\Requests\Assessments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST risk/assessments/{assessment}/reject.
 *
 * The reason is REQUIRED where approval comments are optional, and that
 * asymmetry is deliberate: an approval speaks for itself, a rejection is an
 * instruction to somebody who has to act on it.
 */
class RejectRiskAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assessment = $this->route('assessment');

        return $assessment !== null && ($this->user()?->can('reject', $assessment) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ];
    }
}

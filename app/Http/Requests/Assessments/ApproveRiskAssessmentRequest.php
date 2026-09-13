<?php

namespace App\Http\Requests\Assessments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST risk/assessments/{assessment}/approve.
 *
 * The lifecycle rule — only an in-review assessment can be approved — is NOT
 * here and not in the policy: the screen answers a wrong status with a flash
 * message rather than a 403, and canAct() asks the same ability about tasks
 * that have not been decided yet. See RiskAssessmentPolicy.
 */
class ApproveRiskAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assessment = $this->route('assessment');

        return $assessment !== null && ($this->user()?->can('approve', $assessment) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'comments' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

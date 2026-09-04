<?php

namespace App\Http\Requests\Treatments;

use App\Models\TreatmentPlan;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reject a plan pending review (risk.treatments.reject).
 *
 * The reason is required and is what the workflow records as the decision's
 * comment — the owner reads it on the plan to know what to rework.
 */
class RejectTreatmentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $plan = $this->route('treatment');

        return $plan instanceof TreatmentPlan && $this->user()->can('reject', $plan);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rejection_reason.required' => 'A reason is required to reject a treatment plan.',
        ];
    }
}

<?php

namespace App\Http\Requests\Treatments;

use App\Http\Requests\Concerns\ValidatesConfiguredAttributes;
use App\Models\TreatmentPlan;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a treatment plan (migration Phase 3.5).
 *
 * risk_id is absent, exactly as it was: update() never accepted it, and the
 * edit form omits it. Re-pointing a plan at a different risk is a move, not
 * an edit.
 *
 * `target_completion_date` drops the `after:today` the create rules carry —
 * carried across unchanged, because editing a plan whose target has passed
 * must not be blocked by the target having passed.
 */
class UpdateTreatmentPlanRequest extends FormRequest
{
    use ValidatesConfiguredAttributes;

    public function authorize(): bool
    {
        $plan = $this->route('treatment');

        return $plan instanceof TreatmentPlan && $this->user()->can('update', $plan);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'treatment_title' => ['required', 'string', 'max:255'],
            'treatment_description' => ['required', 'string', 'max:5000'],
            'treatment_type' => ['required', Rule::in(TreatmentPlan::STRATEGIES)],
            'treatment_owner_id' => ['required', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'priority' => ['required', Rule::in(TreatmentPlan::PRIORITIES)],
            'status' => ['required', Rule::in(TreatmentPlan::EDITABLE_STATUSES)],
            'progress_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'target_completion_date' => ['required', 'date'],
            'actual_completion_date' => ['nullable', 'date'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'actual_cost' => ['nullable', 'numeric', 'min:0'],
            'expected_residual_likelihood' => ['nullable', 'integer', 'min:1', 'max:5'],
            'expected_residual_impact' => ['nullable', 'integer', 'min:1', 'max:5'],
            'milestones' => ['nullable', 'array'],
            'milestones.*.title' => ['nullable', 'string', 'max:255'],
            'milestones.*.due_date' => ['nullable', 'date'],
            'milestones.*.responsible' => ['nullable', 'string', 'max:255'],
            'success_criteria' => ['nullable', 'string', 'max:2000'],
            'implementation_notes' => ['nullable', 'string', 'max:5000'],
            ...$this->configuredAttributeRules('TreatmentPlan', $this->route('treatment')),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->configuredAttributeLabels('TreatmentPlan');
    }
}

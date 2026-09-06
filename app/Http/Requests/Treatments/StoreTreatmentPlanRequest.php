<?php

namespace App\Http\Requests\Treatments;

use App\Http\Requests\Concerns\ValidatesConfiguredAttributes;
use App\Models\TreatmentPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Create a treatment plan (migration Phase 3.5).
 *
 * The field NAMES are the 200038 form names, not the canonical columns: the
 * create form renders from the TreatmentPlan object type, whose attributes
 * post as treatment_title / treatment_type / … . TreatmentPlanService maps
 * them onto action_title / strategy / … on the way to the table — one place,
 * as it was in the controller. See docs/schema/canonical-columns.md.
 *
 * risk_id and treatment_owner_id were `exists:risks,id` / `exists:users,id`,
 * which accept ANOTHER TENANT'S id; both are tenant-bound here.
 */
class StoreTreatmentPlanRequest extends FormRequest
{
    use ValidatesConfiguredAttributes;

    public function authorize(): bool
    {
        return $this->user()->can('create', TreatmentPlan::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'risk_id' => ['required', Rule::exists('risks', 'id')->where('organization_id', $orgId)],
            'treatment_title' => ['required', 'string', 'max:255'],
            'treatment_description' => ['required', 'string', 'max:5000'],
            'treatment_type' => ['required', Rule::in(TreatmentPlan::STRATEGIES)],
            'treatment_owner_id' => ['required', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'priority' => ['required', Rule::in(TreatmentPlan::PRIORITIES)],
            'target_completion_date' => ['required', 'date', 'after:today'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'expected_residual_likelihood' => ['nullable', 'integer', 'min:1', 'max:5'],
            'expected_residual_impact' => ['nullable', 'integer', 'min:1', 'max:5'],
            'milestones' => ['nullable', 'array'],
            'milestones.*.title' => ['nullable', 'string', 'max:255'],
            'milestones.*.due_date' => ['nullable', 'date'],
            'milestones.*.responsible' => ['nullable', 'string', 'max:255'],
            'success_criteria' => ['nullable', 'string', 'max:2000'],
            ...$this->configuredAttributeRules('TreatmentPlan'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->configuredAttributeLabels('TreatmentPlan');
    }
}

<?php

namespace App\Http\Requests\Sm;

use App\Models\Sm\ScenarioAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update scenario action request — `PUT {scenario}/actions/{action}` (spec 11, ADR 0027 Amendment 3 §A3.3).
 */
class UpdateScenarioActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('scenario.manage_actions') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $orgId = (int) $this->scenario?->organization_id;

        return [
            'kind' => ['sometimes', 'required', 'string', Rule::in(ScenarioAction::KINDS)],
            'time_horizon' => ['nullable', 'string', Rule::in(ScenarioAction::HORIZONS)],
            'risk_area' => ['sometimes', 'required', 'string', 'max:200'],
            'current_position' => ['nullable', 'string', 'max:5000'],
            'identified_gap' => ['nullable', 'string', 'max:5000'],
            'action_plan' => ['sometimes', 'required', 'string', 'max:5000'],
            'business_unit_id' => [
                'nullable',
                'integer',
                Rule::exists('business_units', 'id')->where('organization_id', $orgId),
            ],
            'department' => ['nullable', 'string', 'max:200'],
            'owner_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('organization_id', $orgId),
            ],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', 'required', 'string', Rule::in(ScenarioAction::STATUSES)],
            'proof_of_closure' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

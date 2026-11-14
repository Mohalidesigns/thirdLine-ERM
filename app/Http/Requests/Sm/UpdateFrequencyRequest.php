<?php

namespace App\Http\Requests\Sm;

use App\Models\Sm\FrequencyDriver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Frequency update request — `PUT {scenario}/frequency` (spec 07, ADR 0027 Amendment 2 §A2.3).
 */
class UpdateFrequencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('scenario.design') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();
        $scenario = $this->route('scenario');
        $scenarioId = $scenario instanceof \App\Models\Sm\Scenario ? $scenario->id : $scenario;

        return [
            'drivers' => ['required', 'array'],
            'drivers.*.driver_type' => ['required', 'string', 'max:50'],
            'drivers.*.criterion' => ['required', 'string', 'max:250'],
            'drivers.*.computation_role' => ['required', 'string', Rule::in(array_keys(FrequencyDriver::COMPUTATION_ROLES))],
            'drivers.*.value' => ['required', 'numeric', 'min:0', 'max:9999999999.99999999'],
            'drivers.*.data_source' => ['nullable', 'string', 'max:100'],
            'drivers.*.sm_scorecard_id' => [
                'nullable',
                Rule::exists('sm_scorecards', 'id')
                    ->where('organization_id', $orgId)
                    ->where('sm_scenario_id', $scenarioId),
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('drivers', []) as $index => $driver) {
                if (($driver['computation_role'] ?? null) === 'probability' && isset($driver['value'])) {
                    if ((float) $driver['value'] > 1.0) {
                        $validator->errors()->add("drivers.{$index}.value", 'Probability cannot exceed 1.0.');
                    }
                }
            }
        });
    }
}

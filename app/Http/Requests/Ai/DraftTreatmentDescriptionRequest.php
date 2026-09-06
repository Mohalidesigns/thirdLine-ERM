<?php

namespace App\Http\Requests\Ai;

/** Draft a treatment plan description from a scenario. */
class DraftTreatmentDescriptionRequest extends AiToolRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scenario' => ['required', 'string', 'min:3', 'max:2000'],
            'title' => ['nullable', 'string', 'max:200'],
            'treatment_type' => ['nullable', 'string', 'max:50'],
            'risk_id' => $this->tenantRiskRule(),
        ];
    }
}

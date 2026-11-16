<?php

namespace App\Http\Requests;

use App\Models\Incident;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return $incident
            ? $this->user()->can('update', $incident)
            : $this->user()->can('create', Incident::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:8000'],
            'description_rich' => ['nullable'],
            'incident_type' => ['required', Rule::in(Incident::TYPES)],
            'basel_event_type' => ['nullable', Rule::in(Incident::BASEL_EVENT_TYPES)],
            'severity' => ['required', Rule::in(Incident::SEVERITIES)],
            'occurred_at' => ['nullable', 'date'],
            'detected_at' => ['nullable', 'date'],
            'detection_method' => ['nullable', 'string', 'max:255'],
            'business_unit_id' => ['nullable', 'exists:business_units,id'],
            'entity_id' => ['nullable', 'exists:business_units,id'],
            'process_id' => ['nullable', 'exists:business_processes,id'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'investigator_id' => ['nullable', 'exists:users,id'],
            'near_miss' => ['nullable', 'boolean'],

            // Minor units plus an ISO-4217 code (R7)
            'currency' => ['nullable', Rule::in(Money::SUPPORTED)],
            'gross_loss_minor' => ['nullable', 'integer', 'min:0', 'required_with:currency'],
            'recovery_minor' => ['nullable', 'integer', 'min:0'],

            'control_failure_ids' => ['nullable', 'array', 'max:50'],
            'control_failure_ids.*' => ['integer', 'exists:controls,id'],
            'risk_ids' => ['nullable', 'array', 'max:50'],
            'risk_ids.*' => ['integer', 'exists:risks,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Support entity_id aliasing to business_unit_id
        if ($this->has('entity_id') && ! $this->has('business_unit_id')) {
            $this->merge(['business_unit_id' => $this->input('entity_id')]);
        }
    }
}

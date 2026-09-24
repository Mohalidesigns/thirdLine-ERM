<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\IncidentLogEntryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `crisis-room.md` §2/§4 — the decision composer. `options_considered` and
 * `rationale` are required specifically for a `decision` entry (clause map
 * §1.2: captured at the moment, not reconstructed later).
 */
class StoreBcmsIncidentLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.incident.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $incident = $this->route('incident');

        return [
            'entry_type' => ['required', Rule::enum(IncidentLogEntryType::class)],
            'content' => ['required', 'string', 'max:10000'],
            'options_considered' => ['nullable', 'string', 'max:2000', 'required_if:entry_type,decision'],
            'rationale' => ['nullable', 'string', 'max:2000', 'required_if:entry_type,decision'],
            'supersedes_entry_id' => [
                'nullable', 'integer',
                Rule::exists('bcms_incident_log', 'id')->where('incident_id', $incident?->getKey()),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'options_considered.required_if' => 'A decision entry must record the options considered at the time.',
            'rationale.required_if' => 'A decision entry must record its rationale at the time.',
        ];
    }
}

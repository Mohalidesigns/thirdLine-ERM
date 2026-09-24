<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;

/** `incident-stand-down.md` §2 — the all-clear message and the resolution reason. */
class StandDownBcmsIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.incident.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'all_clear_message' => ['required', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'max:2000'],
            'dispatch_all_clear' => ['nullable', 'boolean'],
            'plans_remaining_active' => ['nullable', 'array'],
            'plans_remaining_active.*' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

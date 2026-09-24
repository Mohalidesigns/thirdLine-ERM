<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\ActivationLevel;
use App\Enums\Bcms\IncidentSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A severity may only move with a stated reason — clause map §1.3. */
class RegradeBcmsIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.incident.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'severity' => ['required', Rule::enum(IncidentSeverity::class)],
            'activation_level' => ['nullable', Rule::enum(ActivationLevel::class)],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}

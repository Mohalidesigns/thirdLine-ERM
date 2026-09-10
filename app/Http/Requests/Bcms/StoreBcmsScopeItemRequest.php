<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding an org node or a process to the programme's scope, or excluding one.
 *
 * AN EXCLUSION NEEDS A REASON. Clause 4.3 asks for the boundary to be justified,
 * and the rule is here as well as in `ProgrammeService` because a form request
 * gives the user the sentence at the point they are typing, while the service
 * stops a job doing the same thing silently.
 */
class StoreBcmsScopeItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.programme.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        return [
            'scopable_type' => ['required', Rule::in(['business_unit', 'bcms_process'])],
            'scopable_id' => [
                'required', 'integer',
                // Tenant-bound, never a bare `exists:` — a bare one across a
                // tenant boundary is an existence oracle (development standard
                // §4).
                $this->input('scopable_type') === 'business_unit'
                    ? Rule::exists('business_units', 'id')->where('organization_id', $organizationId)
                    : Rule::exists('bcms_processes', 'id')->where('organization_id', $organizationId),
            ],
            'in_scope' => ['required', 'boolean'],
            'rationale' => ['nullable', 'string', 'max:2000', 'required_if:in_scope,false'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rationale.required_if' => 'An exclusion from the BCMS scope must be justified (ISO 22301 clause 4.3).',
        ];
    }
}

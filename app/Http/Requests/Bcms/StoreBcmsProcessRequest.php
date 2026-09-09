<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The process form.
 *
 * EVERY FOREIGN KEY IS TENANT-BOUND. A bare `exists:` across a tenant boundary
 * is an existence oracle — validation passing for another organisation's id and
 * failing for one that exists nowhere answers a question the caller is not
 * entitled to ask (development standard §4).
 *
 * A CRITICAL SERVICE NEEDS A JUSTIFICATION. It is the BOFIA/NDIC
 * resolution-planning designation, and a bare boolean is not something anybody
 * can defend at an examination.
 */
class StoreBcmsProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.process.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;
        $process = $this->route('process');

        return [
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('bcms_processes', 'code')
                    ->where('organization_id', $organizationId)
                    ->ignore($process?->getKey()),
            ],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'business_unit_id' => [
                'nullable', 'integer',
                Rule::exists('business_units', 'id')->where('organization_id', $organizationId),
            ],
            'parent_process_id' => [
                'nullable', 'integer',
                Rule::exists('bcms_processes', 'id')->where('organization_id', $organizationId),
                // A process that is its own parent is an infinite tree, and the
                // catalogue screen would recurse until it ran out of memory.
                Rule::notIn([$process?->getKey()]),
            ],
            'business_process_id' => [
                'nullable', 'integer',
                Rule::exists('business_processes', 'id')->where('organization_id', $organizationId),
            ],
            'owner_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('organization_id', $organizationId),
            ],
            'category' => ['nullable', 'string', 'max:80'],
            'criticality_tier' => ['nullable', 'integer', 'min:1', 'max:4'],
            'is_critical_service' => ['required', 'boolean'],
            'critical_service_justification' => [
                'nullable', 'string', 'max:2000',
                'required_if:is_critical_service,true',
            ],
            'regulatory_flags' => ['nullable', 'array'],
            'regulatory_flags.*' => ['string', 'max:60'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'critical_service_justification.required_if' => 'A critical service needs a justification — this is the '
                .'BOFIA/NDIC resolution-planning register, not a convenience flag.',
            'parent_process_id.not_in' => 'A process cannot be its own parent.',
        ];
    }
}

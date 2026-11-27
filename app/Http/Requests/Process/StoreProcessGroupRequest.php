<?php

namespace App\Http\Requests\Process;

use App\Models\ProcessGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

class StoreProcessGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ProcessGroup::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $orgId = (int) ($this->user()?->organization_id ?? TenantContext::organizationId());

        return [
            'code' => [
                'nullable',
                'string',
                'max:32',
                Rule::unique('process_groups', 'code')->where('organization_id', $orgId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'apqc_code' => ['nullable', 'string', 'max:32'],
            'business_unit_id' => [
                'nullable',
                'integer',
                Rule::exists('business_units', 'id')->where('organization_id', $orgId),
            ],
            'owner_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('organization_id', $orgId),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'status' => ['nullable', 'string', Rule::in(ProcessGroup::STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'This process group code is already in use.',
        ];
    }
}

<?php

namespace App\Http\Requests\Process;

use App\Models\BusinessProcess;
use App\Models\Entity;
use App\Policies\EntityPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use ThirdLine\Platform\Tenancy\TenantContext;

class StoreProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', BusinessProcess::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $orgId = (int) ($this->user()?->organization_id ?? TenantContext::organizationId());
        $isSubProcess = $this->input('level') === 'sub_process';

        return [
            'level' => ['nullable', 'string', Rule::in(['process', 'sub_process'])],
            'parent_id' => [
                $isSubProcess ? 'required' : 'nullable',
                'integer',
                Rule::exists('business_processes', 'id')
                    ->where('organization_id', $orgId)
                    ->whereNull('parent_id')
                    ->whereNull('deleted_at'),
            ],
            'process_group_id' => [
                'nullable',
                'integer',
                Rule::exists('process_groups', 'id')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at'),
            ],
            'business_unit_id' => [
                'nullable',
                'integer',
                Rule::exists('business_units', 'id')->where('organization_id', $orgId),
            ],
            'entity_id' => [
                'nullable',
                'integer',
                Rule::exists('entities', 'id')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at'),
            ],
            'code' => [
                'nullable',
                'string',
                'max:32',
                Rule::unique('business_processes', 'code')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at'),
            ],
            'apqc_code' => ['nullable', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'owner_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('organization_id', $orgId),
            ],
            'criticality' => ['nullable', 'string', Rule::in(BusinessProcess::CRITICALITIES)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'This process code is already in use.',
            'parent_id.required' => 'Select a parent process when creating a sub-process.',
            'parent_id.exists' => 'Selected parent must be a top-level process in your organization.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $entityId = $this->input('entity_id');

            if ($validator->errors()->has('entity_id') || $entityId === null || $entityId === '') {
                return;
            }

            // Reach, not just tenancy: the new process is attached to this entity, so a
            // user limited to part of the organisation may only attach it there.
            $entity = Entity::query()->find((int) $entityId);

            if ($entity !== null && ! EntityPolicy::withinReach($this->user(), $entity)) {
                $validator->errors()->add('entity_id', 'You can only attach a process to an entity in the part of the organisation you manage.');
            }
        });
    }
}

<?php

namespace App\Http\Requests\Process;

use App\Models\BusinessProcess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use ThirdLine\Platform\Tenancy\TenantContext;

class UpdateProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var BusinessProcess|null $process */
        $process = $this->route('process');

        return $process !== null && ($this->user()?->can('update', $process) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var BusinessProcess $process */
        $process = $this->route('process');
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
            'code' => [
                'nullable',
                'string',
                'max:32',
                Rule::unique('business_processes', 'code')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at')
                    ->ignore($process->id),
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var BusinessProcess|null $process */
            $process = $this->route('process');
            if ($process === null) {
                return;
            }

            $level = $this->input('level');
            $parentId = $this->filled('parent_id') ? (int) $this->input('parent_id') : null;

            if ($level === 'process') {
                return;
            }

            if ($parentId !== null) {
                if ($parentId === (int) $process->id) {
                    $validator->errors()->add('parent_id', 'A process cannot be its own parent.');
                }

                if ($process->subProcesses()->exists()) {
                    $validator->errors()->add('parent_id', 'Cannot convert to a sub-process while child sub-processes exist.');
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'This process code is already in use.',
            'parent_id.required' => 'Select a parent process when assigning as a sub-process.',
            'parent_id.exists' => 'Selected parent must be a top-level process in your organization.',
        ];
    }
}

<?php

namespace App\Http\Requests\Workflow;

use App\Models\WorkflowDefinition;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', WorkflowDefinition::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'definition_id' => [
                'required',
                'integer',
                Rule::exists('workflow_definitions', 'id')->where('organization_id', TenantContext::organizationId()),
            ],
            'entity_type' => ['required', 'string', 'max:80'],
            'entity_id' => ['required', 'integer'],
        ];
    }
}

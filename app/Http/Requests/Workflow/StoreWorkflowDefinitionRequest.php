<?php

namespace App\Http\Requests\Workflow;

use App\Models\WorkflowDefinition;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkflowDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', WorkflowDefinition::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'entity_type' => ['required', 'string', 'max:50'],
            'definition' => ['required', 'array'],
            'definition.nodes' => ['required', 'array', 'min:1'],
            'definition.edges' => ['nullable', 'array'],
            'escalation_rules' => ['nullable', 'array'],
        ];
    }
}

<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

class ActOnInstanceRequest extends FormRequest
{
    public const ACTIONS = ['approve', 'reject', 'delegate', 'escalate', 'comment', 'return', 'cancel'];

    public function authorize(): bool
    {
        $instance = $this->route('instance');

        return $this->input('action') === 'cancel'
            ? $this->user()->can('cancel', $instance)
            : $this->user()->can('act', $instance);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(self::ACTIONS)],
            'comments' => ['nullable', 'string', 'max:3000'],
            'delegated_to' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('organization_id', TenantContext::organizationId()),
            ],
            'to_node' => ['nullable', 'string', 'max:80'],
        ];
    }
}

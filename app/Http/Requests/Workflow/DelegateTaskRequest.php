<?php

namespace App\Http\Requests\Workflow;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DelegateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delegate', $this->route('task'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'delegate_to' => [
                'required',
                'integer',
                Rule::exists('users', 'id')
                    ->where('organization_id', TenantContext::organizationId())
                    ->where('is_active', true),
            ],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}

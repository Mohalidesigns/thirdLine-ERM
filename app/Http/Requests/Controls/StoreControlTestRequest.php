<?php

namespace App\Http\Requests\Controls;

use App\Models\ControlTest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreControlTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ControlTest::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'control_id' => ['required', Rule::exists('controls', 'id')->where('organization_id', $orgId)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'test_type' => ['required', Rule::in(ControlTest::TYPES)],
            'tester_id' => ['required', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'reviewer_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'scheduled_date' => ['required', 'date'],
        ];
    }
}

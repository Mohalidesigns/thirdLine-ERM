<?php

namespace App\Http\Requests\Controls;

use App\Models\ControlTest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The control a test belongs to is fixed at scheduling: the old edit form
 * offered a control selector whose value the controller silently discarded,
 * so it is no longer offered and `control_id` is not a rule here.
 */
class UpdateControlTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $test = $this->route('controlTest');

        return $test instanceof ControlTest && $this->user()->can('update', $test);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'test_type' => ['required', Rule::in(ControlTest::TYPES)],
            'tester_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'reviewer_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'scheduled_date' => ['required', 'date'],
        ];
    }
}

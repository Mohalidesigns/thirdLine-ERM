<?php

namespace App\Http\Requests\Issues;

use App\Models\Issue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add a remediation action to an issue (migration Phase 4.4). */
class StoreRemediationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $issue = $this->route('issue');

        return $issue instanceof Issue && $this->user()->can('recordProgress', $issue);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:3000'],
            'owner_id' => ['required', Rule::exists('users', 'id')->where('organization_id', TenantContext::organizationId())],
            'target_date' => ['required', 'date', 'after:today'],
        ];
    }
}

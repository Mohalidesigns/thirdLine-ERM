<?php

namespace App\Http\Requests\Admin;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The institution's identifying details (migration Phase 6.2).
 *
 * These land in `organizations.settings->org_profile` and are read by
 * DocumentRenderer, which prints them on generated documents. The
 * organisation's `name` column is written too, because that is what the rest
 * of the platform displays.
 */
class OrganizationProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateSettings', $this->organization());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'org_name' => ['required', 'string', 'max:255'],
            'org_code' => ['required', 'string', 'max:50'],
            'industry' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
            'regulatory_framework' => ['required', 'string', 'max:255'],
        ];
    }

    public function organization(): Organization
    {
        return Organization::findOrFail(TenantContext::organizationId());
    }
}

<?php

namespace App\Http\Requests\Kri;

use App\Enums\KriContactRole;
use App\Models\KriDepartmentContact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Choose a person for one role in one department (ADR 0026 section 2). Every
 * foreign key is tenant-bound. Configuring a contact grants nothing: a champion
 * still needs `kri.submit_return` from a role.
 */
class StoreKriDepartmentContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', KriDepartmentContact::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'business_unit_id' => ['required', 'integer', Rule::exists('business_units', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
                Rule::unique('kri_department_contacts', 'user_id')
                    ->where('organization_id', $orgId)
                    ->where('business_unit_id', (int) $this->input('business_unit_id'))
                    ->where('role', (string) $this->input('role')),
            ],
            'role' => ['required', Rule::in(KriContactRole::values())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['user_id.unique' => 'That person already holds this role in this department.'];
    }
}

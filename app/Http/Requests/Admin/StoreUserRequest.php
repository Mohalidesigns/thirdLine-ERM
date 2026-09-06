<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Create a user account (migration Phase 6.1).
 *
 * Three rules changed, and the reasons are worth keeping.
 *
 * 1. `roles` HAD NO RULE ON ITS ELEMENTS — `required|array|min:1`, then
 *    `syncRoles($validated['roles'])`. `super-admin` is a seeded role and
 *    `Gate::before` answers TRUE to every ability for anyone holding it, so a
 *    caller with `admin.users` could grant themselves or anyone else the whole
 *    platform. Roles are now checked against the ones that exist, and
 *    `super-admin` additionally against UserPolicy::grantSuperAdmin().
 *
 * 2. `business_unit_id` was `exists:business_units,id` — the bare string this
 *    programme has been replacing since Phase 3, with no tenant filter. A new
 *    account could be posted into another institution's business unit.
 *
 * 3. `staff_id` was `unique:users` — GLOBALLY, across every institution on the
 *    installation. Two banks cannot both employ staff number 001, which is not
 *    a rule anybody asked for; there is no unique index behind it either, so it
 *    was validation-only. It is unique within the organisation now.
 *
 * `email` stays globally unique deliberately: on this platform the address is
 * the identity SSO and SCIM provision against, which is the same reason
 * ProfileUpdateRequest refuses to let a user change their own.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'staff_id' => [
                'required', 'string', 'max:50',
                Rule::unique('users', 'staff_id')->where('organization_id', $orgId),
            ],
            'job_title' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'business_unit_id' => [
                'required', 'integer',
                Rule::exists('business_units', 'id')->where('organization_id', $orgId),
            ],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in($this->assignableRoles())],
        ];
    }

    /**
     * The roles this actor may hand out.
     *
     * Every role that exists, less `super-admin` unless the actor holds it —
     * see UserPolicy::grantSuperAdmin() for why that one is different.
     *
     * @return list<string>
     */
    protected function assignableRoles(): array
    {
        $roles = Role::query()->orderBy('name')->pluck('name');

        if (! $this->user()->can('grantSuperAdmin', User::class)) {
            $roles = $roles->reject(fn (string $name) => $name === UserPolicy::SUPER_ADMIN);
        }

        return $roles->values()->all();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'roles.*.in' => 'That role cannot be assigned. Only a super-admin may grant the super-admin role.',
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Amend a user account (migration Phase 6.1).
 *
 * The same rules as StoreUserRequest — including the role whitelist that stops
 * `admin.users` becoming super-admin — with the uniqueness checks ignoring the
 * record being edited.
 *
 * The extra thing this one refuses: EDITING YOUR OWN ROLES. `UserPolicy::update`
 * allows an administrator to edit their own profile fields, and that is fine,
 * but the roles array is where "may manage users" would become "may do
 * anything" in one step. The controller enforced no-self-action for
 * deactivation and for the active toggle, and not here.
 */
class UpdateUserRequest extends StoreUserRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();
        $userId = $this->route('user')?->id;

        return array_merge(parent::rules(), [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'staff_id' => [
                'required', 'string', 'max:50',
                Rule::unique('users', 'staff_id')->where('organization_id', $orgId)->ignore($userId),
            ],
        ]);
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $subject = $this->route('user');

            if ($subject === null || $subject->id !== $this->user()->id) {
                return;
            }

            $current = $subject->roles->pluck('name')->sort()->values()->all();
            $posted = collect($this->input('roles', []))->map(fn ($r) => (string) $r)->sort()->values()->all();

            if ($current !== $posted) {
                $validator->errors()->add(
                    'roles',
                    'You cannot change your own roles. Ask another administrator.',
                );
            }
        });
    }
}

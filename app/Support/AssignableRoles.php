<?php

namespace App\Support;

use App\Models\User;
use App\Policies\UserPolicy;
use Spatie\Permission\Models\Role;

/**
 * The roles an actor may hand out (migration Phase 6.2).
 *
 * Every role the installation defines, less `super-admin` unless the actor
 * holds it. `Gate::before` answers every ability true for a super-admin, so
 * that role is not one of many — it is the platform, and only somebody who
 * already has it may confer it. `UserPolicy::grantSuperAdmin()` is where that
 * is decided; this class is where the resulting list is built.
 *
 * It is one class because the list is needed in four places and they must not
 * disagree: the user form's options and its validator (Phase 6.1), and the
 * single sign-on form's options and its validator (Phase 6.2). A form that
 * offers a role its validator rejects is the defect Phase 4.6 named; two
 * validators that disagree about `super-admin` is worse, because the laxer one
 * is the one that matters.
 */
class AssignableRoles
{
    /**
     * @return list<string>
     */
    public static function for(?User $actor): array
    {
        $roles = Role::query()->orderBy('name')->pluck('name');

        if ($actor === null || ! $actor->can('grantSuperAdmin', User::class)) {
            $roles = $roles->reject(fn (string $name) => $name === UserPolicy::SUPER_ADMIN);
        }

        return $roles->values()->all();
    }
}

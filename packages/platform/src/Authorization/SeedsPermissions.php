<?php

namespace ThirdLine\Platform\Authorization;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Apply a PermissionCatalog to the database.
 *
 * Used by a seeder on a fresh install and by a grant migration on a deployed
 * one, so both do the same thing from the same declaration. That is the whole
 * point: the drift this prevents comes from those two being written separately.
 *
 * EVERY OPERATION IS IDEMPOTENT. `Permission::create()` throws on the second
 * run, which is why the seeder could never be re-run against a deployed tenant
 * and why every new module needed a hand-written grant migration in the first
 * place. firstOrCreate makes re-running the seeder a no-op instead of an
 * error — and makes the grant migration a thin call rather than a copy.
 *
 * WHAT IT WILL NOT DO IS REVOKE. Removing a permission from the catalog does
 * not delete it or strip it from a role, because a seeder cannot tell a
 * permission you retired from one an operator granted deliberately. Retiring a
 * permission is a migration somebody writes on purpose.
 */
trait SeedsPermissions
{
    protected function syncCatalog(PermissionCatalog $catalog, ?string $guard = null): void
    {
        $guard ??= config('auth.defaults.guard', 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $undefined = $catalog->undefinedRoleGrants();

        if ($undefined !== []) {
            // Fail loudly rather than granting nothing: Spatie drops an unknown
            // permission silently, leaving the role quietly narrower than it
            // reads in the catalog.
            throw new \RuntimeException(
                'The catalog grants permissions it does not define: '.implode(', ', $undefined)
            );
        }

        foreach ($catalog->names() as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        foreach ($catalog->roleNames() as $role) {
            Role::findOrCreate($role, $guard)
                ->givePermissionTo($catalog->permissionsFor($role));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Grant a subset of the catalog to roles that already exist.
     *
     * The shape a grant migration needs: a fresh install is handled by the
     * seeder, and this fills the gap for tenants deployed before the module
     * existed. A role that is absent is skipped rather than created — an
     * install without `risk-manager` chose not to have one.
     *
     * @param  array<string, list<string>>  $grants  role => permissions
     */
    protected function grantFromCatalog(PermissionCatalog $catalog, array $grants, ?string $guard = null): void
    {
        $guard ??= config('auth.defaults.guard', 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($grants as $role => $permissions) {
            foreach ($permissions as $permission) {
                if (! $catalog->has($permission)) {
                    throw new \RuntimeException(
                        "Cannot grant \"{$permission}\": it is not in the catalog."
                    );
                }

                Permission::findOrCreate($permission, $guard);
            }

            $existing = Role::query()->where('name', $role)->where('guard_name', $guard)->first();

            $existing?->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

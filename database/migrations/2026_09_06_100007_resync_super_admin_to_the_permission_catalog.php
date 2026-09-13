<?php

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Give super-admin every permission the catalog defines, on tenants that
 * already exist.
 *
 * WHAT WENT WRONG. `RiskPermissionCatalog` grants super-admin `['*']`, which
 * `permissionsFor()` resolves to the whole catalog — but only when
 * RolesAndPermissionsSeeder runs, and a seeder never runs again on a deployed
 * tenant. Every grant migration since has listed the roles it affects, and each
 * has left super-admin out on the reasoning that the `Gate::before` in
 * AppServiceProvider lets that role through everything anyway.
 *
 * That reasoning is wrong for this application, because the `permission:`
 * middleware alias is NOT Spatie's. It is
 * ThirdLine\Platform\Http\Middleware\CheckPermission, which asked
 * `hasPermissionTo()` — the grant table — and never saw the gate. So the one
 * role that is supposed to see everything was refused every screen whose
 * permissions were added after its grants were seeded, with "Unauthorized
 * action.", while HandleInertiaRequests put those same permissions into the
 * page props and the sidebar rendered a link to the 403.
 *
 * It surfaced with the RCSA v2 universe permissions (migration 100006, which
 * listed five roles and not this one). On the installation where it was found,
 * super-admin held 121 of the catalog's 127 — short by exactly the six
 * `rcsa_universe.*` permissions.
 *
 * TWO FIXES, because either alone leaves a hole. CheckPermission now trusts the
 * role rather than the grant table, as HandleInertiaRequests already does — so
 * a future module cannot reopen this. And this migration repairs the data, so
 * `getAllPermissions()`, the role-administration screen and anything else that
 * reads the grant table agree with the catalog rather than reading "121 of
 * 127" for a role that is meant to hold all of them.
 *
 * IDEMPOTENT AND FORWARD-LOOKING. It syncs whatever the catalog defines at the
 * time it runs, so it is safe to re-run and it repairs any gap, not only this
 * one. It does NOT revoke: a permission granted to super-admin by hand and not
 * in the catalog is left alone, because taking authority away is not this
 * migration's business.
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        $role = Role::query()->where('name', 'super-admin')->where('guard_name', $guard)->first();

        // A fresh install has no roles yet; the seeder runs after the
        // migrations and creates this one with the full catalog.
        if ($role === null) {
            return;
        }

        $catalog = new RiskPermissionCatalog;

        $permissions = collect($catalog->names())->map(
            fn (string $name) => Permission::findOrCreate($name, $guard)
        );

        $role->givePermissionTo($permissions->all());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Deliberately empty. Reversing this would mean revoking permissions
        // from super-admin, and there is no record of which of them this
        // migration added versus which the seeder had already granted.
        // Rolling back must not be able to lock an administrator out.
    }
};

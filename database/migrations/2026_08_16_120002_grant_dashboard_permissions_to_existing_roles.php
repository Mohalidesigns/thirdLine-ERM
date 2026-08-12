<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * WP-08 — dashboard, HQ, My Responsibilities and search permissions, for
 * tenants that already exist.
 *
 * Same mechanism as the WP-06/WP-07 grant migrations: RolesAndPermissionsSeeder
 * calls Role::create, so it cannot re-run against a deployed tenant.
 *
 * THE GRANTS:
 *
 *   hq.view, my.view,   everyone. /my is THE first-line participation surface
 *   search.view         — a risk champion in a branch who cannot see their own
 *                         queue will not fill it. What each page actually shows
 *                         is decided by tenancy, GraphScope and per-object
 *                         permissions, not by this grant.
 *   dashboard.manage    administrators and the risk function. Building and
 *                         publishing a dashboard decides what a role sees as
 *                         its landing surface, which is a governance act.
 */
return new class extends Migration
{
    private const UNIVERSAL = ['hq.view', 'my.view', 'search.view'];

    private const MANAGE = ['dashboard.manage'];

    private const MANAGE_ROLES = ['risk-manager', 'chief-risk-officer'];

    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        // A fresh install has no roles yet — the seeder runs after the
        // migrations and creates both.
        if (! Role::query()->where('guard_name', $guard)->exists()) {
            return;
        }

        $all = array_merge(self::UNIVERSAL, self::MANAGE);

        $permissions = collect($all)->mapWithKeys(fn (string $name) => [
            $name => Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]),
        ]);

        Role::query()->where('guard_name', $guard)->each(function (Role $role) use ($permissions) {
            $grant = self::UNIVERSAL;

            if (in_array($role->name, self::MANAGE_ROLES, true) || $role->name === 'super-admin') {
                $grant = array_merge($grant, self::MANAGE);
            }

            $role->givePermissionTo($permissions->only($grant)->values()->all());
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', array_merge(self::UNIVERSAL, self::MANAGE))->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

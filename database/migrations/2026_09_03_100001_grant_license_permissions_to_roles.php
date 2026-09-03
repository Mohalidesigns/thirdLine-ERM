<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration Phase 0 — licensing permissions, for tenants that already exist.
 *
 * Same mechanism as the WP-06/07/08 grant migrations: RolesAndPermissionsSeeder
 * calls Role::create, so it cannot re-run against a deployed tenant.
 *
 *   license.view, license.manage   super-admin only. The licence binds the
 *                                  deployment; activating or removing it is a
 *                                  platform act, not an organisation setting.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['license.view', 'license.manage'];

    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        // A fresh install has no roles yet — the seeder runs after the
        // migrations and creates both.
        if (! Role::query()->where('guard_name', $guard)->exists()) {
            return;
        }

        $permissions = collect(self::PERMISSIONS)->map(
            fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard])
        );

        Role::query()->where('guard_name', $guard)->where('name', 'super-admin')->each(
            fn (Role $role) => $role->givePermissionTo($permissions->all())
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

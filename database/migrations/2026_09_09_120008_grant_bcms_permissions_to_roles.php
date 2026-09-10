<?php

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * BCMS Phase 0, part 8 of 8 — the module's permissions, for tenants that
 * already exist.
 *
 * Identical in mechanism to the TPRM grant migration and for the same reason:
 * `RolesAndPermissionsSeeder` calls `Role::create`, which throws on a second
 * run and therefore never reaches a deployed tenant. The names and the grants
 * are READ FROM `RiskPermissionCatalog` rather than restated, because
 * development standard §2 declares a permission once — and because Spatie's
 * answer to an unknown permission is to grant nothing, silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        // A fresh install has no roles yet: the seeder runs after migrations
        // and creates both roles and permissions from the same catalogue.
        if (! Role::query()->where('guard_name', $guard)->exists()) {
            return;
        }

        $catalog = new RiskPermissionCatalog;

        $bcmsPermissions = array_keys($catalog->modules()['Business continuity (BCMS)'] ?? []);

        if ($bcmsPermissions === []) {
            return;
        }

        foreach ($bcmsPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        foreach ($catalog->roles() as $roleName => $granted) {
            // `super-admin` holds ['*'] and resolves at read time, so it needs
            // no grant here and must not be given a literal '*' permission.
            if ($granted === ['*']) {
                continue;
            }

            $forThisRole = array_values(array_intersect($granted, $bcmsPermissions));

            if ($forThisRole === []) {
                continue;
            }

            Role::query()
                ->where('guard_name', $guard)
                ->where('name', $roleName)
                ->each(fn (Role $role) => $role->givePermissionTo($forThisRole));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        Permission::query()
            ->where('guard_name', $guard)
            ->where('name', 'like', 'bcms.%')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

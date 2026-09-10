<?php

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * TPRM Phase 0 — the module's permissions, for tenants that already exist.
 *
 * The mechanism is the one every grant migration in this product uses, and it
 * exists because `RolesAndPermissionsSeeder` calls `Role::create`, which throws
 * on a second run and therefore can never reach a deployed tenant.
 *
 * WHAT IS DIFFERENT HERE is that the permission names and the role grants are
 * READ FROM `RiskPermissionCatalog` rather than restated. Development standard
 * §2 is explicit that a permission is declared once: the reason four earlier
 * grant migrations exist as a cautionary tale is that each restated the
 * seeder's list, nothing checked they agreed, and Spatie's answer to an unknown
 * permission is to grant nothing, silently. Reading the catalogue means a
 * permission added to it after this migration ships is granted by the seeder on
 * fresh installs and by the NEXT grant migration on deployed ones — but never
 * disagrees with the catalogue in between.
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

        $tprmPermissions = array_keys($catalog->modules()['Third-party risk (TPRM)'] ?? []);

        if ($tprmPermissions === []) {
            return;
        }

        foreach ($tprmPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        foreach ($catalog->roles() as $roleName => $granted) {
            // `super-admin` holds ['*'] and resolves at read time, so it needs
            // no grant here and must not be given a literal '*' permission.
            if ($granted === ['*']) {
                continue;
            }

            $forThisRole = array_values(array_intersect($granted, $tprmPermissions));

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
            ->where('name', 'like', 'tprm.%')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

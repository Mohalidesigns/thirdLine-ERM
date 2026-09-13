<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * WP-06 TASK 5 — give every existing role its own task queue.
 *
 * RolesAndPermissionsSeeder is a fresh-install seeder: it calls Role::create,
 * so it cannot be re-run against a deployed tenant. Without this migration the
 * new permissions would exist only for organizations installed after the
 * upgrade, and My Tasks would 403 for everybody on every existing deployment —
 * a screen that is the entire point of the work package.
 *
 * task.view and task.act are deliberately universal. They grant seeing and
 * clearing what the engine has assigned to you and nothing else: whether a
 * given task is yours is settled by WorkflowEngine::canAct, which checks the
 * assignment AND the module's existing Gate. Anyone who can be assigned a
 * decision must be able to record it.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['task.view', 'task.act'];

    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        // A fresh install has no roles yet: RolesAndPermissionsSeeder runs
        // after the migrations and creates both the permissions and the roles.
        // Creating them here first would make that seeder throw
        // PermissionAlreadyExists on every new installation.
        if (! Role::query()->where('guard_name', $guard)->exists()) {
            return;
        }

        $permissions = collect(self::PERMISSIONS)->map(
            fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard])
        );

        Role::query()->where('guard_name', $guard)->each(
            fn (Role $role) => $role->givePermissionTo($permissions)
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

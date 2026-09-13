<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * WP-07 — the integration permissions, for tenants that already exist.
 *
 * Same reasoning as the WP-06 task permissions: RolesAndPermissionsSeeder calls
 * Role::create, so it cannot be re-run against a deployed tenant, and without
 * this every WP-07 screen would 403 on every existing installation.
 *
 * THE GRANTS ARE NOT UNIFORM, deliberately:
 *
 *   job.view, api.tokens   everyone. A job you started is yours to stop, and a
 *                          token can never exceed its owner's permissions.
 *   api.docs               everyone who can already reach the application. The
 *                          spec describes endpoints they are separately
 *                          authorized for; hiding it from them buys nothing.
 *   webhook.*, connector.* administrators and the risk function. Creating a
 *                          webhook sends this tenant's data to an external URL,
 *                          and a connector holds credentials for the systems it
 *                          reads.
 *   admin.queues,          administrators only. Horizon shows job payloads, and
 *   api.tokens.manage      a payload is the record itself.
 */
return new class extends Migration
{
    private const UNIVERSAL = ['job.view', 'api.tokens', 'api.docs'];

    private const INTEGRATION = ['webhook.view', 'webhook.manage', 'connector.view', 'connector.manage', 'connector.run'];

    private const ADMINISTRATIVE = ['admin.queues', 'api.tokens.manage'];

    private const INTEGRATION_ROLES = ['risk-manager', 'chief-risk-officer', 'compliance-officer'];

    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        // A fresh install has no roles yet — the seeder runs after the
        // migrations and creates both. Creating the permissions here first
        // would make it throw PermissionAlreadyExists on every new install.
        if (! Role::query()->where('guard_name', $guard)->exists()) {
            return;
        }

        $all = array_merge(self::UNIVERSAL, self::INTEGRATION, self::ADMINISTRATIVE);

        $permissions = collect($all)->mapWithKeys(fn (string $name) => [
            $name => Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]),
        ]);

        Role::query()->where('guard_name', $guard)->each(function (Role $role) use ($permissions) {
            $grant = self::UNIVERSAL;

            if (in_array($role->name, self::INTEGRATION_ROLES, true)) {
                $grant = array_merge($grant, self::INTEGRATION);
            }

            // super-admin already passes every check through Gate::before, but
            // the explicit grants keep the role's permission list honest — a
            // screen that lists what a role may do should not be silently
            // wrong for the one role that may do everything.
            if ($role->name === 'super-admin') {
                $grant = array_merge($grant, self::INTEGRATION, self::ADMINISTRATIVE);
            }

            $role->givePermissionTo($permissions->only($grant)->values()->all());
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', array_merge(self::UNIVERSAL, self::INTEGRATION, self::ADMINISTRATIVE))->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

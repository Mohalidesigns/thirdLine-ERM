<?php

namespace ThirdLine\Platform\Authorization;

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * The migration a new module needs, written once.
 *
 * A seeder creates permissions on a FRESH install. Every tenant deployed
 * before a module existed needs the same permissions granted to the roles it
 * already has, and that is what this is for.
 *
 * Subclasses declare the catalog and who gets what:
 *
 *     return new class extends GrantPermissionsMigration
 *     {
 *         protected function catalog(): PermissionCatalog
 *         {
 *             return new \App\Authorization\RiskPermissionCatalog;
 *         }
 *
 *         protected function grants(): array
 *         {
 *             return ['risk-manager' => ['widget.view', 'widget.manage']];
 *         }
 *     };
 *
 * WHAT THE BASE CLASS BUYS. Every one of these written by hand repeats a
 * permission list that also lives in the seeder, and nothing checks the two
 * agree — the drift PermissionCatalog exists to stop. Granting through the
 * catalog means a permission that is not declared throws here, at deploy time,
 * rather than being silently dropped by Spatie and leaving the role narrower
 * than the migration reads.
 *
 * A ROLE THAT DOES NOT EXIST IS SKIPPED, NOT CREATED. An install without
 * `risk-manager` chose not to have one, and inventing it during a migration
 * would hand somebody a role nobody assigned.
 *
 * DOWN() IS DELIBERATELY EMPTY. Rolling back a deploy should not strip a
 * permission an operator may have granted deliberately in the meantime, and a
 * migration cannot tell the two apart.
 */
abstract class GrantPermissionsMigration extends Migration
{
    use SeedsPermissions;

    abstract protected function catalog(): PermissionCatalog;

    /**
     * @return array<string, list<string>> role => permissions
     */
    abstract protected function grants(): array;

    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        // A fresh install has no roles yet — the seeder runs after the
        // migrations and creates both roles and permissions.
        if (! Role::query()->where('guard_name', $guard)->exists()) {
            return;
        }

        $this->grantFromCatalog($this->catalog(), $this->grants(), $guard);
    }

    public function down(): void
    {
        // See the class docblock.
    }
}

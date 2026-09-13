<?php

namespace Database\Seeders;

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Database\Seeder;
use ThirdLine\Platform\Authorization\SeedsPermissions;

/**
 * Apply the permission catalog to a fresh install.
 *
 * WHAT THIS FILE USED TO BE, AND WHY IT CHANGED (migration Phase 7.1d). It
 * held 121 permission strings and nine roles' grants inline, ~480 lines, and
 * it was the ONLY place they were written down — except for the four
 * `grant_*_permissions_to_existing_roles` migrations that repeat parts of the
 * same list, because `Permission::create()` throws on a second run and this
 * seeder could therefore never reach an already-deployed tenant.
 *
 * Two sources that must agree and nothing checking that they do. A permission
 * added to one and forgotten in the other works on a fresh install and 403s on
 * every existing customer, discovered months later by one of them.
 *
 * Both now read App\Authorization\RiskPermissionCatalog, which also carries
 * what each permission MEANS — see the catalog for why that is not decoration.
 *
 * It is idempotent now, which the old version was not: syncCatalog uses
 * findOrCreate, so re-running this against a deployed tenant adds what is
 * missing instead of throwing on the first permission that already exists.
 * That is what makes a grant migration a thin call rather than a copy.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    use SeedsPermissions;

    public function run(): void
    {
        $this->syncCatalog(new RiskPermissionCatalog);
    }
}

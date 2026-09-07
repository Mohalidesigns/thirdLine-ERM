<?php

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use ThirdLine\Platform\Authorization\SeedsPermissions;

/**
 * RCSA v2, P1 — the universe permissions, for tenants that already exist.
 *
 * RolesAndPermissionsSeeder covers a fresh install. A deployed tenant never
 * re-runs it, so a grant migration is what reaches them; `grantFromCatalog`
 * makes it a thin call rather than a second copy of the list, and it throws if
 * this file names a permission the catalog does not define.
 *
 * WHO GETS WHAT, and why it is not simply "everyone who had rcsa.view":
 *
 *   risk-manager      the full set including publish and import. The plan's
 *                     "Head of ORM" maps here — the role that owns the master
 *                     data.
 *   risk-owner        view, create and update, but NOT publish. This is the
 *                     plan's "Risk Champion": they know their unit's risks and
 *                     should enter them, and someone else approves them into
 *                     the assessment. §14 Q8 asks the bank whether the
 *                     universe is ORM-only or BU-proposes/ORM-approves; this
 *                     grant is the second reading, which is the safer default
 *                     because it can be widened without re-approving data that
 *                     went live unreviewed.
 *   risk-analyst      view.
 *   compliance-officer view.
 *   super-admin       holds '*' in the catalog; nothing to grant.
 *
 * chief-risk-officer inherits the risk-manager list in the catalog, so it is
 * granted here explicitly for tenants whose CRO role was created from that
 * list before these permissions existed.
 */
return new class extends Migration
{
    use SeedsPermissions;

    private const FULL = [
        'rcsa_universe.view',
        'rcsa_universe.create',
        'rcsa_universe.update',
        'rcsa_universe.delete',
        'rcsa_universe.publish',
        'rcsa_universe.import',
    ];

    public function up(): void
    {
        $this->grantFromCatalog(new RiskPermissionCatalog, [
            'risk-manager' => self::FULL,
            'chief-risk-officer' => self::FULL,
            'risk-owner' => ['rcsa_universe.view', 'rcsa_universe.create', 'rcsa_universe.update'],
            'risk-analyst' => ['rcsa_universe.view'],
            'compliance-officer' => ['rcsa_universe.view'],
        ]);
    }

    public function down(): void
    {
        // Deliberately empty. Deleting the permissions would revoke them from
        // every role that holds them, including any a tenant assigned by hand
        // through the admin screen, and the module they authorise is behind a
        // flag that is off by default — so leaving them in place costs nothing
        // and removing them loses configuration this migration did not make.
    }
};

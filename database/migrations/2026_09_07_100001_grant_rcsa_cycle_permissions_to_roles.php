<?php

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use ThirdLine\Platform\Authorization\SeedsPermissions;

/**
 * RCSA v2, P3 — cycle and assessment permissions, for tenants that already
 * exist.
 *
 * WHO RUNS A CYCLE VERSUS WHO FILLS ONE IN. Opening a cycle copies the whole
 * published universe into an assessment for every business unit and cannot be
 * undone, so it sits with the Head of ORM (`risk-manager`) and the CRO.
 * Answering the three columns is the Risk Champion's job (`risk-owner`), and
 * they need no authority over the cycle itself.
 *
 * SUPER-ADMIN IS SYNCED HERE TOO, unlike the P1 grant migration that started
 * this whole problem. CheckPermission no longer consults the grant table for
 * that role, so this is not what keeps the screens reachable — but
 * `getAllPermissions()` and the role-administration screen still read it, and
 * a role that displays as "129 of 133" for the account meant to hold
 * everything is confusing. See docs/rcsa-v2/super-admin-403.md.
 */
return new class extends Migration
{
    use SeedsPermissions;

    private const CYCLE_ADMIN = [
        'rcsa_cycle.view', 'rcsa_cycle.manage', 'rcsa_cycle.open', 'rcsa_cycle.close',
        'rcsa_assessment.view', 'rcsa_assessment.complete',
    ];

    private const ASSESSOR = [
        'rcsa_cycle.view', 'rcsa_assessment.view', 'rcsa_assessment.complete',
    ];

    private const READER = ['rcsa_cycle.view', 'rcsa_assessment.view'];

    public function up(): void
    {
        $this->grantFromCatalog(new RiskPermissionCatalog, [
            'risk-manager' => self::CYCLE_ADMIN,
            'chief-risk-officer' => self::CYCLE_ADMIN,
            'risk-owner' => self::ASSESSOR,
            'risk-analyst' => self::READER,
            'compliance-officer' => self::READER,
        ]);

        $this->syncSuperAdmin();
    }

    /**
     * Keep super-admin level with the catalog.
     *
     * Identical in intent to migration 100007 and safe to repeat: it grants
     * whatever the catalog defines now, and never revokes.
     */
    private function syncSuperAdmin(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        $role = Role::query()->where('name', 'super-admin')->where('guard_name', $guard)->first();

        if ($role === null) {
            return;
        }

        $role->givePermissionTo(
            collect((new RiskPermissionCatalog)->names())
                ->map(fn (string $name) => Permission::findOrCreate($name, $guard))
                ->all()
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Deliberately empty, as with every grant migration here: revoking
        // would take away permissions a tenant may have assigned by hand, and
        // the module these authorise is behind a flag that ships off.
    }
};

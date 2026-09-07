<?php

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use ThirdLine\Platform\Authorization\SeedsPermissions;

/**
 * RCSA v2, P6 — the bulk download and the export log, for tenants that already
 * exist.
 *
 * EXPORTING AND POLICING EXPORTS ARE DIFFERENT AUTHORITIES. `rcsa_export.bulk`
 * lets somebody take the register and see what THEY have taken;
 * `rcsa_audit.view` is what shows one person everybody else's downloads. §10.2
 * calls the second an administrator's view, and it goes to the Head of ORM and
 * the CRO only — an analyst who could see the whole bank's export history has
 * been handed a surveillance tool nobody asked for.
 *
 * `risk-owner` gets NEITHER. A risk champion's offline working copy is gated on
 * `rcsa_assessment.complete` rather than on the export permission, precisely so
 * that the feature §10.4 calls a differentiator for branch staff does not
 * require handing branch staff the whole estate as a spreadsheet.
 */
return new class extends Migration
{
    use SeedsPermissions;

    public function up(): void
    {
        $this->grantFromCatalog(new RiskPermissionCatalog, [
            'risk-manager' => ['rcsa_export.bulk', 'rcsa_audit.view'],
            'chief-risk-officer' => ['rcsa_export.bulk', 'rcsa_audit.view'],
            'risk-analyst' => ['rcsa_export.bulk'],
            'compliance-officer' => ['rcsa_export.bulk'],
        ]);

        // Keep super-admin level with the catalog — see 100007 and
        // docs/rcsa-v2/super-admin-403.md. Every module that adds permissions
        // has to do this, because the catalog is applied by a seeder that never
        // re-runs on a deployed tenant.
        $guard = config('auth.defaults.guard', 'web');
        $role = Role::query()->where('name', 'super-admin')->where('guard_name', $guard)->first();

        if ($role !== null) {
            $role->givePermissionTo(
                collect((new RiskPermissionCatalog)->names())
                    ->map(fn (string $name) => Permission::findOrCreate($name, $guard))
                    ->all()
            );

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Deliberately empty, as with every grant migration here.
    }
};

<?php

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use ThirdLine\Platform\Authorization\SeedsPermissions;

/**
 * §14 Q5 — who may sign off a treatment override.
 *
 * Granted to the roles that already hold `rcsa_assessment.validate`, because
 * those are the second line and an override is a second-line judgement. NOT
 * granted to `risk-analyst`, who holds `review` but not `validate`: challenging
 * a line and overruling the formula are different weights of decision, and P5
 * split them for the same reason.
 *
 * NOT granted to `risk-owner`. A business-unit head who could approve their own
 * unit's override would be the whole control, and the override is precisely the
 * moment the business asks to depart from what the methodology computed.
 *
 * Granting it changes nothing on its own — the approval step only runs when a
 * tenant sets `settings['rcsa']['treatment_override_approval_required']`.
 */
return new class extends Migration
{
    use SeedsPermissions;

    public function up(): void
    {
        $this->grantFromCatalog(new RiskPermissionCatalog, [
            'risk-manager' => ['rcsa_assessment.approve_override'],
            'chief-risk-officer' => ['rcsa_assessment.approve_override'],
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

<?php

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use ThirdLine\Platform\Authorization\SeedsPermissions;

/**
 * RCSA v2, P4 — `rcsa_assessment.submit`, for tenants that already exist.
 *
 * SUBMITTING IS SEPARATE FROM COMPLETING. Answering the three columns is
 * ordinary work; submission locks every line and hands the assessment to the
 * second line, which is the act §14 Q6 asks the bank about (does a BU Head
 * approve before it reaches ORM?). Granting it to the Risk Champion is the
 * simpler reading and the one the module ships with — a tenant that wants the
 * approval step takes this permission off `risk-owner` and P5's BU-approval
 * state does the rest.
 */
return new class extends Migration
{
    use SeedsPermissions;

    public function up(): void
    {
        $this->grantFromCatalog(new RiskPermissionCatalog, [
            'risk-manager' => ['rcsa_assessment.submit'],
            'chief-risk-officer' => ['rcsa_assessment.submit'],
            'risk-owner' => ['rcsa_assessment.submit'],
        ]);

        // Keep super-admin level with the catalog — see 100007 and
        // docs/rcsa-v2/super-admin-403.md.
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

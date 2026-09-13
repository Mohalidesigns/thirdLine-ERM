<?php

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use ThirdLine\Platform\Authorization\SeedsPermissions;

/**
 * RCSA v2, P5 — the review and action-plan permissions, for tenants that
 * already exist.
 *
 * THE SPLIT IS THE POINT. `review` is the ORM Analyst's: open the queue,
 * challenge a line, escalate. `validate` and `return` are the Head of ORM's —
 * accepting what the business filed, or sending it back — and giving the
 * analyst both would collapse §9's two-person control into one. `approve` goes
 * to `risk-owner`, the business-unit head, and to nobody in the second line;
 * `review` deliberately does NOT, because a unit that can review its own
 * assessment is not being reviewed.
 *
 * On the action-plan side, `close` (the owner's claim) and `verify` (the second
 * line accepting it) are separate for the same reason, and `risk-owner` holds
 * neither `close` nor `verify` at tenant level — an owner marks their own plan
 * complete through `rcsa_actionplan.update`, and somebody else closes it.
 */
return new class extends Migration
{
    use SeedsPermissions;

    public function up(): void
    {
        $this->grantFromCatalog(new RiskPermissionCatalog, [
            'risk-manager' => [
                'rcsa_assessment.review', 'rcsa_assessment.validate', 'rcsa_assessment.return',
                'rcsa_actionplan.view', 'rcsa_actionplan.update', 'rcsa_actionplan.close', 'rcsa_actionplan.verify',
            ],
            'chief-risk-officer' => [
                'rcsa_assessment.review', 'rcsa_assessment.validate', 'rcsa_assessment.return',
                'rcsa_actionplan.view', 'rcsa_actionplan.update', 'rcsa_actionplan.close', 'rcsa_actionplan.verify',
            ],
            'risk-analyst' => [
                'rcsa_assessment.review',
                'rcsa_actionplan.view',
            ],
            'risk-owner' => [
                'rcsa_assessment.approve',
                'rcsa_actionplan.view', 'rcsa_actionplan.update',
            ],
            'compliance-officer' => [
                'rcsa_actionplan.view',
            ],
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

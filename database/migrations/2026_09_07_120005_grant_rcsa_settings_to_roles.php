<?php

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use ThirdLine\Platform\Authorization\SeedsPermissions;

/**
 * The §14 settings screen — who may answer the bank's own questions.
 *
 * `risk-manager` and `chief-risk-officer` only. NOT `risk-analyst`, who runs
 * cycles and reviews assessments: changing how appetite is expressed re-rates
 * a portfolio, and changing a retention period decides what the bank still
 * holds in three years. Both are policy decisions rather than operating ones,
 * which is also why this is separate from `rcsa_cycle.manage`.
 *
 * NOT `risk-owner`. A business-unit head who could raise their own unit's
 * appetite ceiling, or switch off the approval their overrides need, would be
 * configuring away the controls that apply to them.
 */
return new class extends Migration
{
    use SeedsPermissions;

    public function up(): void
    {
        $this->grantFromCatalog(new RiskPermissionCatalog, [
            'risk-manager' => ['rcsa_settings.manage'],
            'chief-risk-officer' => ['rcsa_settings.manage'],
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

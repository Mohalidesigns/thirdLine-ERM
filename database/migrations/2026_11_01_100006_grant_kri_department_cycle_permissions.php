<?php

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use ThirdLine\Platform\Authorization\SeedsPermissions;

/**
 * ADR 0026 section 14 - the departmental KRI cycle's five permissions, for
 * tenants that already exist. The names and descriptions are declared once, in
 * RiskPermissionCatalog; the grants below mirror its roles() so the seeder and
 * this migration agree.
 */
return new class extends Migration
{
    use SeedsPermissions;

    public function up(): void
    {
        $this->grantFromCatalog(new RiskPermissionCatalog, [
            'risk-owner' => ['kri.submit_return'],
            'risk-manager' => [
                'kri.submit_return', 'kri.send_reminder', 'kri.manage_department_contacts',
                'kri.all_departments', 'holiday.manage',
            ],
            'chief-risk-officer' => [
                'kri.submit_return', 'kri.send_reminder', 'kri.manage_department_contacts',
                'kri.all_departments', 'holiday.manage',
            ],
            'risk-analyst' => ['kri.all_departments'],
        ]);

        // Keep super-admin level with the catalog - see 2026_09_06_100007 and
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
        // Deliberately empty, as with every grant migration here: reversing it
        // would revoke authority, and rolling back must not lock anyone out.
    }
};

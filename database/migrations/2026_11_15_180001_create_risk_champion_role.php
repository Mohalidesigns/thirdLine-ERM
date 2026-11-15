<?php

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use ThirdLine\Platform\Authorization\SeedsPermissions;

/**
 * Register the "Risk Champion" / "Risk Champions" role and its permissions
 * for departmental KRI completion.
 */
return new class extends Migration
{
    use SeedsPermissions;

    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $catalog = new RiskPermissionCatalog;

        foreach (['risk-champion', 'risk-champions'] as $roleName) {
            $role = Role::findOrCreate($roleName, $guard);
            $role->syncPermissions(
                collect($catalog->permissionsFor($roleName))
                    ->map(fn (string $name) => Permission::findOrCreate($name, $guard))
                    ->all()
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
    }
};

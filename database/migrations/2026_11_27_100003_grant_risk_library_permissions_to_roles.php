<?php

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use ThirdLine\Platform\Authorization\SeedsPermissions;

/**
 * ADR 0028 Phase 1 — Grant Risk Library permissions additively to existing roles.
 *
 * Uses SeedsPermissions::grantFromCatalog() so deployed tenants receive the
 * new permissions without overwriting custom role assignments.
 */
return new class extends Migration
{
    use SeedsPermissions;

    private const FULL = [
        'risk_library.view',
        'risk_library.create',
        'risk_library.update',
        'risk_library.retire',
    ];

    public function up(): void
    {
        $this->grantFromCatalog(new RiskPermissionCatalog, [
            'chief-risk-officer' => self::FULL,
            'risk-manager' => self::FULL,
            'risk-analyst' => [
                'risk_library.view',
                'risk_library.create',
                'risk_library.update',
            ],
            'risk-owner' => ['risk_library.view'],
            'compliance-officer' => ['risk_library.view'],
            'board-member' => ['risk_library.view'],
        ]);
    }

    public function down(): void
    {
        // Deliberately empty: additive grant migrations never revoke permissions
        // that an administrator may have assigned through the UI.
    }
};

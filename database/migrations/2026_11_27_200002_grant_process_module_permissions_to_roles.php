<?php

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use ThirdLine\Platform\Authorization\SeedsPermissions;

/**
 * ADR 0028 Phase 2 — Grant Process Module & Process Group permissions additively
 * to existing roles.
 *
 * Uses SeedsPermissions::grantFromCatalog() so deployed tenants receive the
 * new permissions without overwriting custom role assignments.
 */
return new class extends Migration
{
    use SeedsPermissions;

    private const FULL = [
        'process.view',
        'process.create',
        'process.update',
        'process_group.manage',
    ];

    private const PROCESS_EDIT = [
        'process.view',
        'process.create',
        'process.update',
    ];

    public function up(): void
    {
        $this->grantFromCatalog(new RiskPermissionCatalog, [
            'chief-risk-officer' => self::FULL,
            'risk-manager' => self::FULL,
            'risk-analyst' => self::PROCESS_EDIT,
            'risk-owner' => self::PROCESS_EDIT,
            'compliance-officer' => ['process.view'],
            'board-member' => ['process.view'],
        ]);
    }

    public function down(): void
    {
        // Deliberately empty: additive grant migrations never revoke permissions
        // that an administrator may have assigned through the UI.
    }
};

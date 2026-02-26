<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Seed RBAC roles and permissions aligned to the TRD.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        /* ------------------------------------------------------------------ */
        /*  Permissions – organized by module                                  */
        /* ------------------------------------------------------------------ */

        $permissions = [
            // Risk module
            'risk.view',
            'risk.create',
            'risk.edit',
            'risk.delete',
            'risk.approve',
            'risk.admin',

            // Assessment module
            'assessment.view',
            'assessment.create',
            'assessment.submit',
            'assessment.approve',
            'assessment.reject',

            // Control module
            'control.view',
            'control.create',
            'control.edit',
            'control.delete',

            // Treatment module
            'treatment.view',
            'treatment.create',
            'treatment.edit',
            'treatment.approve',

            // KRI module
            'kri.view',
            'kri.create',
            'kri.edit',
            'kri.record_measurement',

            // Appetite module
            'appetite.view',
            'appetite.manage',
            'appetite.approve',

            // Loss Event module
            'loss_event.view',
            'loss_event.create',
            'loss_event.edit',
            'loss_event.approve',
            'loss_event.cbn_notify',

            // Issue module
            'issue.view',
            'issue.create',
            'issue.edit',
            'issue.escalate',
            'issue.close',

            // Quantification module
            'quantification.view',
            'quantification.create',
            'quantification.run_simulation',
            'quantification.approve_icaap',

            // Report module
            'report.view',
            'report.generate',
            'report.export',

            // Admin module
            'admin.users',
            'admin.settings',
            'admin.organization',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        /* ------------------------------------------------------------------ */
        /*  Roles                                                              */
        /* ------------------------------------------------------------------ */

        // 1. super-admin – gets ALL permissions
        $superAdmin = Role::create(['name' => 'super-admin']);
        $superAdmin->givePermissionTo(Permission::all());

        // 2. risk-manager – all risk/assessment/control/treatment/kri/appetite/report permissions
        $riskManager = Role::create(['name' => 'risk-manager']);
        $riskManager->givePermissionTo([
            'risk.view', 'risk.create', 'risk.edit', 'risk.delete', 'risk.approve', 'risk.admin',
            'assessment.view', 'assessment.create', 'assessment.submit', 'assessment.approve', 'assessment.reject',
            'control.view', 'control.create', 'control.edit', 'control.delete',
            'treatment.view', 'treatment.create', 'treatment.edit', 'treatment.approve',
            'kri.view', 'kri.create', 'kri.edit', 'kri.record_measurement',
            'appetite.view', 'appetite.manage', 'appetite.approve',
            'report.view', 'report.generate', 'report.export',
        ]);

        // 3. risk-owner – limited risk editing + assessment/treatment creation
        $riskOwner = Role::create(['name' => 'risk-owner']);
        $riskOwner->givePermissionTo([
            'risk.view', 'risk.edit',
            'assessment.create',
            'treatment.view', 'treatment.create',
        ]);

        // 4. risk-analyst – view-focused + assessment/kri/report
        $riskAnalyst = Role::create(['name' => 'risk-analyst']);
        $riskAnalyst->givePermissionTo([
            'risk.view',
            'assessment.view', 'assessment.create',
            'kri.view', 'kri.record_measurement',
            'report.view', 'report.generate',
        ]);

        // 5. chief-risk-officer – risk-manager perms + loss_event.approve, issue.escalate, quantification, appetite.approve
        $cro = Role::create(['name' => 'chief-risk-officer']);
        $cro->givePermissionTo([
            // All risk-manager permissions
            'risk.view', 'risk.create', 'risk.edit', 'risk.delete', 'risk.approve', 'risk.admin',
            'assessment.view', 'assessment.create', 'assessment.submit', 'assessment.approve', 'assessment.reject',
            'control.view', 'control.create', 'control.edit', 'control.delete',
            'treatment.view', 'treatment.create', 'treatment.edit', 'treatment.approve',
            'kri.view', 'kri.create', 'kri.edit', 'kri.record_measurement',
            'appetite.view', 'appetite.manage', 'appetite.approve',
            'report.view', 'report.generate', 'report.export',
            // Additional CRO permissions
            'loss_event.view', 'loss_event.create', 'loss_event.edit', 'loss_event.approve', 'loss_event.cbn_notify',
            'issue.view', 'issue.create', 'issue.edit', 'issue.escalate', 'issue.close',
            'quantification.view', 'quantification.create', 'quantification.run_simulation', 'quantification.approve_icaap',
        ]);

        // 6. compliance-officer – read-focused + loss events and issues
        $complianceOfficer = Role::create(['name' => 'compliance-officer']);
        $complianceOfficer->givePermissionTo([
            'risk.view',
            'loss_event.view',
            'issue.view', 'issue.create',
            'report.view',
        ]);

        // 7. board-member – read-only
        $boardMember = Role::create(['name' => 'board-member']);
        $boardMember->givePermissionTo([
            'risk.view',
            'report.view',
        ]);

        // 8. loss-event-manager – all loss_event + issue view/create
        $lossEventManager = Role::create(['name' => 'loss-event-manager']);
        $lossEventManager->givePermissionTo([
            'loss_event.view', 'loss_event.create', 'loss_event.edit', 'loss_event.approve', 'loss_event.cbn_notify',
            'issue.view', 'issue.create',
        ]);

        // 9. issue-manager – all issue + loss_event view
        $issueManager = Role::create(['name' => 'issue-manager']);
        $issueManager->givePermissionTo([
            'issue.view', 'issue.create', 'issue.edit', 'issue.escalate', 'issue.close',
            'loss_event.view',
        ]);
    }
}

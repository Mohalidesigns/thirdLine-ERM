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
        /*  Permissions – organized by module */
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
            'treatment.delete',

            // KRI module
            'kri.view',
            'kri.create',
            'kri.edit',
            'kri.record_measurement',
            'kri.acknowledge_breach',
            'kri.delete',

            // Reporting periods (WP-04). Selecting a period rides on
            // dashboard.view; these govern the calendar itself. Reopening a
            // closed period can move a number a board pack was built on, so it
            // is separate from closing one and is not granted to the roles that
            // merely run the close.
            'period.view',
            'period.close',
            'period.reopen',

            // Formula thresholds and their re-baselining approvals.
            'threshold.view',
            'threshold.manage',
            'threshold.rebaseline_approve',

            // The measure engine itself: definitions and recorded values.
            'measure.view',
            'measure.manage',
            'measure.record',

            // FX rates. A rate override flows straight into a regulatory
            // threshold test, so recording one is its own permission.
            'fx_rate.view',
            'fx_rate.manage',

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
            'loss_event.delete',

            // Issue module
            'issue.view',
            'issue.create',
            'issue.edit',
            'issue.escalate',
            'issue.close',
            'issue.delete',

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
            // Federation config is an authentication-bypass vector if it is
            // wrong, so it is grantable separately from the rest of settings.
            'admin.sso',

            /* -------------------------------------------------------------- */
            /*  Added by WP-00 TASK 2: modules that shipped with routes but no */
            /*  permission to guard them. Same module.action convention. */
            /* -------------------------------------------------------------- */

            // Landing pages every authenticated user needs
            'dashboard.view',
            'notification.view',
            'document.view',

            // Scoping / entity management
            'entity.view',
            'entity.create',
            'entity.edit',
            'entity.delete',

            // RCSA
            'rcsa.view',
            'rcsa.submit',

            // Analysis (heatmap, bowtie, trends, correlation)
            'analysis.view',

            // AI intelligence and live LLM tooling
            'ai.view',
            'ai.use',

            // Control testing
            'control_test.view',
            'control_test.create',
            'control_test.edit',
            'control_test.execute',
            'control_test.review',

            // Assessment campaigns
            'campaign.view',
            'campaign.create',
            'campaign.manage',
            'campaign.respond',
            'campaign.review',

            // Questionnaire engine
            'questionnaire.view',
            'questionnaire.create',
            'questionnaire.edit',
            'questionnaire.publish',

            // Workflow engine
            'workflow.view',
            'workflow.manage',
            'workflow.act',

            // WP-06: a person's own task queue. Held apart from workflow.* on
            // purpose — every user must be able to see and clear what has been
            // assigned to them, and nobody should need workflow.manage (which
            // grants redesigning the process) to approve one thing.
            // Whether a given task is theirs is settled by the engine, which
            // checks the assignment AND the module's existing Gate.
            'task.view',
            'task.act',

            // Regulatory compliance
            'regulatory.view',
            'regulatory.manage',
            'regulatory.file',

            // Data import
            'import.view',
            'import.create',
            'import.process',

            // Approvals inbox
            'approval.view',
            'approval.act',

            /* -------------------------------------------------------------- */
            /*  Added by WP-05: the configuration surface. */
            /* -------------------------------------------------------------- */

            // The object type builder: types, attributes, relationship types
            // and lifecycles. Held apart from admin.settings because these
            // change the shape of every record in the tenant, not a preference.
            'admin.metadata',

            // Scoring profiles. Separate again: resizing a matrix re-rates the
            // entire register, which is a risk-governance act rather than an
            // administrative one, and in most institutions the person who may
            // add users is not the person who may redefine what Critical means.
            'admin.scoring',

            // Export, diff, import and rollback of configuration bundles.
            // An import rewrites the tenant's whole definition set, so this is
            // the narrowest grant in the product and is deliberately not part
            // of admin.metadata.
            'admin.configuration',

            /* -------------------------------------------------------------- */
            /*  Added by WP-07: the integration surface. */
            /* -------------------------------------------------------------- */

            // The queue dashboard. Held apart from admin.settings because a job
            // payload IS the record it operates on — a queued notification
            // carries its subject and body, an import job the file it reads —
            // so Horizon on this platform shows loss events and examination
            // findings, not just throughput.
            'admin.queues',

            // The OpenAPI specification: a complete map of the API surface,
            // which on a risk register is reconnaissance material.
            'api.docs',

            // Issue and revoke one's OWN API tokens. Universal, like task.*:
            // anyone who may use the application may automate their own use of
            // it, and the token can never exceed its owner's permissions.
            'api.tokens',

            // Issue machine-to-machine tokens for the whole organization, and
            // revoke anybody's. A separate, much narrower grant.
            'api.tokens.manage',

            // Webhook subscriptions. Creating one sends this tenant's data to
            // an external URL on every matching event, so it is an integration
            // decision rather than a preference.
            'webhook.view',
            'webhook.manage',

            // Connectors: scheduled pulls into the measure engine, holding
            // encrypted credentials for the systems they read.
            'connector.view',
            'connector.manage',
            'connector.run',

            // Background jobs raised by this user, and the ability to cancel
            // them. Universal for the same reason as api.tokens: a job you
            // started is yours to stop.
            'job.view',

            /* -------------------------------------------------------------- */
            /*  Added by WP-08: the widget engine and its two surfaces. */
            /* -------------------------------------------------------------- */

            // Business HQ (/hq/{node}) and My Responsibilities (/my).
            // Universal, like task.*: a first-line user who cannot see their
            // own node's page or their own queue will not participate, and
            // what either page actually shows is decided by tenancy,
            // GraphScope and per-object permissions — not by this grant.
            'hq.view',
            'my.view',

            // Global search. Results are permission-filtered per object; the
            // grant only opens the box.
            'search.view',

            // Building and publishing dashboards. Held apart from
            // admin.settings: what a role sees when it logs in is a
            // risk-governance decision, and the person composing the board's
            // view is rarely the person who adds users.
            'dashboard.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        /* ------------------------------------------------------------------ */
        /*  Roles */
        /* ------------------------------------------------------------------ */

        // Every authenticated role needs a landing page, their own notification
        // bell and the shared document repository. Granting these per-role
        // rather than exempting the routes keeps the "no route without a
        // permission" invariant intact.
        $baseline = [
            'dashboard.view',
            'notification.view',
            'document.view',
            // WP-06. Everyone has a task queue; what is in it is decided by the
            // engine, not by this grant.
            'task.view',
            'task.act',
            // WP-07. A job you started is yours to watch and to stop, and a
            // token can never exceed the permissions of the person who issued
            // it — so both are safe to grant universally.
            'job.view',
            'api.tokens',
            // WP-08. Your node's page, your own queue, and the search box.
            // What they show is decided elsewhere; see the permission list.
            'hq.view',
            'my.view',
            'search.view',
        ];

        // 1. super-admin – gets ALL permissions
        $superAdmin = Role::create(['name' => 'super-admin']);
        $superAdmin->givePermissionTo(Permission::all());

        // 2. risk-manager – all risk/assessment/control/treatment/kri/appetite/report permissions
        $riskManager = Role::create(['name' => 'risk-manager']);
        $riskManager->givePermissionTo(array_merge($baseline, [
            'risk.view', 'risk.create', 'risk.edit', 'risk.delete', 'risk.approve', 'risk.admin',
            'assessment.view', 'assessment.create', 'assessment.submit', 'assessment.approve', 'assessment.reject',
            'control.view', 'control.create', 'control.edit', 'control.delete',
            'treatment.view', 'treatment.create', 'treatment.edit', 'treatment.approve', 'treatment.delete',
            'kri.view', 'kri.create', 'kri.edit', 'kri.record_measurement', 'kri.delete',
            'appetite.view', 'appetite.manage', 'appetite.approve',
            'report.view', 'report.generate', 'report.export',
            'entity.view', 'entity.create', 'entity.edit', 'entity.delete',
            'rcsa.view', 'rcsa.submit',
            'analysis.view',
            'ai.view', 'ai.use',
            'control_test.view', 'control_test.create', 'control_test.edit', 'control_test.execute', 'control_test.review',
            'campaign.view', 'campaign.create', 'campaign.manage', 'campaign.respond', 'campaign.review',
            'questionnaire.view', 'questionnaire.create', 'questionnaire.edit', 'questionnaire.publish',
            'workflow.view', 'workflow.manage', 'workflow.act',
            'regulatory.view', 'regulatory.manage',
            'import.view', 'import.create', 'import.process',
            // WP-04 measure engine, periods and thresholds
            'kri.acknowledge_breach',
            'period.view', 'period.close',
            'threshold.view', 'threshold.manage', 'threshold.rebaseline_approve',
            'measure.view', 'measure.manage', 'measure.record',
            'fx_rate.view', 'fx_rate.manage',
            'approval.view', 'approval.act',
            // WP-08: composing and publishing dashboards is the risk
            // function's job, alongside the CRO.
            'dashboard.manage',
        ]));

        // 3. risk-owner – limited risk editing + assessment/treatment creation
        $riskOwner = Role::create(['name' => 'risk-owner']);
        $riskOwner->givePermissionTo(array_merge($baseline, [
            'risk.view', 'risk.edit',
            'assessment.create',
            'treatment.view', 'treatment.create',
            'rcsa.view', 'rcsa.submit',
            'analysis.view',
            'campaign.view', 'campaign.respond',
            'control_test.view',
            'approval.view',
        ]));

        // 4. risk-analyst – view-focused + assessment/kri/report
        $riskAnalyst = Role::create(['name' => 'risk-analyst']);
        $riskAnalyst->givePermissionTo(array_merge($baseline, [
            'risk.view',
            'assessment.view', 'assessment.create',
            'kri.view', 'kri.record_measurement',
            'report.view', 'report.generate',
            'rcsa.view',
            'analysis.view',
            'ai.view',
            'control_test.view',
            'campaign.view', 'campaign.respond',
            // WP-04 measure engine, periods and thresholds
            'kri.acknowledge_breach',
            'period.view',
            'threshold.view',
            'measure.view', 'measure.record',
            'fx_rate.view',
            'regulatory.view',
        ]));

        // 5. chief-risk-officer – risk-manager perms + loss_event.approve, issue.escalate, quantification, appetite.approve
        $cro = Role::create(['name' => 'chief-risk-officer']);
        $cro->givePermissionTo(array_merge($baseline, [
            // All risk-manager permissions
            'risk.view', 'risk.create', 'risk.edit', 'risk.delete', 'risk.approve', 'risk.admin',
            'assessment.view', 'assessment.create', 'assessment.submit', 'assessment.approve', 'assessment.reject',
            'control.view', 'control.create', 'control.edit', 'control.delete',
            'treatment.view', 'treatment.create', 'treatment.edit', 'treatment.approve', 'treatment.delete',
            'kri.view', 'kri.create', 'kri.edit', 'kri.record_measurement', 'kri.delete',
            'appetite.view', 'appetite.manage', 'appetite.approve',
            'report.view', 'report.generate', 'report.export',
            // Additional CRO permissions
            'loss_event.view', 'loss_event.create', 'loss_event.edit', 'loss_event.approve', 'loss_event.cbn_notify', 'loss_event.delete',
            'issue.view', 'issue.create', 'issue.edit', 'issue.escalate', 'issue.close', 'issue.delete',
            'quantification.view', 'quantification.create', 'quantification.run_simulation', 'quantification.approve_icaap',
            // Modules added by WP-00 TASK 2
            'entity.view', 'entity.create', 'entity.edit', 'entity.delete',
            'rcsa.view', 'rcsa.submit',
            'analysis.view',
            'ai.view', 'ai.use',
            'control_test.view', 'control_test.create', 'control_test.edit', 'control_test.execute', 'control_test.review',
            'campaign.view', 'campaign.create', 'campaign.manage', 'campaign.respond', 'campaign.review',
            'questionnaire.view', 'questionnaire.create', 'questionnaire.edit', 'questionnaire.publish',
            'workflow.view', 'workflow.manage', 'workflow.act',
            'regulatory.view', 'regulatory.manage', 'regulatory.file',
            'import.view', 'import.create', 'import.process',
            // WP-04 measure engine, periods and thresholds
            'kri.acknowledge_breach',
            'period.view', 'period.close',
            'threshold.view', 'threshold.manage', 'threshold.rebaseline_approve',
            'measure.view', 'measure.manage', 'measure.record',
            'fx_rate.view', 'fx_rate.manage',
            'approval.view', 'approval.act',
            // WP-08
            'dashboard.manage',
        ]));

        // 6. compliance-officer – read-focused + loss events and issues
        $complianceOfficer = Role::create(['name' => 'compliance-officer']);
        $complianceOfficer->givePermissionTo(array_merge($baseline, [
            'risk.view',
            'loss_event.view',
            'issue.view', 'issue.create',
            'report.view',
            'analysis.view',
            'rcsa.view',
            'control_test.view', 'control_test.review',
            'regulatory.view', 'regulatory.manage', 'regulatory.file',
            // WP-04 measure engine, periods and thresholds
            'period.view',
            'threshold.view',
            'measure.view',
            'fx_rate.view',
            'approval.view',
        ]));

        // 7. board-member – read-only
        $boardMember = Role::create(['name' => 'board-member']);
        $boardMember->givePermissionTo(array_merge($baseline, [
            'risk.view',
            'report.view',
            // WP-04 measure engine, periods and thresholds
            'period.view',
            'threshold.view',
            'measure.view',
            'fx_rate.view',
            'analysis.view',
        ]));

        // 8. loss-event-manager – all loss_event + issue view/create
        $lossEventManager = Role::create(['name' => 'loss-event-manager']);
        $lossEventManager->givePermissionTo(array_merge($baseline, [
            'loss_event.view', 'loss_event.create', 'loss_event.edit', 'loss_event.approve', 'loss_event.cbn_notify', 'loss_event.delete',
            'issue.view', 'issue.create',
            'analysis.view',
            'approval.view', 'approval.act',
        ]));

        // 9. issue-manager – all issue + loss_event view
        $issueManager = Role::create(['name' => 'issue-manager']);
        $issueManager->givePermissionTo(array_merge($baseline, [
            'issue.view', 'issue.create', 'issue.edit', 'issue.escalate', 'issue.close', 'issue.delete',
            'loss_event.view',
            'analysis.view',
            'approval.view', 'approval.act',
        ]));
    }
}

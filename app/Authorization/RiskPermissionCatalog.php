<?php

namespace App\Authorization;

use ThirdLine\Platform\Authorization\PermissionCatalog;

/**
 * Every permission this product defines, what it means, and who holds it.
 *
 * ONE DECLARATION (migration Phase 7.1d). Before this, a permission was
 * declared in RolesAndPermissionsSeeder for fresh installs and again in a
 * hand-written grant migration for every already-deployed tenant — because
 * `Permission::create()` throws on a second run, so the seeder could never
 * reach an existing install. Four such migrations exist. Nothing checked that
 * they agreed with the seeder, and a permission added to one and forgotten in
 * the other works on staging and 403s in production, for one customer, months
 * later.
 *
 * THE DESCRIPTIONS ARE LOAD-BEARING. `admin.metadata`, `admin.scoring` and
 * `admin.configuration` all read as "administration" to whoever assigns a role,
 * and each is a different kind of authority: reshaping every record in the
 * tenant, redefining what Critical means, and replacing the whole definition
 * set. A list of 121 bare strings cannot be granted safely. Where the seeder
 * carried that reasoning in a comment, it is a description here — a comment
 * reaches no screen and no test.
 *
 * NAMING IS `resource.verb`, settled in the migration programme's Decision 2
 * and unchanged.
 */
class RiskPermissionCatalog extends PermissionCatalog
{
    /**
     * @return array<string, array<string, string>>
     */
    public function modules(): array
    {
        return [
            'Risk register' => [
                'risk.view' => 'See the risk register and individual risks.',
                'risk.create' => 'Add a risk to the register.',
                'risk.edit' => 'Change a risk that already exists.',
                'risk.delete' => 'Remove a risk from the register.',
                'risk.approve' => 'Approve a risk through its review workflow.',
                'risk.admin' => 'Administer the register itself, beyond editing individual risks.',
            ],

            'Assessments' => [
                'assessment.view' => 'See risk assessments and their scores.',
                'assessment.create' => 'Start a new assessment of a risk.',
                'assessment.submit' => 'Submit a completed assessment for review.',
                'assessment.approve' => 'Approve a submitted assessment, fixing the score it carries.',
                'assessment.reject' => 'Return a submitted assessment for rework.',
            ],

            'Controls' => [
                'control.view' => 'See the control library and individual controls.',
                'control.create' => 'Add a control.',
                'control.edit' => 'Change an existing control.',
                'control.delete' => 'Remove a control.',
            ],

            'Control testing' => [
                'control_test.view' => 'See control tests and their results.',
                'control_test.create' => 'Schedule a control test.',
                'control_test.edit' => 'Change a control test before it is executed.',
                'control_test.execute' => 'Carry out a control test and record its result.',
                'control_test.review' => 'Review an executed control test and accept or return it.',
            ],

            'Treatments' => [
                'treatment.view' => 'See treatment plans and their progress.',
                'treatment.create' => 'Raise a treatment plan against a risk.',
                'treatment.edit' => 'Change a treatment plan.',
                'treatment.approve' => 'Approve a treatment plan through its workflow.',
                'treatment.delete' => 'Remove a treatment plan.',
            ],

            'Key risk indicators' => [
                'kri.view' => 'See KRIs and their measurements.',
                'kri.create' => 'Define a new KRI.',
                'kri.edit' => 'Change a KRI definition.',
                'kri.record_measurement' => 'Record a measurement against a KRI.',
                'kri.acknowledge_breach' => 'Acknowledge a threshold breach, recording who accepted it.',
                'kri.delete' => 'Remove a KRI.',
            ],

            'Reporting periods' => [
                // Selecting a period rides on dashboard.view; these govern the
                // calendar itself.
                'period.view' => 'See the reporting calendar and which periods are open.',
                'period.close' => 'Close a reporting period, fixing the figures reported for it.',
                // Reopening can move a number a board pack was built on, so it
                // is separate from closing and is not granted to the roles that
                // merely run the close.
                'period.reopen' => 'Reopen a closed period — this can move a figure a board pack was built on.',
            ],

            'Thresholds' => [
                'threshold.view' => 'See formula thresholds and their current values.',
                'threshold.manage' => 'Define and change thresholds.',
                'threshold.rebaseline_approve' => 'Approve a re-baselining, which changes what counts as a breach.',
            ],

            'Measure engine' => [
                'measure.view' => 'See measure definitions and recorded values.',
                'measure.manage' => 'Define and change measures.',
                'measure.record' => 'Record a value against a measure.',
            ],

            'FX rates' => [
                'fx_rate.view' => 'See exchange rates used in conversions.',
                // A rate override flows straight into a regulatory threshold
                // test, so recording one is its own permission.
                'fx_rate.manage' => 'Override an exchange rate — this flows straight into regulatory threshold tests.',
            ],

            'Risk appetite' => [
                'appetite.view' => 'See appetite statements and current utilisation.',
                'appetite.manage' => 'Define and change appetite statements.',
                'appetite.approve' => 'Approve an appetite statement, making it the standard the register is measured against.',
            ],

            'Loss events' => [
                'loss_event.view' => 'See recorded loss events.',
                'loss_event.create' => 'Record a loss event.',
                'loss_event.edit' => 'Change a recorded loss event.',
                'loss_event.approve' => 'Approve a loss event through its workflow.',
                'loss_event.cbn_notify' => 'File a loss event notification with the Central Bank of Nigeria.',
                'loss_event.delete' => 'Remove a loss event.',
            ],

            'Issues' => [
                'issue.view' => 'See raised issues and their status.',
                'issue.create' => 'Raise an issue.',
                'issue.edit' => 'Change an issue.',
                'issue.escalate' => 'Escalate an issue to a higher level of oversight.',
                'issue.close' => 'Close an issue, asserting it is resolved.',
                'issue.delete' => 'Remove an issue.',
            ],

            'Quantification' => [
                'quantification.view' => 'See scenarios, simulations and capital figures.',
                'quantification.create' => 'Define a quantification scenario.',
                'quantification.run_simulation' => 'Run a Monte Carlo simulation.',
                'quantification.approve_icaap' => 'Approve an ICAAP assessment — the capital adequacy position the board is told.',
            ],

            'Reports' => [
                'report.view' => 'See generated reports.',
                'report.generate' => 'Generate a report.',
                'report.export' => 'Download a report as PDF or spreadsheet.',
            ],

            'Analysis' => [
                'analysis.view' => 'See the heatmap, bow-tie, trend and correlation screens.',
            ],

            'Regulatory compliance' => [
                'regulatory.view' => 'See regulatory requirements, circulars and deadlines.',
                'regulatory.manage' => 'Maintain the regulatory library and its mappings.',
                'regulatory.file' => 'Mark a regulatory return as filed.',
            ],

            'RCSA' => [
                'rcsa.view' => 'See risk and control self-assessment worksheets.',
                'rcsa.submit' => 'Submit an RCSA worksheet.',
            ],

            // The rewritten RCSA module, behind the `rcsa_v2` flag. Separate
            // from the two above, which belong to the module it will replace:
            // during the parallel run both are live and a role may hold one
            // without the other. Named `rcsa_universe.*` rather than the plan's
            // `rcsa.universe.*` because permission naming here is
            // `resource.verb` in two segments (migration Decision 2) — see
            // `control_test.view` for the same compound resource.
            'RCSA Universe' => [
                'rcsa_universe.view' => 'See the RCSA Universe: processes, risks and their controls.',
                'rcsa_universe.create' => 'Add a risk to the RCSA Universe.',
                'rcsa_universe.update' => 'Change a universe risk or its controls.',
                'rcsa_universe.delete' => 'Remove a universe risk that was never assessed.',
                'rcsa_universe.publish' => 'Approve a universe risk into future assessments, or retire it. '
                    .'Separate from editing: this is what makes the master data authoritative.',
                'rcsa_universe.import' => 'Bulk-upload universe rows from the RCSA template.',
            ],

            'RCSA Cycles' => [
                'rcsa_cycle.view' => 'See RCSA cycles and their progress.',
                'rcsa_cycle.manage' => 'Create and schedule an RCSA cycle.',
                'rcsa_cycle.open' => 'Open a cycle, which copies the published universe into an '
                    .'assessment for every business unit. This cannot be undone.',
                'rcsa_cycle.close' => 'Close a cycle, freezing every assessment under it.',
            ],

            'RCSA Assessments' => [
                'rcsa_assessment.view' => 'See RCSA assessments and the risks in them.',
                'rcsa_assessment.complete' => 'Answer likelihood, impact and control effectiveness on an assessment, '
                    .'and record the action plans for risks above appetite.',
                'rcsa_assessment.submit' => 'Submit a completed assessment for ORM review. Separate from completing '
                    .'it: submission locks every line and is what hands the work to the second line.',
                // The optional BU-head step of §9.1. NOT in the plan's §11
                // list, which names only review|validate|return — because the
                // plan treats BU approval as a switch rather than a role. A
                // switch still needs somebody authorised to flick it, and
                // reusing `submit` would let the person who filed the
                // assessment approve their own.
                'rcsa_assessment.approve' => 'Approve your business unit\'s assessment so it reaches ORM. '
                    .'Only used where the tenant has enabled the BU-head step.',
            ],

            // §9.2 — the second line's work on somebody else's assessment.
            // Three permissions, not one, because they are three different
            // authorities: an analyst may challenge every line without being
            // the person who accepts the assessment or sends it back.
            'RCSA Review' => [
                'rcsa_assessment.review' => 'Open the ORM review queue, take an assessment for review, and '
                    .'challenge or accept individual risks. Includes escalating one without deciding it.',
                'rcsa_assessment.validate' => 'Validate a reviewed assessment — the second line accepting what '
                    .'the business filed.',
                'rcsa_assessment.return' => 'Return an assessment for rework, which reopens the flagged risks '
                    .'and only those.',
            ],

            // §11 — the scoping escape hatch. A permission rather than a
            // null assignment list, because "no assignments means everything"
            // is a scoping system that fails open on exactly the accounts
            // nobody has configured.
            'RCSA Scope' => [
                'rcsa_scope.all_units' => 'See every business unit\'s RCSA, not only the ones you are assigned to. '
                    .'The Head of ORM, the CRO and Internal Audit hold this; a risk champion does not.',
                'rcsa_scope.assign' => 'Assign users to the business units whose RCSA they may see.',
            ],

            // §10 — the bulk download, its log and the dashboards. Named
            // `rcsa_export.*` rather than the plan's `rcsa.export.bulk` for the
            // same reason as every other RCSA permission: two segments,
            // `resource.verb` (migration Decision 2).
            'RCSA Reporting' => [
                'rcsa_export.bulk' => 'Download the RCSA assessment register as the 23-column workbook. '
                    .'A completed RCSA is the bank\'s operational risk profile in one file, so every '
                    .'export is logged with the user, the filters, the row count and the IP.',
                'rcsa_audit.view' => 'See every user\'s RCSA export history, not only your own, and the '
                    .'workflow audit trail behind an assessment.',
            ],

            // §9.3 — the remediation register, which outlives the cycle that
            // produced it. `close` and `verify` are separate on purpose: the
            // owner claims the control is in place, the second line accepts
            // that it is, and one person doing both is how a remediation
            // register comes to be 100% complete and empty of controls.
            'RCSA Action Plans' => [
                'rcsa_actionplan.view' => 'See the RCSA action-plan register and its ageing.',
                'rcsa_actionplan.update' => 'Record progress on an action plan you own, and ask for an extension.',
                'rcsa_actionplan.close' => 'Mark an action plan complete with evidence, and approve extension requests.',
                'rcsa_actionplan.verify' => 'Verify that a completed action plan really is in place, which is what '
                    .'closes it.',
            ],

            'Campaigns' => [
                'campaign.view' => 'See assessment campaigns.',
                'campaign.create' => 'Create a campaign.',
                'campaign.manage' => 'Run a campaign: launch it, chase it, close it.',
                'campaign.respond' => 'Answer a campaign questionnaire.',
                'campaign.review' => 'Review a submitted campaign response.',
            ],

            'Questionnaires' => [
                'questionnaire.view' => 'See questionnaire definitions.',
                'questionnaire.create' => 'Create a questionnaire.',
                'questionnaire.edit' => 'Change a questionnaire.',
                'questionnaire.publish' => 'Publish a questionnaire, making it usable by a campaign.',
            ],

            'Scoping' => [
                'entity.view' => 'See the organisational entities risks are scoped to.',
                'entity.create' => 'Add an entity.',
                'entity.edit' => 'Change an entity.',
                'entity.delete' => 'Remove an entity.',
            ],

            'Workflow' => [
                'workflow.view' => 'See workflow definitions and running instances.',
                'workflow.manage' => 'Design and publish workflow definitions.',
                'workflow.act' => 'Act on a workflow step outside your own task queue.',
            ],

            'Tasks and approvals' => [
                // Held apart from workflow.* on purpose: every user must be able
                // to clear what is assigned to them, and nobody should need
                // workflow.manage — which grants redesigning the process — to
                // approve one thing.
                'task.view' => 'See your own task queue.',
                'task.act' => 'Decide a task assigned to you.',
                'approval.view' => 'See the approvals inbox.',
                'approval.act' => 'Approve or reject a request in the approvals inbox.',
            ],

            'Data import' => [
                'import.view' => 'See past imports and their outcomes.',
                'import.create' => 'Upload a file for import.',
                'import.process' => 'Commit a validated import into the register.',
            ],

            'AI' => [
                'ai.view' => 'See AI-generated analysis and its provenance.',
                'ai.use' => 'Run a live model call.',
            ],

            'Landing pages' => [
                'dashboard.view' => 'Sign in and reach a landing page. Every authenticated role holds this.',
                'notification.view' => 'See your own notifications.',
                'document.view' => 'See the shared document repository.',
                // What either page shows is decided by tenancy, GraphScope and
                // per-object permissions — not by this grant.
                'hq.view' => 'See a business unit\'s HQ page.',
                'my.view' => 'See your own responsibilities page.',
                // Results are permission-filtered per object; the grant only
                // opens the box.
                'search.view' => 'Use global search.',
            ],

            'Dashboards' => [
                // Held apart from admin.settings: what a role sees when it logs
                // in is a risk-governance decision, and the person composing the
                // board's view is rarely the person who adds users.
                'dashboard.manage' => 'Build and publish dashboards other people see.',
            ],

            'Administration' => [
                'admin.users' => 'Add, edit and deactivate users, and assign their roles.',
                'admin.settings' => 'Change organisation settings and risk configuration.',
                'admin.organization' => 'Change the organisation profile used on generated documents.',
                // An authentication-bypass vector if it is wrong, so grantable
                // separately from the rest of settings.
                'admin.sso' => 'Configure single sign-on — a wrong setting here is an authentication bypass.',
                // These change the shape of every record in the tenant.
                'admin.metadata' => 'Reshape object types, attributes, relationships and lifecycles — this changes every record in the tenant.',
                // Resizing a matrix re-rates the entire register.
                'admin.scoring' => 'Redefine scoring profiles — resizing a matrix re-rates the entire register.',
                // An import rewrites the tenant's whole definition set.
                'admin.configuration' => 'Export, diff, import and roll back configuration bundles — an import replaces the tenant\'s whole definition set.',
                // A job payload IS the record it operates on, so the queue
                // dashboard shows loss events and findings, not just throughput.
                'admin.queues' => 'See the job queue dashboard, whose payloads contain the records being processed.',
            ],

            'Licensing' => [
                // A licence binds the whole deployment, not one organisation's
                // settings, so both go to super-admin only.
                'license.view' => 'See the deployment\'s licence state.',
                'license.manage' => 'Activate or deactivate the deployment\'s licence.',
            ],

            'Integrations' => [
                // A complete map of the API surface, which on a risk register is
                // reconnaissance material.
                'api.docs' => 'Read the OpenAPI specification, a complete map of the API surface.',
                // A token can never exceed its owner's permissions, so this is
                // safe to grant universally.
                'api.tokens' => 'Issue and revoke your own API tokens, which can never exceed your own permissions.',
                'api.tokens.manage' => 'Issue machine-to-machine tokens for the organisation and revoke anybody\'s.',
                'webhook.view' => 'See webhook subscriptions and their delivery log.',
                // Creating one sends this tenant's data to an external URL on
                // every matching event.
                'webhook.manage' => 'Create webhook subscriptions, which send this tenant\'s data to an external URL.',
                'connector.view' => 'See connectors and their run history.',
                'connector.manage' => 'Configure connectors, including the credentials they hold.',
                'connector.run' => 'Run a connector on demand.',
                // A job you started is yours to stop.
                'job.view' => 'See and cancel the background jobs you started.',
            ],
        ];
    }

    /**
     * Permissions every authenticated role holds.
     *
     * @return list<string>
     */
    public function baseline(): array
    {
        return [
            'dashboard.view',
            'notification.view',
            'document.view',
            'task.view',
            'task.act',
            'job.view',
            'api.tokens',
            'hq.view',
            'my.view',
            'search.view',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function roles(): array
    {
        $riskManager = [
            'risk.view', 'risk.create', 'risk.edit', 'risk.delete', 'risk.approve', 'risk.admin',
            'assessment.view', 'assessment.create', 'assessment.submit', 'assessment.approve', 'assessment.reject',
            'control.view', 'control.create', 'control.edit', 'control.delete',
            'treatment.view', 'treatment.create', 'treatment.edit', 'treatment.approve', 'treatment.delete',
            'kri.view', 'kri.create', 'kri.edit', 'kri.record_measurement', 'kri.delete',
            'appetite.view', 'appetite.manage', 'appetite.approve',
            'report.view', 'report.generate', 'report.export',
            'entity.view', 'entity.create', 'entity.edit', 'entity.delete',
            'rcsa.view', 'rcsa.submit',
            'rcsa_universe.view', 'rcsa_universe.create', 'rcsa_universe.update',
            'rcsa_universe.delete', 'rcsa_universe.publish', 'rcsa_universe.import',
            'rcsa_cycle.view', 'rcsa_cycle.manage', 'rcsa_cycle.open', 'rcsa_cycle.close',
            'rcsa_assessment.view', 'rcsa_assessment.complete', 'rcsa_assessment.submit',
            'rcsa_assessment.review', 'rcsa_assessment.validate', 'rcsa_assessment.return',
            'rcsa_actionplan.view', 'rcsa_actionplan.update', 'rcsa_actionplan.close', 'rcsa_actionplan.verify',
            'rcsa_export.bulk', 'rcsa_audit.view',
            'rcsa_scope.all_units', 'rcsa_scope.assign',
            'analysis.view',
            'ai.view', 'ai.use',
            'control_test.view', 'control_test.create', 'control_test.edit', 'control_test.execute', 'control_test.review',
            'campaign.view', 'campaign.create', 'campaign.manage', 'campaign.respond', 'campaign.review',
            'questionnaire.view', 'questionnaire.create', 'questionnaire.edit', 'questionnaire.publish',
            'workflow.view', 'workflow.manage', 'workflow.act',
            'regulatory.view', 'regulatory.manage',
            'import.view', 'import.create', 'import.process',
            'kri.acknowledge_breach',
            'period.view', 'period.close',
            'threshold.view', 'threshold.manage', 'threshold.rebaseline_approve',
            'measure.view', 'measure.manage', 'measure.record',
            'fx_rate.view', 'fx_rate.manage',
            'approval.view', 'approval.act',
            'dashboard.manage',
        ];

        return [
            // Every permission in the catalog, resolved at read time so the
            // grant cannot drift behind it.
            'super-admin' => ['*'],

            'risk-manager' => $riskManager,

            'risk-owner' => [
                'risk.view', 'risk.edit',
                'assessment.create',
                'treatment.view', 'treatment.create',
                'rcsa.view', 'rcsa.submit',
                'rcsa_universe.view', 'rcsa_universe.create', 'rcsa_universe.update',
                'rcsa_cycle.view',
                'rcsa_assessment.view', 'rcsa_assessment.complete', 'rcsa_assessment.submit',
                // The BU head is a risk owner in this product's role map, and
                // the approval step is theirs. Review, validate and return are
                // NOT here: a unit reviewing its own assessment is not a second
                // line, and the whole of §9 rests on that separation.
                'rcsa_assessment.approve',
                'rcsa_actionplan.view', 'rcsa_actionplan.update',
                'analysis.view',
                'campaign.view', 'campaign.respond',
                'control_test.view',
                'approval.view',
            ],

            'risk-analyst' => [
                'risk.view',
                'assessment.view', 'assessment.create',
                'kri.view', 'kri.record_measurement',
                'report.view', 'report.generate',
                'rcsa.view',
                'rcsa_universe.view',
                'rcsa_cycle.view', 'rcsa_assessment.view',
                // The ORM Analyst of §11's role list: challenges every line,
                // decides nothing. Validate and return belong to the Head of
                // ORM, who is `risk-manager` here.
                'rcsa_assessment.review',
                'rcsa_actionplan.view',
                // The analyst builds the Board pack, so they export. They do
                // NOT get `rcsa_audit.view`: seeing who else downloaded what is
                // an administrator's control, not a reporting one.
                'rcsa_export.bulk',
                // The ORM Analyst reviews every unit's assessment, so they see
                // every unit. They cannot ASSIGN — deciding who sees what is
                // the administrator's, not the reviewer's.
                'rcsa_scope.all_units',
                'analysis.view',
                'ai.view',
                'control_test.view',
                'campaign.view', 'campaign.respond',
                'kri.acknowledge_breach',
                'period.view',
                'threshold.view',
                'measure.view', 'measure.record',
                'fx_rate.view',
                'regulatory.view',
            ],

            'chief-risk-officer' => [
                ...$riskManager,
                'loss_event.view', 'loss_event.create', 'loss_event.edit', 'loss_event.approve', 'loss_event.cbn_notify', 'loss_event.delete',
                'issue.view', 'issue.create', 'issue.edit', 'issue.escalate', 'issue.close', 'issue.delete',
                'quantification.view', 'quantification.create', 'quantification.run_simulation', 'quantification.approve_icaap',
                'regulatory.file',
            ],

            'compliance-officer' => [
                'risk.view',
                'loss_event.view',
                'issue.view', 'issue.create',
                'report.view',
                'analysis.view',
                'rcsa.view',
                'rcsa_universe.view',
                'rcsa_cycle.view', 'rcsa_assessment.view',
                'rcsa_actionplan.view',
                'rcsa_export.bulk',
                // §11's "Internal Audit (read-only, full estate)".
                'rcsa_scope.all_units',
                'control_test.view', 'control_test.review',
                'regulatory.view', 'regulatory.manage', 'regulatory.file',
                'period.view',
                'threshold.view',
                'measure.view',
                'fx_rate.view',
                'approval.view',
            ],

            'board-member' => [
                'risk.view',
                'report.view',
                'period.view',
                'threshold.view',
                'measure.view',
                'fx_rate.view',
                'analysis.view',
            ],

            'loss-event-manager' => [
                'loss_event.view', 'loss_event.create', 'loss_event.edit', 'loss_event.approve', 'loss_event.cbn_notify', 'loss_event.delete',
                'issue.view', 'issue.create',
                'analysis.view',
                'approval.view', 'approval.act',
            ],

            'issue-manager' => [
                'issue.view', 'issue.create', 'issue.edit', 'issue.escalate', 'issue.close', 'issue.delete',
                'loss_event.view',
                'analysis.view',
                'approval.view', 'approval.act',
            ],
        ];
    }
}

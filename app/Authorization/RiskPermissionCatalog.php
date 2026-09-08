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

            'Third-party risk (TPRM)' => [
                'tprm.view' => 'See the third-party register, engagements and their risk scores.',
                'tprm.create' => 'Register a third party and raise an engagement intake.',
                'tprm.edit' => 'Change a third party or an engagement that already exists.',
                'tprm.delete' => 'Remove a third party or an engagement from the register.',

                // Approving an intake is what admits a vendor to the estate.
                // It is separate from `edit` because the person who prepares
                // an intake must not be the person who approves it.
                'tprm.intake.approve' => 'Approve or reject a third-party intake request.',

                // Tier drives assessment cadence, clause set, exit-plan
                // requirement and board reportability. Overriding it is
                // deciding a vendor needs less scrutiny than the model says.
                'tprm.tier.override' => 'Override a computed risk tier, which reduces the scrutiny a vendor receives.',

                // Editing the ruleset redefines what Critical MEANS for every
                // vendor at once — the TPRM equivalent of `admin.scoring`.
                'tprm.ruleset.manage' => 'Edit the tiering factors, weights and knockout rules that decide every vendor\'s tier.',

                'tprm.assessment.view' => 'See third-party assessments and their responses.',
                'tprm.assessment.issue' => 'Issue an assessment to a vendor.',
                'tprm.assessment.review' => 'Review a submitted assessment and accept, reject or query each answer.',
                'tprm.assessment.validate' => 'Validate a reviewed assessment, fixing the assurance score it carries.',
                'tprm.questionnaire.manage' => 'Author and publish questionnaire templates and their control mappings.',

                'tprm.evidence.view' => 'See third-party evidence and its extractions.',
                'tprm.evidence.upload' => 'Upload third-party evidence.',
                'tprm.evidence.confirm' => 'Confirm a machine-read extraction, which applies it to answers, findings and obligations.',

                'tprm.contract.view' => 'See third-party contracts, clauses and obligations.',
                'tprm.contract.manage' => 'Record and amend contracts and their clause analysis.',

                // Waiving a blocking clause admits a vendor the regulator's
                // required terms do not cover. It is the single most
                // consequential grant in the module and belongs with the risk
                // function, not with whoever manages the contract.
                'tprm.waiver.approve' => 'Waive a blocking contract clause or another control gate, admitting a vendor a required term does not cover.',

                'tprm.finding.view' => 'See third-party findings and their remediation.',
                'tprm.finding.manage' => 'Raise, assign and progress third-party findings.',
                'tprm.finding.accept_risk' => 'Accept a third-party risk rather than remediating it.',

                'tprm.screening.view' => 'See sanctions, PEP and adverse-media screening results.',
                'tprm.screening.decide' => 'Decide a screening match, which can suspend every engagement with a vendor.',

                'tprm.monitoring.view' => 'See the monitoring signal stream and alerts.',
                'tprm.monitoring.manage' => 'Configure monitoring sources and alert rules.',

                'tprm.incident.view' => 'See third-party incidents and their regulatory clocks.',
                'tprm.incident.manage' => 'Record and progress a third-party incident.',

                // Nothing in this module submits to a regulator automatically.
                // A named officer holding this permission approves each draft
                // before it is marked submitted.
                'tprm.incident.notify' => 'Approve a regulatory notification draft and record it as submitted. Nothing is ever submitted automatically.',

                'tprm.portal.manage' => 'Invite, suspend and remove vendor portal users.',
                'tprm.graph.view' => 'See the sub-processor graph, concentration analysis and single points of failure.',
                'tprm.graph.manage' => 'Record and confirm sub-processor relationships, and run the concentration analysis.',

                /*
                 * Access is split from the rest of the engagement because the
                 * people who close connections are the network and identity
                 * teams, not the vendor managers — and because `access.manage`
                 * is the permission that can clear the one gate stopping an
                 * engagement being closed with a live production login on it.
                 */
                'tprm.access.view' => 'See third-party connections, access grants and the access reconciliation report.',
                'tprm.access.manage' => 'Record connections and access grants, and close or revoke them with evidence.',

                'tprm.report.view' => 'See TPRM reports, registers and regulatory returns.',
                'tprm.report.export' => 'Export the CBN, DORA, NDPA and PCI registers and returns.',
                'tprm.admin' => 'Administer the TPRM programme: tier policies, clause library, document types and the category taxonomy.',
            ],

            'Business continuity (BCMS)' => [
                'bcms.view' => 'See the BCMS module: the programme, the resilience calendar and the registers behind them.',
                'bcms.admin' => 'Administer the BCMS module itself: tenant settings, exercise types, readiness templates, the blackout calendar and the scenario library.',

                'bcms.programme.manage' => 'Create and edit the BC programme, its scope statement and its objectives.',
                'bcms.programme.approve' => 'Approve the BC programme and record the board attestation against it.',

                'bcms.process.view' => 'See the BCM process catalogue and its criticality tiers.',
                'bcms.process.manage' => 'Add and edit BCM processes, their dependencies and their criticality.',

                'bcms.bia.view' => 'See business impact assessments and their RTO, RPO and MTPD figures.',
                'bcms.bia.complete' => 'Complete a business impact assessment for a process you are assigned.',
                // Approving a BIA fixes the RTO every downstream strategy,
                // plan, DR tier and regulatory return is measured against. It
                // is separate from completing one because a unit that approves
                // its own impact analysis has not had one reviewed.
                'bcms.bia.approve' => 'Approve a submitted BIA, fixing the recovery objectives everything downstream is measured against.',
                'bcms.bia.campaign.manage' => 'Open, distribute and close BIA campaigns.',

                'bcms.strategy.view' => 'See continuity strategies and their cost, capability and gap analysis.',
                'bcms.strategy.manage' => 'Propose and edit continuity strategies.',
                'bcms.strategy.approve' => 'Select and approve the continuity strategy a process will rely on.',

                'bcms.plan.view' => 'See continuity, recovery, crisis and incident plans.',
                'bcms.plan.manage' => 'Author and edit plans and their sections.',
                'bcms.plan.approve' => 'Approve a plan, which makes it the version distributed for use.',
                'bcms.plan.activate' => 'Activate a plan in a live incident.',

                // ---- The exercise engine. -------------------------------
                'bcms.exercise.view' => 'See the resilience calendar, exercise definitions and their occurrences.',
                'bcms.exercise.manage' => 'Create and edit exercise definitions and generate their occurrences for the year.',
                // Approving the annual programme is the clause 8.5 record. The
                // programme as approved is what an examiner compares delivery
                // against, so approving it is not the same authority as editing
                // a definition inside it.
                'bcms.exercise.approve' => 'Approve the annual exercise programme, which is the ISO 22301 8.5 record delivery is measured against.',
                'bcms.exercise.schedule' => 'Move, defer or cancel a scheduled occurrence.',
                'bcms.exercise.facilitate' => 'Run an exercise: start it, release injects, log the timeline and close it out.',
                'bcms.exercise.evaluate' => 'Score objectives and attach evidence as an observer or evaluator.',
                // A blocking readiness task exists to stop an exercise going
                // ahead unprepared. Overriding it is deciding to run anyway,
                // and it is recorded in the AAR — so it needs an owner.
                'bcms.readiness.override' => 'Override a blocking readiness task and let an exercise proceed unprepared.',
                'bcms.aar.manage' => 'Draft and edit the after-action report for an exercise.',
                'bcms.aar.approve' => 'Approve and distribute an after-action report.',

                'bcms.finding.view' => 'See BCMS findings and their corrective actions.',
                'bcms.finding.manage' => 'Raise, assign and progress BCMS findings and corrective actions.',
                // Verification asks whether the action WORKED, which the person
                // who did it cannot answer about themselves (clause 10.1).
                'bcms.finding.verify' => 'Verify that a completed corrective action actually closed the finding.',
                'bcms.finding.accept_risk' => 'Accept a BCMS nonconformity rather than correcting it.',

                // ---- Call tree and EMNS. ---------------------------------
                'bcms.calltree.view' => 'See call trees, their tiers and their test history.',
                'bcms.calltree.manage' => 'Build and edit call trees, tiers and deputies.',
                'bcms.calltree.test' => 'Initiate a call tree test, which contacts every person on the tree.',

                'bcms.contact.view' => 'See the emergency contact roster.',
                // Contact records are personal data under the NDPA, held for
                // emergency use only. Editing somebody else\'s emergency
                // contact details is a different authority from editing your own,
                // which every employee has through `bcms.myprofile.manage`.
                'bcms.contact.manage' => 'Edit other people\'s emergency contact records, which are personal data held under the NDPA.',
                'bcms.contact.export' => 'Export the contact roster, including personal phone numbers — a bulk personal-data export.',
                'bcms.myprofile.manage' => 'Maintain your own emergency profile, channels and consent.',

                'bcms.alert.view' => 'See alerts, their audiences and their delivery audit trail.',
                'bcms.alert.compose' => 'Compose an alert and send it for approval.',
                // Dispatching is the act that puts a message on ten thousand
                // handsets. It is separate from composing one, and separate
                // again from the life-safety grant below.
                'bcms.alert.dispatch' => 'Dispatch an alert to its resolved audience.',
                'bcms.alert.approve' => 'Act as the second authoriser on a dual-approval alert.',
                // A life-safety dispatch bypasses quiet hours, throttling and
                // the routine queue, and a live (non-simulated) send from an
                // exercise context is a decision with real consequences.
                'bcms.alert.life_safety' => 'Dispatch life-safety traffic, which bypasses quiet hours and throttling, and authorise a live send from an exercise context.',
                'bcms.alert.template.manage' => 'Author alert templates and their per-channel and per-language renderings.',

                // ---- Incident, crisis and IT DR. -------------------------
                'bcms.incident.view' => 'See incidents, their decision log and their regulatory clocks.',
                'bcms.incident.declare' => 'Declare an incident and set its activation level.',
                'bcms.incident.manage' => 'Run an incident: log decisions, assign tasks and close it out.',
                'bcms.incident.notify' => 'Record a regulatory notification of an incident as submitted. Nothing is ever submitted automatically.',

                'bcms.dr.view' => 'See the IT DR register, recovery tiers and test history.',
                'bcms.dr.manage' => 'Maintain DR systems, their targets and their runbook links.',
                'bcms.dr.test.record' => 'Record a DR test result and its actual RTO and RPO.',

                // ---- Training, identity sync and reporting. ---------------
                'bcms.training.view' => 'See BC training curricula and competency records.',
                'bcms.training.manage' => 'Maintain curricula and record training and competency outcomes.',

                // Identity sync reads a corporate directory and rewrites the
                // roster every alert resolves against. It is an administrator\'s
                // authority, and the read-only rule is enforced in the connector.
                'bcms.identity.manage' => 'Configure and run the AD, Entra and SCIM contact sync. Read-only against the directory; nothing is ever written back.',

                'bcms.report.view' => 'See BCMS reports, the maturity heatmap and the board pack.',
                'bcms.report.export' => 'Export the ISO 22301, CBN and board evidence packs.',
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

            // TPRM: the risk function runs the programme day to day. Two
            // grants are deliberately NOT here — `tprm.waiver.approve` and
            // `tprm.finding.accept_risk` — because both are decisions to carry
            // a risk rather than to manage one, and they sit with the CRO.
            // `tprm.tier.override` is likewise withheld: overriding a computed
            // tier downwards is the same kind of decision.
            'tprm.view', 'tprm.create', 'tprm.edit', 'tprm.delete',
            'tprm.intake.approve',
            'tprm.assessment.view', 'tprm.assessment.issue', 'tprm.assessment.review', 'tprm.assessment.validate',
            'tprm.questionnaire.manage',
            'tprm.evidence.view', 'tprm.evidence.upload', 'tprm.evidence.confirm',
            'tprm.contract.view', 'tprm.contract.manage',
            'tprm.finding.view', 'tprm.finding.manage',
            'tprm.screening.view',
            'tprm.monitoring.view', 'tprm.monitoring.manage',
            'tprm.incident.view', 'tprm.incident.manage',
            'tprm.portal.manage',
            'tprm.report.view', 'tprm.report.export',
            'tprm.admin',

            // BCMS: the risk function runs the continuity programme day to
            // day — the BC Coordinator of Blueprint §13. Six grants are
            // deliberately NOT here and sit with the CRO below, because each
            // is a decision to proceed on terms the system says are
            // insufficient: overriding a blocking readiness task, accepting a
            // nonconformity, dispatching life-safety traffic, approving the
            // annual programme, approving the programme itself, and recording
            // a regulatory notification.
            'bcms.view', 'bcms.admin',
            'bcms.programme.manage',
            'bcms.process.view', 'bcms.process.manage',
            'bcms.bia.view', 'bcms.bia.complete', 'bcms.bia.approve', 'bcms.bia.campaign.manage',
            'bcms.strategy.view', 'bcms.strategy.manage', 'bcms.strategy.approve',
            'bcms.plan.view', 'bcms.plan.manage', 'bcms.plan.approve',
            'bcms.exercise.view', 'bcms.exercise.manage', 'bcms.exercise.schedule',
            'bcms.exercise.facilitate', 'bcms.exercise.evaluate',
            'bcms.aar.manage', 'bcms.aar.approve',
            'bcms.finding.view', 'bcms.finding.manage', 'bcms.finding.verify',
            'bcms.calltree.view', 'bcms.calltree.manage', 'bcms.calltree.test',
            'bcms.contact.view', 'bcms.contact.manage', 'bcms.myprofile.manage',
            'bcms.alert.view', 'bcms.alert.compose', 'bcms.alert.dispatch',
            'bcms.alert.template.manage',
            'bcms.incident.view', 'bcms.incident.manage',
            'bcms.dr.view', 'bcms.dr.manage', 'bcms.dr.test.record',
            'bcms.training.view', 'bcms.training.manage',
            'bcms.report.view', 'bcms.report.export',
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

                // Blueprint §13's Department BC Champion: owns the unit's BIA,
                // plan and call tree, closes its readiness tasks, sees its own
                // calendar. Approving its own BIA is NOT here, for the same
                // reason a unit does not review its own RCSA assessment.
                'bcms.view',
                'bcms.process.view',
                'bcms.bia.view', 'bcms.bia.complete',
                'bcms.strategy.view',
                'bcms.plan.view', 'bcms.plan.manage',
                'bcms.exercise.view', 'bcms.exercise.facilitate',
                'bcms.aar.manage',
                'bcms.finding.view', 'bcms.finding.manage',
                'bcms.calltree.view', 'bcms.calltree.manage',
                'bcms.contact.view',
                'bcms.alert.view',
                'bcms.incident.view',
                'bcms.training.view',
                'bcms.myprofile.manage',
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

                // Reads the continuity estate to build the board pack and
                // evaluates exercises as an observer. Decides nothing.
                'bcms.view',
                'bcms.process.view', 'bcms.bia.view', 'bcms.strategy.view', 'bcms.plan.view',
                'bcms.exercise.view', 'bcms.exercise.evaluate',
                'bcms.finding.view',
                'bcms.calltree.view', 'bcms.contact.view',
                'bcms.alert.view', 'bcms.incident.view', 'bcms.dr.view',
                'bcms.training.view', 'bcms.myprofile.manage',
                'bcms.report.view',
            ],

            'chief-risk-officer' => [
                ...$riskManager,
                'loss_event.view', 'loss_event.create', 'loss_event.edit', 'loss_event.approve', 'loss_event.cbn_notify', 'loss_event.delete',
                'issue.view', 'issue.create', 'issue.edit', 'issue.escalate', 'issue.close', 'issue.delete',
                'quantification.view', 'quantification.create', 'quantification.run_simulation', 'quantification.approve_icaap',
                'regulatory.file',

                // The four TPRM grants that are decisions to CARRY a risk
                // rather than to manage one. Each one lets a vendor into the
                // estate on terms the model says are insufficient, so each
                // needs an owner senior enough to answer for it.
                'tprm.tier.override',
                'tprm.waiver.approve',
                'tprm.finding.accept_risk',
                'tprm.screening.decide',
                'tprm.ruleset.manage',
                'tprm.incident.notify',

                // The BCMS grants that are decisions to proceed on terms the
                // system says are insufficient, plus the two approvals that
                // create the ISO 22301 records an examiner compares delivery
                // against.
                'bcms.programme.approve',
                'bcms.exercise.approve',
                'bcms.readiness.override',
                'bcms.finding.accept_risk',
                'bcms.plan.activate',
                'bcms.incident.declare', 'bcms.incident.notify',
                'bcms.alert.approve', 'bcms.alert.life_safety',
                'bcms.contact.export',
                'bcms.identity.manage',
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

                // Compliance reads the third-party estate and decides
                // screening matches — sanctions and PEP resolution is an AML
                // function, not a vendor-management one — but does not run the
                // programme.
                'tprm.view',
                'tprm.assessment.view',
                'tprm.evidence.view',
                'tprm.contract.view',
                'tprm.finding.view',
                'tprm.screening.view', 'tprm.screening.decide',
                'tprm.incident.view',
                'tprm.report.view', 'tprm.report.export',

                // Compliance reads the whole continuity estate and raises
                // findings against it; it runs no exercise and dispatches no
                // alert. `bcms.contact.export` is withheld deliberately — a
                // bulk export of staff mobile numbers is an NDPA event, not a
                // reporting one.
                'bcms.view',
                'bcms.programme.manage',
                'bcms.process.view', 'bcms.bia.view', 'bcms.strategy.view', 'bcms.plan.view',
                'bcms.exercise.view', 'bcms.exercise.evaluate',
                'bcms.finding.view', 'bcms.finding.manage', 'bcms.finding.verify',
                'bcms.calltree.view', 'bcms.contact.view',
                'bcms.alert.view', 'bcms.incident.view', 'bcms.dr.view',
                'bcms.training.view',
                'bcms.myprofile.manage',
                'bcms.report.view', 'bcms.report.export',
                'period.view',
                'threshold.view',
                'measure.view',
                'fx_rate.view',
                'approval.view',
            ],

            'board-member' => [
                'risk.view',
                'report.view',
                // Read-only. TRD §6.16 makes the third-party programme board
                // reportable at the top two tiers, so the board needs the
                // register and the reports and nothing else.
                'tprm.view',
                'tprm.report.view',
                // Read-only, plus the attestation the CBN Corporate Governance
                // Guidelines make a board act: `bcms.programme.approve` is what
                // records it against the programme.
                'bcms.view', 'bcms.report.view', 'bcms.programme.approve',
                'bcms.myprofile.manage',
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

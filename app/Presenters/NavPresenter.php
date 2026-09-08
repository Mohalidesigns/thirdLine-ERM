<?php

namespace App\Presenters;

use App\Models\User;
use App\Support\Migration\Ported;
use Illuminate\Support\Facades\Route;

/**
 * The sidebar as data.
 *
 * ONE SOURCE. The React layout (resources/js/Layouts/AuthenticatedLayout.jsx)
 * renders whatever this class emits through the `navigation` shared prop, and
 * NavigationPermissionGateTest holds every entry to its route: the
 * `permission` declared here must equal the `permission:` middleware of the
 * named route, so a menu entry can never lead to a 403 and a route can never
 * be reachable from the menu without the guard the router applies.
 *
 * Transcribed from resources/views/layouts/partials/sidebar.blade.php, which
 * keeps rendering the Blade screens until Phase 6 retires it. Two things are
 * corrected in the transcription rather than copied:
 *
 *   - the Blade sections were not permission-gated at all (a user without
 *     kri.view still saw "KRI Monitoring" and got a 403 on click);
 *   - the Risk Intelligence section was appended to `$sections`, a variable
 *     the Blade never reads, so it never rendered even with the flag on.
 *
 * Ordered following the ISO 31000 / COSO ERM lifecycle, as the Blade is:
 * Context → Identify → Assess → Control → Treat → Monitor → Incidents →
 * Issues → Analyze → Quantify → Report → Intelligence.
 */
class NavPresenter
{
    /**
     * Entries that sit above the sections: the four navigation surfaces.
     *
     * @return list<array{key: string, label: string, icon: string, route: string, permission: string}>
     */
    public static function primary(): array
    {
        return [
            ['key' => 'dashboard', 'label' => 'Command Centre', 'icon' => 'home', 'route' => 'risk.dashboard', 'permission' => 'dashboard.view'],
            ['key' => 'my', 'label' => 'My Responsibilities', 'icon' => 'checklist', 'route' => 'my.index', 'permission' => 'my.view'],
            ['key' => 'hq', 'label' => 'Business HQ', 'icon' => 'corporate_fare', 'route' => 'hq.index', 'permission' => 'hq.view'],
            ['key' => 'dashboards', 'label' => 'Dashboards', 'icon' => 'dashboard', 'route' => 'risk.dashboards.index', 'permission' => 'dashboard.manage'],
        ];
    }

    /**
     * The 22 module sections.
     *
     * `prefixes` are URL path prefixes used only for "which section is open"
     * highlighting; `feature` names a config('features.*') flag whose routes
     * 404 while the flag is off, so the section is withheld rather than linked.
     *
     * @return list<array<string, mixed>>
     */
    public static function sections(): array
    {
        return [
            // ── Phase 1: Context & Governance ──────────────────────────
            [
                'key' => 'scoping', 'label' => 'Scoping', 'icon' => 'account_tree', 'prefixes' => ['/risk/scoping'],
                'items' => [
                    ['label' => 'Entity Dashboard', 'route' => 'risk.scoping.dashboard', 'permission' => 'entity.view'],
                    ['label' => 'Entity Register', 'route' => 'risk.scoping.index', 'permission' => 'entity.view'],
                    ['label' => 'Create Entity', 'route' => 'risk.scoping.create', 'permission' => 'entity.create'],
                ],
            ],

            // ── Phase 2: Risk Identification & Assessment ──────────────
            [
                'key' => 'risk_register', 'label' => 'Risk Register', 'icon' => 'assessment', 'prefixes' => ['/risk/register'],
                'items' => [
                    ['label' => 'Risk Register', 'route' => 'risk.register.index', 'permission' => 'risk.view'],
                    ['label' => 'Create New Risk', 'route' => 'risk.register.create', 'permission' => 'risk.create'],
                ],
            ],
            [
                'key' => 'rcsa', 'label' => 'RCSA', 'icon' => 'fact_check', 'prefixes' => ['/risk/rcsa'],
                'items' => [
                    ['label' => 'Dashboard', 'route' => 'risk.rcsa.dashboard', 'permission' => 'rcsa.view'],
                    ['label' => 'Worksheet', 'route' => 'risk.rcsa.worksheet', 'permission' => 'rcsa.view'],
                    ['label' => 'Risk Matrix', 'route' => 'risk.rcsa.matrix', 'permission' => 'rcsa.view'],
                    // RCSA v2. Sits beside the module it will replace for the
                    // duration of the parallel run; the flag is what keeps it
                    // out of every install that has not opted in.
                    ['label' => 'Universe', 'route' => 'rcsa.universe.index', 'permission' => 'rcsa_universe.view', 'feature' => 'rcsa_v2'],
                    ['label' => 'Cycles', 'route' => 'rcsa.cycles.index', 'permission' => 'rcsa_cycle.view', 'feature' => 'rcsa_v2'],
                    ['label' => 'My Assessments', 'route' => 'rcsa.assessments.index', 'permission' => 'rcsa_assessment.view', 'feature' => 'rcsa_v2'],
                    ['label' => 'ORM Review', 'route' => 'rcsa.review.index', 'permission' => 'rcsa_assessment.review', 'feature' => 'rcsa_v2'],
                    ['label' => 'Action Plans', 'route' => 'rcsa.action-plans.index', 'permission' => 'rcsa_actionplan.view', 'feature' => 'rcsa_v2'],
                    ['label' => 'RCSA Dashboard', 'route' => 'rcsa.dashboard.index', 'permission' => 'rcsa_assessment.view', 'feature' => 'rcsa_v2'],
                    ['label' => 'Export', 'route' => 'rcsa.exports.index', 'permission' => 'rcsa_export.bulk', 'feature' => 'rcsa_v2'],
                ],
            ],
            // Third-Party Risk Management. Its own section rather than an
            // entry under the register: TRD §5.1 assesses risk at the
            // engagement, and folding vendor engagements into the risk
            // register's navigation would suggest they are the same objects.
            [
                'key' => 'tprm', 'label' => 'Third-Party Risk', 'icon' => 'handshake', 'prefixes' => ['/risk/tprm'],
                'items' => [
                    ['label' => 'Third-Party Register', 'route' => 'tprm.third-parties.index', 'permission' => 'tprm.view', 'feature' => 'tprm'],
                    ['label' => 'Engagements', 'route' => 'tprm.engagements.index', 'permission' => 'tprm.view', 'feature' => 'tprm'],
                    ['label' => 'Raise an Intake', 'route' => 'tprm.intake.create', 'permission' => 'tprm.create', 'feature' => 'tprm'],
                    ['label' => 'Intake Queue', 'route' => 'tprm.intake.index', 'permission' => 'tprm.view', 'feature' => 'tprm'],
                    ['label' => 'Assessments', 'route' => 'tprm.assessments.index', 'permission' => 'tprm.assessment.view', 'feature' => 'tprm'],
                    ['label' => 'Questionnaires', 'route' => 'tprm.templates.index', 'permission' => 'tprm.assessment.view', 'feature' => 'tprm'],
                    ['label' => 'Evidence Library', 'route' => 'tprm.documents.index', 'permission' => 'tprm.evidence.view', 'feature' => 'tprm'],
                    ['label' => 'Contracts', 'route' => 'tprm.contracts.index', 'permission' => 'tprm.contract.view', 'feature' => 'tprm'],
                    ['label' => 'Obligations', 'route' => 'tprm.obligations.index', 'permission' => 'tprm.contract.view', 'feature' => 'tprm'],
                    ['label' => 'Findings', 'route' => 'tprm.findings.index', 'permission' => 'tprm.finding.view', 'feature' => 'tprm'],
                    ['label' => 'Monitoring', 'route' => 'tprm.monitoring.index', 'permission' => 'tprm.monitoring.view', 'feature' => 'tprm'],
                    ['label' => 'Screening', 'route' => 'tprm.screening.index', 'permission' => 'tprm.screening.view', 'feature' => 'tprm'],
                    ['label' => 'Incidents', 'route' => 'tprm.incidents.index', 'permission' => 'tprm.incident.view', 'feature' => 'tprm'],
                    ['label' => 'Exit Readiness', 'route' => 'tprm.exit.index', 'permission' => 'tprm.exit.view', 'feature' => 'tprm'],
                    ['label' => 'Concentration', 'route' => 'tprm.concentration.index', 'permission' => 'tprm.graph.view', 'feature' => 'tprm'],
                    ['label' => 'Access Reconciliation', 'route' => 'tprm.access.index', 'permission' => 'tprm.access.view', 'feature' => 'tprm'],
                    ['label' => 'CBN ICT Register', 'route' => 'tprm.reports.cbn-register', 'permission' => 'tprm.report.view', 'feature' => 'tprm'],
                    ['label' => 'Register of Information', 'route' => 'tprm.reports.dora-register', 'permission' => 'tprm.report.view', 'feature' => 'tprm'],
                    ['label' => 'NDPA Audit Return', 'route' => 'tprm.reports.ndpa-car', 'permission' => 'tprm.report.view', 'feature' => 'tprm'],
                    ['label' => 'PCI DSS 12.8 Pack', 'route' => 'tprm.reports.pci-pack', 'permission' => 'tprm.report.view', 'feature' => 'tprm'],
                    ['label' => 'Board Packs', 'route' => 'tprm.reports.board-packs', 'permission' => 'tprm.report.view', 'feature' => 'tprm'],
                    ['label' => 'Override Register', 'route' => 'tprm.overrides.index', 'permission' => 'tprm.view', 'feature' => 'tprm'],
                    ['label' => 'Bulk Import', 'route' => 'tprm.imports.index', 'permission' => 'tprm.create', 'feature' => 'tprm'],
                    ['label' => 'Tiering Rulesets', 'route' => 'tprm.rulesets.index', 'permission' => 'tprm.ruleset.manage', 'feature' => 'tprm'],
                    ['label' => 'Clause Library', 'route' => 'tprm.clauses.index', 'permission' => 'tprm.contract.view', 'feature' => 'tprm'],
                    ['label' => 'Programme Settings', 'route' => 'tprm.settings.programme', 'permission' => 'tprm.admin', 'feature' => 'tprm'],
                ],
            ],

            [
                'key' => 'assessments', 'label' => 'Risk Assessments', 'icon' => 'rate_review', 'prefixes' => ['/risk/assessments'],
                'items' => [
                    ['label' => 'All Assessments', 'route' => 'risk.assessments.index', 'permission' => 'assessment.view'],
                    ['label' => 'New Assessment', 'route' => 'risk.assessments.create', 'permission' => 'assessment.create'],
                ],
            ],

            // ── Phase 3: Controls & Treatment ──────────────────────────
            [
                'key' => 'controls', 'label' => 'Control Library', 'icon' => 'verified_user', 'prefixes' => ['/risk/controls', '/risk/control-tests'],
                'items' => [
                    ['label' => 'All Controls', 'route' => 'risk.controls.index', 'permission' => 'control.view'],
                    ['label' => 'New Control', 'route' => 'risk.controls.create', 'permission' => 'control.create'],
                    ['label' => 'Testing Dashboard', 'route' => 'risk.control-tests.dashboard', 'permission' => 'control_test.view'],
                    ['label' => 'All Tests', 'route' => 'risk.control-tests.index', 'permission' => 'control_test.view'],
                    ['label' => 'Schedule Test', 'route' => 'risk.control-tests.create', 'permission' => 'control_test.create'],
                ],
            ],
            [
                'key' => 'treatment_plans', 'label' => 'Treatment Plans', 'icon' => 'healing', 'prefixes' => ['/risk/treatments'],
                'items' => [
                    ['label' => 'Dashboard', 'route' => 'risk.treatments.dashboard', 'permission' => 'treatment.view'],
                    ['label' => 'Active Plans', 'route' => 'risk.treatments.index', 'permission' => 'treatment.view'],
                    ['label' => 'New Plan', 'route' => 'risk.treatments.create', 'permission' => 'treatment.create'],
                    ['label' => 'Review', 'route' => 'risk.treatments.review', 'permission' => 'treatment.approve'],
                ],
            ],
            [
                'key' => 'risk_appetite', 'label' => 'Risk Appetite', 'icon' => 'tune', 'prefixes' => ['/risk/appetite'],
                'items' => [
                    ['label' => 'Appetite Statements', 'route' => 'risk.appetite.index', 'permission' => 'appetite.view'],
                ],
            ],
            [
                'key' => 'approvals', 'label' => 'Approvals', 'icon' => 'approval', 'prefixes' => ['/risk/approvals'],
                'items' => [
                    ['label' => 'Pending Approvals', 'route' => 'risk.approvals.dashboard', 'permission' => 'approval.view'],
                    ['label' => 'Approval History', 'route' => 'risk.approvals.history', 'permission' => 'approval.view'],
                ],
            ],

            // ── Phase 4: Monitoring & Events ───────────────────────────
            [
                'key' => 'kri_monitoring', 'label' => 'KRI Monitoring', 'icon' => 'speed', 'prefixes' => ['/risk/kri'],
                'items' => [
                    ['label' => 'Dashboard', 'route' => 'risk.kri.dashboard', 'permission' => 'kri.view'],
                    ['label' => 'KRI Library', 'route' => 'risk.kri.index', 'permission' => 'kri.view'],
                    ['label' => 'Thresholds', 'route' => 'risk.kri.thresholds', 'permission' => 'kri.view'],
                    ['label' => 'Breach Register', 'route' => 'risk.kri.breaches', 'permission' => 'kri.view'],
                ],
            ],
            [
                'key' => 'reporting_periods', 'label' => 'Reporting Periods', 'icon' => 'calendar_month', 'prefixes' => ['/risk/periods', '/risk/thresholds'],
                'items' => [
                    ['label' => 'Calendar & Close', 'route' => 'risk.periods.index', 'permission' => 'period.view'],
                    ['label' => 'Threshold Re-baselining', 'route' => 'risk.thresholds.rebaseline', 'permission' => 'threshold.view'],
                ],
            ],
            [
                'key' => 'loss_events', 'label' => 'Loss Events', 'icon' => 'report_problem', 'prefixes' => ['/risk/loss-events'],
                'items' => [
                    ['label' => 'Dashboard', 'route' => 'risk.loss-events.dashboard', 'permission' => 'loss_event.view'],
                    ['label' => 'Event Register', 'route' => 'risk.loss-events.index', 'permission' => 'loss_event.view'],
                    ['label' => 'New Event', 'route' => 'risk.loss-events.create', 'permission' => 'loss_event.create'],
                    ['label' => 'Near Misses', 'route' => 'risk.loss-events.near-misses', 'permission' => 'loss_event.view'],
                    ['label' => 'Approvals', 'route' => 'risk.loss-events.approvals', 'permission' => 'loss_event.approve'],
                    ['label' => 'Root Cause', 'route' => 'risk.loss-events.rca', 'permission' => 'loss_event.view'],
                    ['label' => 'Reports', 'route' => 'risk.loss-events.reports', 'permission' => 'loss_event.view'],
                ],
            ],
            [
                'key' => 'issues', 'label' => 'Issues & Findings', 'icon' => 'bug_report', 'prefixes' => ['/risk/issues'],
                'items' => [
                    ['label' => 'Dashboard', 'route' => 'risk.issues.dashboard', 'permission' => 'issue.view'],
                    ['label' => 'Issues Register', 'route' => 'risk.issues.index', 'permission' => 'issue.view'],
                    ['label' => 'New Issue', 'route' => 'risk.issues.create', 'permission' => 'issue.create'],
                    ['label' => 'Ageing Report', 'route' => 'risk.issues.ageing', 'permission' => 'issue.view'],
                    ['label' => 'Closure', 'route' => 'risk.issues.closure', 'permission' => 'issue.close'],
                ],
            ],
            [
                'key' => 'campaigns', 'label' => 'Campaigns', 'icon' => 'campaign', 'prefixes' => ['/risk/campaigns', '/risk/questionnaires', '/risk/question-library'],
                'items' => [
                    ['label' => 'Dashboard', 'route' => 'risk.campaigns.dashboard', 'permission' => 'campaign.view'],
                    ['label' => 'All Campaigns', 'route' => 'risk.campaigns.index', 'permission' => 'campaign.view'],
                    ['label' => 'New Campaign', 'route' => 'risk.campaigns.create', 'permission' => 'campaign.create'],
                    ['label' => 'Questionnaires', 'route' => 'risk.questionnaires.index', 'permission' => 'questionnaire.view'],
                    ['label' => 'Question Library', 'route' => 'risk.questionnaires.library', 'permission' => 'questionnaire.view'],
                ],
            ],
            [
                'key' => 'workflows', 'label' => 'Workflows', 'icon' => 'device_hub', 'prefixes' => ['/risk/workflows', '/risk/my-tasks'],
                'items' => [
                    // First, because it is the one entry here most people use
                    // daily: everything a person owes, from every module.
                    ['label' => 'My Tasks', 'route' => 'risk.my-tasks.index', 'permission' => 'task.view'],
                    ['label' => 'Dashboard', 'route' => 'risk.workflows.dashboard', 'permission' => 'workflow.view'],
                    ['label' => 'Definitions', 'route' => 'risk.workflows.definitions', 'permission' => 'workflow.view'],
                    ['label' => 'Designer', 'route' => 'risk.workflows.create-definition', 'permission' => 'workflow.manage'],
                ],
            ],

            // ── Phase 5: Analysis & Quantification ─────────────────────
            [
                'key' => 'analysis', 'label' => 'Risk Analysis', 'icon' => 'analytics', 'prefixes' => ['/risk/analysis'],
                'items' => [
                    ['label' => 'Heat Map', 'route' => 'risk.analysis.heatmap', 'permission' => 'analysis.view'],
                    ['label' => 'Bow-Tie', 'route' => 'risk.analysis.bowtie', 'permission' => 'analysis.view'],
                    ['label' => 'Trends', 'route' => 'risk.analysis.trends', 'permission' => 'analysis.view'],
                    // The route name stays `correlation` so bookmarks keep
                    // working; the page measures shared-control overlap.
                    ['label' => 'Shared Controls', 'route' => 'risk.analysis.correlation', 'permission' => 'analysis.view'],
                ],
            ],
            [
                'key' => 'quantification', 'label' => 'Risk Quantification', 'icon' => 'calculate', 'prefixes' => ['/risk/quantification'],
                'items' => [
                    ['label' => 'Dashboard', 'route' => 'risk.quantification.dashboard', 'permission' => 'quantification.view'],
                    ['label' => 'Scenarios', 'route' => 'risk.quantification.scenarios', 'permission' => 'quantification.view'],
                    ['label' => 'Simulate', 'route' => 'risk.quantification.simulate', 'permission' => 'quantification.view'],
                    ['label' => 'Results', 'route' => 'risk.quantification.results', 'permission' => 'quantification.view'],
                    ['label' => 'ICAAP', 'route' => 'risk.quantification.icaap', 'permission' => 'quantification.view'],
                    ['label' => 'Library', 'route' => 'risk.quantification.library', 'permission' => 'quantification.view'],
                    ['label' => 'Settings', 'route' => 'risk.quantification.settings', 'permission' => 'quantification.view'],
                    ['label' => 'Reports', 'route' => 'risk.quantification.reports', 'permission' => 'quantification.view'],
                ],
            ],

            // ── Regulatory Compliance ────────────────────────────────────
            [
                'key' => 'regulatory', 'label' => 'Regulatory', 'icon' => 'gavel', 'prefixes' => ['/risk/regulatory'],
                'items' => [
                    ['label' => 'Dashboard', 'route' => 'risk.regulatory.dashboard', 'permission' => 'regulatory.view'],
                    ['label' => 'Calendar', 'route' => 'risk.regulatory.calendar', 'permission' => 'regulatory.view'],
                    ['label' => 'Deadlines', 'route' => 'risk.regulatory.deadlines', 'permission' => 'regulatory.view'],
                    ['label' => 'Circulars', 'route' => 'risk.regulatory.circulars', 'permission' => 'regulatory.view'],
                    ['label' => 'Taxonomy', 'route' => 'risk.regulatory.taxonomy', 'permission' => 'regulatory.view'],
                ],
            ],

            // ── Data Import ─────────────────────────────────────────────
            [
                'key' => 'imports', 'label' => 'Data Import', 'icon' => 'upload_file', 'prefixes' => ['/risk/imports'],
                'items' => [
                    ['label' => 'Import History', 'route' => 'risk.imports.index', 'permission' => 'import.view'],
                    ['label' => 'New Import', 'route' => 'risk.imports.create', 'permission' => 'import.create'],
                ],
            ],

            // ── Document Repository ────────────────────────────────────
            [
                'key' => 'documents', 'label' => 'Documents', 'icon' => 'folder_open', 'prefixes' => ['/risk/documents'],
                'items' => [
                    ['label' => 'Repository', 'route' => 'risk.documents.index', 'permission' => 'document.view'],
                ],
            ],

            // ── Phase 6: Reporting & Intelligence ──────────────────────
            [
                'key' => 'reports', 'label' => 'Reports', 'icon' => 'summarize', 'prefixes' => ['/risk/reports'],
                'items' => [
                    ['label' => 'Executive', 'route' => 'risk.reports.executive', 'permission' => 'report.view'],
                    ['label' => 'Board', 'route' => 'risk.reports.board', 'permission' => 'report.view'],
                    ['label' => 'Regulatory', 'route' => 'risk.reports.regulatory', 'permission' => 'report.view'],
                    ['label' => 'Custom', 'route' => 'risk.reports.custom', 'permission' => 'report.view'],
                    ['label' => 'Library', 'route' => 'risk.reports.library', 'permission' => 'report.view'],
                ],
            ],
            [
                'key' => 'emerging', 'label' => 'Emerging Risk', 'icon' => 'radar', 'prefixes' => ['/risk/emerging-risks'],
                'items' => [
                    ['label' => 'Register', 'route' => 'risk.emerging.index', 'permission' => 'risk.view'],
                ],
            ],
            [
                'key' => 'ai_intelligence', 'label' => 'Risk Intelligence', 'icon' => 'psychology', 'prefixes' => ['/risk/ai'],
                'feature' => 'ai_intelligence',
                'items' => [
                    ['label' => 'Forecast', 'route' => 'risk.ai.predictive', 'permission' => 'ai.view', 'feature' => 'ai_intelligence'],
                    ['label' => 'Emerging Risk Radar', 'route' => 'risk.ai.radar', 'permission' => 'ai.view', 'feature' => 'ai_intelligence'],
                    ['label' => 'Regulatory Pulse', 'route' => 'risk.ai.regulatory-pulse', 'permission' => 'ai.view', 'feature' => 'ai_intelligence'],
                ],
            ],
        ];
    }

    /**
     * The Administration section: three groups of entries, each gated on the
     * permission its route enforces rather than on a role.
     *
     * @return list<array{label: string, items: list<array{label: string, route: string, permission: string}>}>
     */
    public static function adminGroups(): array
    {
        return [
            [
                'label' => 'Access',
                'items' => [
                    ['label' => 'User Management', 'route' => 'admin.users.index', 'permission' => 'admin.users'],
                    ['label' => 'SSO', 'route' => 'admin.settings.sso', 'permission' => 'admin.sso'],
                ],
            ],
            [
                'label' => 'Configuration',
                'items' => [
                    ['label' => 'Metadata Builder', 'route' => 'admin.builder', 'permission' => 'admin.metadata'],
                    ['label' => 'Scoring Profiles', 'route' => 'admin.builder.scoring-profiles', 'permission' => 'admin.scoring'],
                    ['label' => 'Configuration Bundles', 'route' => 'admin.configuration', 'permission' => 'admin.configuration'],
                    ['label' => 'Settings', 'route' => 'admin.settings', 'permission' => 'admin.settings'],
                ],
            ],
            [
                'label' => 'Integration',
                'items' => [
                    ['label' => 'Webhooks', 'route' => 'admin.webhooks.index', 'permission' => 'webhook.view'],
                    ['label' => 'API Tokens', 'route' => 'admin.api-tokens.index', 'permission' => 'api.tokens'],
                    ['label' => 'Connectors', 'route' => 'admin.connectors.index', 'permission' => 'connector.view'],
                    ['label' => 'Job Runs', 'route' => 'admin.jobs.index', 'permission' => 'job.view'],
                ],
            ],
            [
                'label' => 'Platform',
                'items' => [
                    ['label' => 'License', 'route' => 'admin.license', 'permission' => 'license.manage'],
                ],
            ],
        ];
    }

    /**
     * Every leaf entry in the definition, flattened — the surface
     * NavigationPermissionGateTest walks.
     *
     * @return list<array{label: string, route: string, permission: string, feature?: string, section: string}>
     */
    public static function allItems(): array
    {
        $items = [];

        foreach (self::primary() as $entry) {
            $items[] = $entry + ['section' => 'primary'];
        }

        foreach (self::sections() as $section) {
            foreach ($section['items'] as $item) {
                $items[] = $item + ['section' => $section['key']];
            }
        }

        foreach (self::adminGroups() as $group) {
            foreach ($group['items'] as $item) {
                $items[] = $item + ['section' => 'administration'];
            }
        }

        return $items;
    }

    /**
     * The tree for one user: entries the user may open, with URLs resolved.
     * Sections with nothing visible inside them are omitted, so the sidebar
     * shows a person only the modules they can actually use.
     *
     * @return array{primary: list<array<string, mixed>>, sections: list<array<string, mixed>>, admin: array<string, mixed>|null}
     */
    public function for(?User $user): array
    {
        if ($user === null) {
            return ['primary' => [], 'sections' => [], 'admin' => null];
        }

        $primary = array_values(array_filter(
            array_map(fn (array $item) => $this->resolve($item, $user), self::primary())
        ));

        $sections = [];

        foreach (self::sections() as $section) {
            if (isset($section['feature']) && ! $this->featureEnabled($section['feature'])) {
                continue;
            }

            $items = array_values(array_filter(
                array_map(fn (array $item) => $this->resolve($item, $user), $section['items'])
            ));

            if ($items === []) {
                continue;
            }

            $sections[] = [
                'key' => $section['key'],
                'label' => $section['label'],
                'icon' => $section['icon'],
                'prefixes' => $section['prefixes'],
                'items' => $items,
            ];
        }

        $groups = [];

        foreach (self::adminGroups() as $group) {
            $items = array_values(array_filter(
                array_map(fn (array $item) => $this->resolve($item, $user), $group['items'])
            ));

            if ($items !== []) {
                $groups[] = ['label' => $group['label'], 'items' => $items];
            }
        }

        $admin = $groups === [] ? null : [
            'key' => 'administration',
            'label' => 'Administration',
            'icon' => 'admin_panel_settings',
            'prefixes' => ['/admin'],
            'groups' => $groups,
        ];

        return ['primary' => $primary, 'sections' => $sections, 'admin' => $admin];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function resolve(array $item, User $user): ?array
    {
        // Belt and braces, as the Blade sidebar has for hq.index: permissions
        // are seeded independently of routes, so a tenant can hold a grant
        // for a surface that has been withdrawn, and route() would then throw
        // on every page of the site.
        if (! Route::has($item['route'])) {
            return null;
        }

        if (isset($item['feature']) && ! $this->featureEnabled($item['feature'])) {
            return null;
        }

        if (! $user->can($item['permission'])) {
            return null;
        }

        return [
            'key' => $item['key'] ?? $item['route'],
            'label' => $item['label'],
            'icon' => $item['icon'] ?? null,
            'route' => $item['route'],
            'url' => (string) parse_url(route($item['route']), PHP_URL_PATH),
            'permission' => $item['permission'],
            // True for every route since Phase 6.8 — there is no Blade page
            // left to need a full-page navigation. The layout still branches on
            // it; see App\Support\Migration\Ported for why both survive into
            // Phase 7 rather than being unpicked here.
            'inertia' => Ported::isRoute($item['route']),
        ];
    }

    private function featureEnabled(string $feature): bool
    {
        return filter_var(config("features.{$feature}", false), FILTER_VALIDATE_BOOLEAN);
    }
}

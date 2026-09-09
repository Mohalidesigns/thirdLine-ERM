<?php

namespace Database\Seeders\Tprm;

use App\Models\WidgetDefinition;
use Illuminate\Database\Seeder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The nine third-party widgets for the Dashboards builder — FR-RPT-08.
 *
 * SYSTEM ROWS, `organization_id` NULL, so any tenant can place them on any
 * dashboard. Idempotent on `code`, like every other widget seeder here.
 *
 * EACH ONE DECLARES A SOURCE WHOSE `node_column_kind` RESOLVES THROUGH
 * `tp_engagement_functions`, which is what makes them render per org node in
 * Business HQ. TPRM tables carry no `node_id` on purpose — an engagement
 * supports business functions across several units at once, and denormalising
 * one node onto it would have to pick one of them.
 *
 * NINE SHIP, AND THEY ARE NOT EXACTLY THE NINE THE PROMPT NAMES. Two
 * deviations, both stated rather than quietly absorbed:
 *
 *   1. CONCENTRATION GAUGE IS NOT HERE. The gauge resolver reads its bands
 *      from a MEASURE's own threshold row, so the gauge and the breach engine
 *      cannot disagree about where amber ends — and the concentration index
 *      becomes a measure only once a tenant adopts the TPRM KRIs (TPRM-06,
 *      via KriMeasureBridge). Seeding a system gauge with no measure would put
 *      a permanently empty widget in every tenant's library and call it
 *      delivered. A tenant binds the shipped `gauge` type to TPRM-06 in the
 *      dashboard builder, where it works. An overdue-findings tile ships in
 *      its place.
 *   2. "RESIDUAL HEATMAP" AND "FINDINGS AGEING" ARE A DONUT AND A PARETO.
 *      `heatmap` is the 5x5 likelihood-by-impact resolver and is specific to
 *      risks; `stacked_bar_bands` counts risks per org unit. Neither reads a
 *      TPRM source, and building two bespoke resolvers to match a word in the
 *      prompt would be a worse trade than shipping the distribution the data
 *      actually supports. The ageing BUCKETS live on the board pack and the
 *      findings report, which is where somebody reads them.
 */
class TprmWidgetSeeder extends Seeder
{
    public function run(): void
    {
        // SYSTEM ROWS MUST BE CREATED OUTSIDE A TENANT. `BelongsToOrganization`
        // stamps `organization_id` from the ambient TenantContext on create,
        // so seeding this while a tenant is resolved produces nine widgets
        // belonging to that one tenant and none in the shared library — and
        // the global scope then hides them from everybody else. The same trap
        // caught `QuestionnaireTemplate` in Phase 2.
        TenantContext::bypass(
            fn () => $this->write(),
            'Widget definitions are system-owned and belong to no tenant.',
        );
    }

    private function write(): void
    {
        foreach ($this->definitions() as [$code, $name, $type, $config]) {
            WidgetDefinition::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => null, 'code' => $code],
                array_merge([
                    'name' => $name,
                    'widget_type' => $type,
                    'context_binding' => 'inherit_subtree',
                    'period_binding' => 'selected',
                    'is_system' => true,
                    'min_w' => 4,
                    'min_h' => 3,
                ], $config),
            );
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
     */
    private function definitions(): array
    {
        return [
            ['wg-tprm-tier-distribution', 'Third-party tier distribution', 'donut', [
                'description' => 'Live engagements by effective tier. Engagements with no tier are counted '
                    .'separately — one nobody has tiered has not been found to be low risk.',
                'query' => [
                    'source' => 'tprm_engagements',
                    'group_by' => 'effective_tier',
                    'filters' => [['field' => 'status', 'op' => 'in', 'value' => self::LIVE_STATUSES]],
                ],
                'drilldown' => ['route' => 'tprm.engagements.index'],
                'min_w' => 4, 'min_h' => 4,
            ]],

            ['wg-tprm-residual-bands', 'Third-party residual risk', 'donut', [
                'description' => 'Live engagements by residual band. Unscored engagements appear as their own '
                    .'slice rather than being folded into Low.',
                'query' => [
                    'source' => 'tprm_engagements',
                    'group_by' => 'residual_band',
                    'filters' => [['field' => 'status', 'op' => 'in', 'value' => self::LIVE_STATUSES]],
                ],
                'drilldown' => ['route' => 'tprm.engagements.index'],
                'min_w' => 4, 'min_h' => 4,
            ]],

            ['wg-tprm-assessments-overdue', 'Assessments overdue', 'kpi_tile', [
                'description' => 'Live engagements whose next assessment date has passed. Engagements with no '
                    .'cadence set are not counted here — nothing is due, which is a different problem and is '
                    .'on the assessment status report.',
                'query' => [
                    'source' => 'tprm_engagements',
                    'filters' => [
                        ['field' => 'status', 'op' => 'in', 'value' => self::LIVE_STATUSES],
                        ['field' => 'next_assessment_due', 'op' => 'lt', 'value' => date('Y-m-d')],
                    ],
                ],
                'drilldown' => ['route' => 'tprm.engagements.index'],
                'min_w' => 3, 'min_h' => 2,
            ]],

            ['wg-tprm-findings-ageing', 'Third-party findings by severity', 'pareto', [
                'description' => 'Open findings by severity, worst first. Age is measured from identification, '
                    .'never from a target date that has been moved.',
                'query' => [
                    'source' => 'tprm_findings',
                    'group_by' => 'severity',
                    'filters' => [['field' => 'status', 'op' => 'not_in', 'value' => self::CLOSED_FINDINGS]],
                ],
                'drilldown' => ['route' => 'tprm.findings.index'],
                'min_w' => 6, 'min_h' => 4,
            ]],

            ['wg-tprm-findings-overdue', 'Findings past their remediation date', 'kpi_tile', [
                'description' => 'Open findings whose target date has passed.',
                'query' => [
                    'source' => 'tprm_findings',
                    'filters' => [
                        ['field' => 'status', 'op' => 'not_in', 'value' => self::CLOSED_FINDINGS],
                        ['field' => 'target_date', 'op' => 'lt', 'value' => date('Y-m-d')],
                    ],
                ],
                'drilldown' => ['route' => 'tprm.findings.index'],
                'min_w' => 3, 'min_h' => 2,
            ]],

            ['wg-tprm-evidence-expiring', 'Assurance evidence expiring', 'kpi_tile', [
                // The limitation is in the description because it is a fact
                // about SOC 2 reports rather than about this query: one report
                // covers every engagement with that provider.
                'description' => 'Engagement-owned evidence expiring within the period. Evidence held against '
                    .'the PROVIDER rather than one engagement is not attributable to a single org node and is '
                    .'not counted here; the evidence expiry forecast report shows the whole population.',
                'period_binding' => 'range',
                'period_config' => ['type' => 'month', 'count' => 3],
                'query' => [
                    'source' => 'tprm_evidence',
                    'date_filter' => true,
                    'filters' => [
                        ['field' => 'owner_type', 'op' => 'eq', 'value' => 'engagement'],
                        ['field' => 'is_superseded', 'op' => 'eq', 'value' => false],
                    ],
                ],
                'drilldown' => ['route' => 'tprm.documents.index'],
                'min_w' => 3, 'min_h' => 2,
            ]],

            ['wg-tprm-exit-readiness', 'Engagements requiring an exit plan', 'kpi_tile', [
                'description' => 'Live engagements the tier policy requires an exit plan for. Whether each one '
                    .'HAS a tested plan is on the exit readiness screen, which is the number that matters.',
                'query' => [
                    'source' => 'tprm_engagements',
                    'filters' => [
                        ['field' => 'status', 'op' => 'in', 'value' => self::LIVE_STATUSES],
                        ['field' => 'exit_plan_required', 'op' => 'eq', 'value' => true],
                    ],
                ],
                'drilldown' => ['route' => 'tprm.exit.index'],
                'min_w' => 3, 'min_h' => 2,
            ]],

            ['wg-tprm-top-exposures', 'Top third-party exposures', 'register', [
                'description' => 'Live engagements by residual score, worst first.',
                'query' => [
                    'source' => 'tprm_engagements',
                    'columns' => ['reference', 'name', 'effective_tier', 'residual_score', 'residual_band'],
                    'sort' => ['field' => 'residual_score', 'direction' => 'desc'],
                    'limit' => 10,
                    'filters' => [['field' => 'status', 'op' => 'in', 'value' => self::LIVE_STATUSES]],
                ],
                'drilldown' => ['route' => 'tprm.engagements.index'],
                'min_w' => 6, 'min_h' => 4,
            ]],

            ['wg-tprm-clock-status', 'Incidents on a regulatory clock', 'register', [
                'description' => 'Third-party incidents reportable to the CBN or the NDPC, with their '
                    .'deadlines. An incident whose materiality could not be determined carries no deadline, '
                    .'and shows as such rather than as not reportable.',
                'query' => [
                    'source' => 'tprm_incidents',
                    'columns' => [
                        'reference', 'title', 'severity', 'reported_to_us_at',
                        'ndpc_deadline_at', 'ndpc_reported_at', 'cbn_deadline_at', 'cbn_reported_at',
                    ],
                    'sort' => ['field' => 'reported_to_us_at', 'direction' => 'desc'],
                    'limit' => 10,
                    'filters' => [['field' => 'status', 'op' => 'not_in', 'value' => ['closed']]],
                ],
                'drilldown' => ['route' => 'tprm.incidents.index'],
                'min_w' => 6, 'min_h' => 4,
            ]],
        ];
    }

    /**
     * The engagement statuses that count as live, matching
     * `EngagementStatus::isLive()`. Restated as values here because a widget
     * definition is JSON a tenant can edit, and JSON cannot call an enum.
     *
     * `TprmWidgetTest` asserts this list against the enum. It has to: the
     * first version of it listed ten statuses including `due_diligence` and
     * `onboarding`, which `isLive()` excludes — so every tier widget would
     * have counted engagements the register does not, and the dashboard and
     * the register would have disagreed with nothing to explain why.
     *
     * @var list<string>
     */
    public const LIVE_STATUSES = [
        'active', 'monitoring_exception', 'reassessment', 'exit_planning', 'transitioning',
    ];

    /** @var list<string> */
    public const CLOSED_FINDINGS = [
        'closed_remediated', 'closed_risk_accepted', 'closed_false_positive',
    ];
}

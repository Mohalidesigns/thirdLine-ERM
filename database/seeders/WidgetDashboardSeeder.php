<?php

namespace Database\Seeders;

use App\Models\Dashboard;
use App\Models\GraphObject;
use App\Models\Measure;
use App\Models\ObjectType;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\WidgetDefinition;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * WP-08 — the system widget library and the seeded Corporater dashboards.
 *
 * IDEMPOTENT (updateOrCreate on code), like the newer seeders: re-running
 * refreshes definitions without duplicating them.
 *
 * Two kinds of rows:
 *   – SYSTEM WIDGETS (organization_id NULL): placement-agnostic questions
 *     any tenant can put on any dashboard. No org-specific ids inside.
 *   – ORG ROWS for the demo tenant: widgets that must reference tenant data
 *     (category ids, measures), the two dashboards, and a handful of
 *     Opportunity objects so the opportunity heat map demonstrates ISO
 *     31000's other half with real rows.
 *
 * The five-band Corporater split (Deep Red … Green) lives in
 * visualisation.bands as score ranges over the 5×5 default profile —
 * configuration a tenant can edit, not code.
 */
class WidgetDashboardSeeder extends Seeder
{
    private const FIVE_BANDS = [
        ['code' => 'green', 'label' => 'Green', 'color' => '#22c55e', 'min' => 1, 'max' => 4],
        ['code' => 'amber', 'label' => 'Amber', 'color' => '#eab308', 'min' => 5, 'max' => 9],
        ['code' => 'orange', 'label' => 'Orange', 'color' => '#f97316', 'min' => 10, 'max' => 14],
        ['code' => 'red', 'label' => 'Red', 'color' => '#dc2626', 'min' => 15, 'max' => 19],
        ['code' => 'deep_red', 'label' => 'Deep Red', 'color' => '#7f1d1d', 'min' => 20, 'max' => 25],
    ];

    public function run(): void
    {
        $organization = Organization::query()->where('cbn_institution_code', 'NGN/COM/0001')->first();

        $this->systemWidgets();

        // WP-12. These used to live inside the demo-org block below, so an
        // install without the demo bank — which is every real install — ended
        // up with zero rows in `dashboards`. DashboardResolver then correctly
        // returned null for every node and Business HQ rendered "No dashboard
        // published for Enterprise" across the whole org tree. That, and not
        // any judgement about the surface, is why HQ was retired.
        //
        // They are seeded here, before TenantContext::set(), so
        // BelongsToOrganization's creating() hook leaves organization_id NULL
        // and they are system rows — same mechanism as systemWidgets() above.
        $this->systemDashboards();

        if ($organization === null) {
            $this->command?->warn('WidgetDashboardSeeder: demo organization not found; system library only.');

            return;
        }

        TenantContext::set($organization->id);

        try {
            $this->tenantWidgets($organization);
            $this->opportunityObjects($organization);
            $this->backfillControlEffectiveness();

            // The WP-03 pivot→edge migration ran at migrate time, BEFORE the
            // demo seeders wrote any pivots — so a seeded install has a graph
            // with no edges and the network widget has nothing to draw. The
            // migrator is idempotent (existence-checked per edge), so calling
            // it here converts whatever pivots the seeders created.
            app(\App\Support\Graph\PivotRelationshipMigrator::class)->run();

            $this->adoptOrphanRoots($organization);

            // The demo bank's OWN composition of erm-hq, which shadows the
            // system one of the same code (DashboardResolver::pick() orders
            // tenant rows first). It is worth keeping distinct rather than
            // folding into the system default: it carries the credit-risk tab,
            // which is built from tenant widgets that reference this bank's
            // own risk categories and measures and therefore cannot be system
            // rows at all. It is also the demonstration that a bank can
            // recompose the default without losing it.
            $this->ermDashboard($organization);
        } finally {
            TenantContext::clear();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  System widget library */
    /* ------------------------------------------------------------------ */

    private function systemWidgets(): void
    {
        $definitions = [
            // KPI tiles ----------------------------------------------------
            ['wg-risks-active', 'Active risks', 'kpi_tile', [
                'query' => ['source' => 'risks', 'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'active']]],
                'drilldown' => ['route' => 'risk.register.index'],
                'min_w' => 3, 'min_h' => 2,
            ]],
            ['wg-risks-critical', 'Critical risks', 'kpi_tile', [
                'query' => ['source' => 'risks', 'filters' => [
                    ['field' => 'status', 'op' => 'eq', 'value' => 'active'],
                    ['field' => 'residual_rating', 'op' => 'eq', 'value' => 'Critical'],
                ]],
                'drilldown' => ['route' => 'risk.register.index'],
                'min_w' => 3, 'min_h' => 2,
            ]],
            ['wg-issues-open', 'Open issues', 'kpi_tile', [
                'query' => ['source' => 'issues', 'filters' => [
                    ['field' => 'issue_status', 'op' => 'not_in', 'value' => ['closed', 'resolved']],
                ]],
                'drilldown' => ['route' => 'risk.issues.index'],
                'min_w' => 3, 'min_h' => 2,
            ]],
            ['wg-treatments-overdue', 'Overdue treatments', 'kpi_tile', [
                'query' => ['source' => 'treatment_plans', 'filters' => [
                    ['field' => 'status', 'op' => 'not_in', 'value' => ['completed', 'cancelled']],
                    ['field' => 'target_date', 'op' => 'lt', 'value' => date('Y-m-d')],
                ]],
                'drilldown' => ['route' => 'risk.treatments.index'],
                'min_w' => 3, 'min_h' => 2,
            ]],
            ['wg-losses-gross', 'Gross losses (window)', 'kpi_tile', [
                'period_binding' => 'range', 'period_config' => ['type' => 'month', 'count' => 12],
                'query' => ['source' => 'loss_events', 'date_filter' => true,
                    'aggregate' => ['fn' => 'sum', 'field' => 'gross_loss_amount_kobo']],
                'visualisation' => ['unit' => 'kobo'],
                'drilldown' => ['route' => 'risk.loss-events.index'],
                'min_w' => 3, 'min_h' => 2,
            ]],

            // The two heat maps -------------------------------------------
            ['wg-risk-heatmap', 'Risk heat map', 'heatmap', [
                'query' => ['source' => 'risks', 'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'active']]],
                'drilldown' => ['route' => 'risk.register.index'],
                'min_w' => 5, 'min_h' => 4,
            ]],
            ['wg-opportunity-heatmap', 'Opportunity heat map', 'opportunity_heatmap', [
                'min_w' => 5, 'min_h' => 4,
            ]],

            // Distribution charts -----------------------------------------
            ['wg-risks-by-unit', 'Risk profile by unit', 'stacked_bar_bands', [
                'query' => ['source' => 'risks', 'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'active']]],
                'visualisation' => ['bands' => self::FIVE_BANDS],
                'min_w' => 6, 'min_h' => 4,
            ]],
            ['wg-losses-pareto', 'Losses by Basel category', 'pareto', [
                'query' => ['source' => 'loss_events', 'group_by' => 'basel_l1_category',
                    'aggregate' => ['fn' => 'sum', 'field' => 'gross_loss_amount_kobo']],
                'drilldown' => ['route' => 'risk.loss-events.index'],
                'min_w' => 6, 'min_h' => 4,
            ]],
            ['wg-risks-grouped-bar', 'Inherent vs residual vs planned', 'grouped_bar_3', [
                'query' => ['source' => 'risks', 'group_by' => 'category_id',
                    'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'active']]],
                'min_w' => 6, 'min_h' => 4,
            ]],
            ['wg-risks-bubble', 'Exposure vs control effectiveness', 'bubble', [
                'query' => ['source' => 'risks', 'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'active']]],
                'min_w' => 6, 'min_h' => 4,
            ]],
            ['wg-risks-donut-rating', 'Risks by priority', 'donut', [
                'query' => ['source' => 'risks', 'group_by' => 'residual_rating',
                    'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'active']]],
                'min_w' => 4, 'min_h' => 3,
            ]],
            ['wg-risks-treemap-category', 'Risk mass by category', 'treemap', [
                'query' => ['source' => 'risks', 'group_by' => 'category_id',
                    'aggregate' => ['fn' => 'sum', 'field' => 'residual_score'],
                    'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'active']]],
                'min_w' => 5, 'min_h' => 4,
            ]],
            ['wg-objects-by-type', 'Objects by type', 'bar_by_type', [
                'min_w' => 4, 'min_h' => 3,
            ]],

            // Time series -------------------------------------------------
            ['wg-risks-stacked-area', 'Risk development over time', 'stacked_area', [
                'period_binding' => 'range', 'period_config' => ['type' => 'month', 'count' => 12],
                'query' => ['source' => 'risks', 'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'active']]],
                'min_w' => 6, 'min_h' => 4,
            ]],
            ['wg-risks-trend-quarters', 'Risk trend by quarter', 'trend_stacked_bar', [
                'query' => ['source' => 'risks', 'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'active']]],
                'min_w' => 6, 'min_h' => 4,
            ]],
            ['wg-treatments-cumulative', 'Treatment implementation %', 'cumulative_line', [
                'period_binding' => 'range', 'period_config' => ['type' => 'month', 'count' => 12],
                'query' => ['source' => 'treatment_plans'],
                'min_w' => 6, 'min_h' => 4,
            ]],

            // Tables ------------------------------------------------------
            ['wg-treatments-on-track', 'Treatment activities — on track', 'activity_table', [
                'query' => ['source' => 'treatment_plans'],
                'visualisation' => ['mode' => 'on_track'],
                'min_w' => 6, 'min_h' => 4,
            ]],
            ['wg-treatments-off-track', 'Treatment activities — off track', 'activity_table', [
                'query' => ['source' => 'treatment_plans'],
                'visualisation' => ['mode' => 'off_track'],
                'min_w' => 6, 'min_h' => 4,
            ]],
            ['wg-controls-table', 'Control measures', 'measure_table', [
                'query' => ['source' => 'controls'],
                'min_w' => 6, 'min_h' => 4,
            ]],
            ['wg-controls-not-implemented', 'Not implemented / findings', 'measure_table', [
                'query' => ['source' => 'controls'],
                'visualisation' => ['mode' => 'not_implemented'],
                'min_w' => 6, 'min_h' => 4,
            ]],
            ['wg-risk-register', 'Risk register', 'register', [
                'query' => ['source' => 'risks',
                    'columns' => ['risk_code', 'title', 'residual_rating', 'residual_score', 'status'],
                    'sort' => ['by' => 'residual_score', 'dir' => 'desc'],
                    'limit' => 10],
                'drilldown' => ['route' => 'risk.register.index', 'row_route' => 'risk.register.show',
                    'row_param' => 'risk', 'create_route' => 'risk.register.create'],
                'min_w' => 6, 'min_h' => 4,
            ]],
            ['wg-top-risks', 'Top risks', 'register', [
                'query' => ['source' => 'risks',
                    'columns' => ['risk_code', 'title', 'residual_rating', 'residual_score', 'control_effectiveness_pct'],
                    'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'active']],
                    'sort' => ['by' => 'residual_score', 'dir' => 'desc'],
                    'limit' => 10],
                'drilldown' => ['route' => 'risk.register.index', 'row_route' => 'risk.register.show', 'row_param' => 'risk'],
                'min_w' => 6, 'min_h' => 4,
            ]],

            // Quantification ----------------------------------------------
            ['wg-quant-tornado', 'Scenario loss distribution', 'tornado', [
                'min_w' => 6, 'min_h' => 4,
            ]],
            ['wg-quant-lec', 'Loss exceedance curve', 'lec_curve', [
                'min_w' => 6, 'min_h' => 4,
            ]],

            // Graph views -------------------------------------------------
            ['wg-node-network', 'Relationship network', 'network', [
                'context_binding' => 'inherit_node',
                'min_w' => 5, 'min_h' => 4,
            ]],
            ['wg-treatments-timeline', 'Treatment timeline', 'timeline', [
                'query' => ['source' => 'treatment_plans', 'limit' => 20],
                'min_w' => 6, 'min_h' => 4,
            ]],
        ];

        foreach ($definitions as [$code, $name, $type, $config]) {
            WidgetDefinition::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => null, 'code' => $code],
                array_merge([
                    'name' => $name,
                    'widget_type' => $type,
                    'context_binding' => 'inherit_subtree',
                    'period_binding' => 'selected',
                    'is_system' => true,
                    'min_w' => 3,
                    'min_h' => 3,
                ], $config),
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Demo-tenant widgets that reference tenant data */
    /* ------------------------------------------------------------------ */

    private function tenantWidgets(Organization $organization): void
    {
        // Credit-risk register: filtered to the tenant's CR-* categories.
        $creditCategoryIds = RiskCategory::query()
            ->where('code', 'like', 'CR%')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($creditCategoryIds !== []) {
            WidgetDefinition::updateOrCreate(
                ['organization_id' => $organization->id, 'code' => 'wg-credit-register'],
                [
                    'name' => 'Credit risk register',
                    'widget_type' => 'register',
                    'context_binding' => 'inherit_subtree',
                    'period_binding' => 'selected',
                    'query' => [
                        'source' => 'risks',
                        'columns' => ['risk_code', 'title', 'residual_rating', 'residual_score', 'status'],
                        'filters' => [['field' => 'category_id', 'op' => 'in', 'value' => $creditCategoryIds]],
                        'sort' => ['by' => 'residual_score', 'dir' => 'desc'],
                        'limit' => 10,
                    ],
                    'drilldown' => ['route' => 'risk.register.index', 'row_route' => 'risk.register.show', 'row_param' => 'risk'],
                    'min_w' => 6, 'min_h' => 4,
                ],
            );

            WidgetDefinition::updateOrCreate(
                ['organization_id' => $organization->id, 'code' => 'wg-credit-heatmap'],
                [
                    'name' => 'Credit risk heat map',
                    'widget_type' => 'heatmap',
                    'context_binding' => 'inherit_subtree',
                    'period_binding' => 'selected',
                    'query' => [
                        'source' => 'risks',
                        'filters' => [
                            ['field' => 'status', 'op' => 'eq', 'value' => 'active'],
                            ['field' => 'category_id', 'op' => 'in', 'value' => $creditCategoryIds],
                        ],
                    ],
                    'drilldown' => ['route' => 'risk.register.index'],
                    'min_w' => 5, 'min_h' => 4,
                ],
            );
        }

        // Residual-score gauge over the tenant's registered measure.
        $measure = Measure::query()->where('code', 'risk.residual_score')->first();

        if ($measure !== null) {
            WidgetDefinition::updateOrCreate(
                ['organization_id' => $organization->id, 'code' => 'wg-residual-gauge'],
                [
                    'name' => 'Average residual score',
                    'widget_type' => 'gauge',
                    'context_binding' => 'inherit_subtree',
                    'period_binding' => 'selected',
                    'measure_id' => $measure->id,
                    'min_w' => 3, 'min_h' => 3,
                ],
            );
        }
    }

    /**
     * A handful of opportunities so the inverse heat map shows real rows.
     * Fixed values — a seeded demo tells one deliberate story, not a random
     * one. WP-10 brings the typed model; these graph objects are exactly
     * what that migration will adopt.
     */
    private function opportunityObjects(Organization $organization): void
    {
        $type = ObjectType::withoutGlobalScopes()
            ->whereNull('organization_id')
            ->where('code', 'Opportunity')
            ->first();

        if ($type === null) {
            return;
        }

        // Hang opportunities off the ENTITY tree — the tree the demo risks
        // live in (risks resolve node_id through entity_id), so the group
        // HQ page rolls both maps up from the same subtree.
        $units = GraphObject::query()
            ->where('source_model_type', 'entity')
            ->whereIn('name', [
                'Retail Banking Division',
                'Corporate Banking Division',
                'Treasury & Investment Banking',
                'Operations & Technology',
            ])
            ->get()
            ->keyBy('name');

        $opportunities = [
            ['OPP-0001', 'Agency banking expansion into underserved LGAs', 'Retail Banking Division', 4, 5],
            ['OPP-0002', 'USSD micro-savings product for informal traders', 'Operations & Technology', 4, 4],
            ['OPP-0003', 'FX remittance corridor partnership (diaspora)', 'Treasury & Investment Banking', 3, 5],
            ['OPP-0004', 'SME supply-chain financing marketplace', 'Corporate Banking Division', 3, 3],
            ['OPP-0005', 'API banking revenue from fintech partnerships', 'Operations & Technology', 5, 3],
            ['OPP-0006', 'Naira-settled trade instruments for AfCFTA flows', 'Treasury & Investment Banking', 2, 4],
        ];

        foreach ($opportunities as [$code, $name, $unitCode, $likelihood, $benefit]) {
            $unit = $units->get($unitCode);

            $object = GraphObject::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'object_type_id' => $type->id, 'code' => $code],
                [
                    'name' => $name,
                    'node_id' => $unit?->id,
                    'lifecycle_state' => 'active',
                    'status' => 'active',
                ],
            );

            $object->setCustomAttributes(array_merge($object->customAttributes(), [
                'likelihood' => $likelihood,
                'benefit' => $benefit,
            ]));
            $object->saveQuietly();
        }
    }

    /**
     * The demo risks carry control mappings but nothing ever computed
     * control_effectiveness_pct from them, so the bubble chart's x-axis was
     * empty. Compute it with the SAME service production uses — a number
     * derived from real mappings, not invented. Deliberately NOT
     * recalculateForRisk(): that would also rewrite the hand-authored demo
     * residual scores, which tell their own story.
     */
    private function backfillControlEffectiveness(): void
    {
        $service = app(\App\Services\ControlEffectivenessService::class);

        \App\Models\Risk::query()
            ->whereNull('control_effectiveness_pct')
            ->whereHas('controls')
            ->each(function (\App\Models\Risk $risk) use ($service) {
                $risk->updateQuietly([
                    'control_effectiveness_pct' => $service->calculateForRisk($risk),
                ]);
            });
    }

    /**
     * The demo data grew two parallel trees: the entity hierarchy (which the
     * risks hang off) and the flat business-unit objects (which controls,
     * issues and treatments hang off). Both are true; they were just never
     * joined, so the enterprise root rolled up only half the organization.
     *
     * Adopt every orphan org-node root under the busiest root and rewrite
     * the materialised paths of the adopted subtrees. Idempotent: a node
     * with a parent is left alone. The full entity↔unit MERGE (one "Retail"
     * node instead of two) stays WP-03 merge-candidate work.
     */
    private function adoptOrphanRoots(Organization $organization): void
    {
        $roots = GraphObject::query()->nodes()->whereNull('parent_id')->get();

        if ($roots->count() <= 1) {
            return;
        }

        // The enterprise root is picked by TYPE first (level_hint 0 =
        // Enterprise, 1 = LegalEntity …), subtree weight second. Weight alone
        // once adopted the whole bank under the IT department, because IT
        // owned the most process nodes — org level beats popularity.
        $levelHints = ObjectType::withoutGlobalScopes()->pluck('level_hint', 'id');

        $enterprise = $roots
            ->sortBy([
                fn (GraphObject $a, GraphObject $b) => ($levelHints[$a->object_type_id] ?? 9) <=> ($levelHints[$b->object_type_id] ?? 9),
                fn (GraphObject $a, GraphObject $b) => GraphObject::query()->where('hierarchy_path', 'like', $b->pathOrFallback().'%')->toBase()->count()
                    <=> GraphObject::query()->where('hierarchy_path', 'like', $a->pathOrFallback().'%')->toBase()->count(),
            ])
            ->first();

        foreach ($roots as $orphan) {
            if ((int) $orphan->id === (int) $enterprise->id) {
                continue;
            }

            $oldPath = $orphan->pathOrFallback();
            $newPath = $enterprise->pathOrFallback().$orphan->id.'/';
            $depthShift = ((int) $enterprise->hierarchy_depth + 1) - (int) $orphan->hierarchy_depth;

            $orphan->updateQuietly([
                'parent_id' => $enterprise->id,
                'hierarchy_path' => $newPath,
                'hierarchy_depth' => (int) $orphan->hierarchy_depth + $depthShift,
            ]);

            // Rewrite every descendant's materialised path in one statement.
            GraphObject::query()
                ->where('hierarchy_path', 'like', $oldPath.'%')
                ->where('id', '!=', $orphan->id)
                ->toBase()
                ->orderBy('id')
                ->each(function (object $row) use ($oldPath, $newPath, $depthShift) {
                    GraphObject::query()->whereKey($row->id)->toBase()->update([
                        'hierarchy_path' => $newPath.substr($row->hierarchy_path, strlen($oldPath)),
                        'hierarchy_depth' => (int) $row->hierarchy_depth + $depthShift,
                    ]);
                });
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Dashboards */
    /* ------------------------------------------------------------------ */

    /**
     * WP-12 — the dashboards every tenant gets, composed from system widgets.
     *
     * This is the architecture bet made visible. There is ONE library of
     * widget definitions; a dashboard is a composition over it, and each
     * widget resolves against whatever org node the viewer is standing on
     * (context_binding = inherit_subtree). So the same `wg-risk-heatmap` row
     * draws the group heat map on the Enterprise node and one division's heat
     * map on a Division node, with no per-node configuration anywhere.
     *
     * The type-agnostic `erm-hq` is the floor: DashboardResolver falls back to
     * it for any object type without a composition of its own, so the "No
     * dashboard published" empty state is now reachable only if an
     * administrator unpublishes it deliberately.
     *
     * A tenant may recompose any of these under its own organization_id and
     * the same code; pick() prefers the tenant's row.
     */
    private function systemDashboards(): void
    {
        $w = $this->systemWidgetIds();

        // A dependency that has not been seeded is a reason to skip this
        // dashboard, never a reason to publish one with holes in it: a tab
        // whose layout references a widget_id that does not exist renders an
        // error panel to the customer.
        $need = function (array $codes) use ($w): bool {
            foreach ($codes as $code) {
                if (! isset($w[$code])) {
                    return false;
                }
            }

            return true;
        };

        $this->defaultDashboard($w, $need);
        $this->enterpriseDashboard($w, $need);
        $this->operationalDashboards($w, $need);
        $this->assessmentDashboard($w, $need);
    }

    /**
     * The floor: what a node renders when nothing is composed for its type.
     *
     * Deliberately modest. It answers the four questions every reader of any
     * node has — how much risk is here, how severe, what is untreated, what is
     * the shape of it — and nothing that only makes sense at one level of the
     * tree. Anything richer belongs on a type-bound dashboard, where it is
     * true of every node that reaches it.
     */
    private function defaultDashboard(array $w, callable $need): void
    {
        $required = [
            'wg-risks-active', 'wg-risks-critical', 'wg-issues-open', 'wg-treatments-overdue',
            'wg-risk-heatmap', 'wg-risks-donut-rating', 'wg-risk-register',
        ];

        if (! $need($required)) {
            return;
        }

        $this->publishSystemDashboard('erm-hq', 'Enterprise Risk Management', null, [
            ['code' => 'dashboard', 'label' => 'Dashboard', 'layout' => $this->grid([
                [$w['wg-risks-active'], 0, 0, 3, 2], [$w['wg-risks-critical'], 3, 0, 3, 2],
                [$w['wg-issues-open'], 6, 0, 3, 2], [$w['wg-treatments-overdue'], 9, 0, 3, 2],
                [$w['wg-risk-heatmap'], 0, 2, 7, 4], [$w['wg-risks-donut-rating'], 7, 2, 5, 4],
            ])],
            ['code' => 'risk-register', 'label' => 'Risk Register', 'layout' => $this->grid([
                [$w['wg-risk-register'], 0, 0, 12, 5],
            ])],
        ]);
    }

    /**
     * Enterprise — the group view. The only level where rolling the whole
     * tree up into one number is the right question, so this is where the
     * cross-unit comparisons and the capital work live.
     */
    private function enterpriseDashboard(array $w, callable $need): void
    {
        $type = $this->systemObjectType('Enterprise');

        $required = [
            'wg-risks-active', 'wg-risks-critical', 'wg-issues-open', 'wg-treatments-overdue',
            'wg-risk-heatmap', 'wg-opportunity-heatmap', 'wg-risks-by-unit', 'wg-risks-stacked-area',
            'wg-risks-grouped-bar', 'wg-risks-treemap-category', 'wg-objects-by-type', 'wg-node-network',
            'wg-top-risks', 'wg-risks-bubble', 'wg-losses-gross', 'wg-quant-tornado', 'wg-quant-lec',
            'wg-losses-pareto', 'wg-treatments-cumulative', 'wg-controls-table',
            'wg-controls-not-implemented', 'wg-treatments-on-track', 'wg-treatments-off-track',
            'wg-risk-register', 'wg-risks-trend-quarters', 'wg-treatments-timeline',
        ];

        if ($type === null || ! $need($required)) {
            return;
        }

        $this->publishSystemDashboard('enterprise-hq', 'Enterprise (Group)', $type->id, [
            ['code' => 'dashboard', 'label' => 'Dashboard', 'layout' => $this->grid([
                [$w['wg-risks-active'], 0, 0, 3, 2], [$w['wg-risks-critical'], 3, 0, 3, 2],
                [$w['wg-issues-open'], 6, 0, 3, 2], [$w['wg-treatments-overdue'], 9, 0, 3, 2],
                [$w['wg-risk-heatmap'], 0, 2, 5, 4], [$w['wg-risks-donut-rating'], 5, 2, 3, 4],
                [$w['wg-opportunity-heatmap'], 8, 2, 4, 4],
                [$w['wg-risks-by-unit'], 0, 6, 7, 4], [$w['wg-risks-stacked-area'], 7, 6, 5, 4],
            ])],
            ['code' => 'risk-categories', 'label' => 'Risk Category Dashboard', 'layout' => $this->grid([
                [$w['wg-risks-grouped-bar'], 0, 0, 7, 4], [$w['wg-risks-treemap-category'], 7, 0, 5, 4],
                [$w['wg-risks-donut-rating'], 0, 4, 4, 3], [$w['wg-objects-by-type'], 4, 4, 4, 3],
                [$w['wg-node-network'], 8, 4, 4, 3],
            ])],
            ['code' => 'top-risk', 'label' => 'Top Risk', 'layout' => $this->grid([
                [$w['wg-top-risks'], 0, 0, 7, 5], [$w['wg-risks-bubble'], 7, 0, 5, 5],
            ])],
            ['code' => 'financial-risk', 'label' => 'Financial Risk', 'layout' => $this->grid([
                [$w['wg-losses-gross'], 0, 0, 3, 2], [$w['wg-quant-tornado'], 0, 2, 6, 4],
                [$w['wg-quant-lec'], 6, 2, 6, 4], [$w['wg-losses-pareto'], 0, 6, 7, 4],
            ])],
            ['code' => 'risk-effectiveness', 'label' => 'Risk Effectiveness', 'layout' => $this->grid([
                [$w['wg-treatments-cumulative'], 0, 0, 6, 4], [$w['wg-controls-table'], 6, 0, 6, 4],
                [$w['wg-controls-not-implemented'], 0, 4, 6, 4],
                [$w['wg-treatments-on-track'], 6, 4, 6, 4],
                [$w['wg-treatments-off-track'], 0, 8, 6, 4],
            ])],
            ['code' => 'risk-register', 'label' => 'Risk Register', 'layout' => $this->grid([
                [$w['wg-risk-register'], 0, 0, 8, 5], [$w['wg-objects-by-type'], 8, 0, 4, 5],
            ])],
            ['code' => 'reports', 'label' => 'Reports', 'layout' => $this->grid([
                [$w['wg-risks-trend-quarters'], 0, 0, 6, 4], [$w['wg-treatments-timeline'], 6, 0, 6, 4],
                [$w['wg-losses-pareto'], 0, 4, 6, 4], [$w['wg-node-network'], 6, 4, 6, 4],
            ])],
        ]);
    }

    /**
     * Division, Business Unit and Department share one composition.
     *
     * A dashboard binds to exactly one object_type_id, so this is three rows;
     * the point is that it is one LAYOUT and one widget library behind them.
     * The operational view drops the capital and group-comparison tabs — a
     * department head has no ICAAP question — and leads with the work: what is
     * overdue, which controls are untested, what is on my register.
     */
    private function operationalDashboards(array $w, callable $need): void
    {
        $required = [
            'wg-risks-active', 'wg-risks-critical', 'wg-issues-open', 'wg-treatments-overdue',
            'wg-risk-heatmap', 'wg-risks-donut-rating', 'wg-risk-register', 'wg-top-risks',
            'wg-treatments-on-track', 'wg-treatments-off-track', 'wg-controls-table',
            'wg-controls-not-implemented', 'wg-treatments-timeline', 'wg-node-network',
        ];

        if (! $need($required)) {
            return;
        }

        $tabs = [
            ['code' => 'dashboard', 'label' => 'Dashboard', 'layout' => $this->grid([
                [$w['wg-risks-active'], 0, 0, 3, 2], [$w['wg-risks-critical'], 3, 0, 3, 2],
                [$w['wg-issues-open'], 6, 0, 3, 2], [$w['wg-treatments-overdue'], 9, 0, 3, 2],
                [$w['wg-risk-heatmap'], 0, 2, 7, 4], [$w['wg-risks-donut-rating'], 7, 2, 5, 4],
                [$w['wg-top-risks'], 0, 6, 12, 4],
            ])],
            ['code' => 'treatment', 'label' => 'Treatment', 'layout' => $this->grid([
                [$w['wg-treatments-on-track'], 0, 0, 6, 4], [$w['wg-treatments-off-track'], 6, 0, 6, 4],
                [$w['wg-treatments-timeline'], 0, 4, 12, 4],
            ])],
            ['code' => 'controls', 'label' => 'Controls', 'layout' => $this->grid([
                [$w['wg-controls-table'], 0, 0, 12, 4],
                [$w['wg-controls-not-implemented'], 0, 4, 12, 4],
            ])],
            ['code' => 'risk-register', 'label' => 'Risk Register', 'layout' => $this->grid([
                [$w['wg-risk-register'], 0, 0, 8, 5], [$w['wg-node-network'], 8, 0, 4, 5],
            ])],
        ];

        foreach ([
            ['Division', 'division-hq', 'Division'],
            ['BusinessUnit', 'business-unit-hq', 'Business Unit'],
            ['Department', 'department-hq', 'Department'],
        ] as [$typeCode, $code, $label]) {
            $type = $this->systemObjectType($typeCode);

            if ($type === null) {
                continue;
            }

            $this->publishSystemDashboard($code, $label, $type->id, $tabs);
        }
    }

    /** The assessment-context tab set, bound to the RiskAssessment type. */
    private function assessmentDashboard(array $w, callable $need): void
    {
        $type = $this->systemObjectType('RiskAssessment');

        $required = [
            'wg-risks-active', 'wg-risks-critical', 'wg-risk-heatmap', 'wg-risks-donut-rating',
            'wg-risk-register', 'wg-treatments-on-track', 'wg-treatments-off-track',
            'wg-treatments-cumulative', 'wg-risks-stacked-area', 'wg-top-risks',
            'wg-risks-trend-quarters', 'wg-losses-pareto', 'wg-node-network',
        ];

        if ($type === null || ! $need($required)) {
            return;
        }

        $this->publishSystemDashboard('assessment-hq', 'Assessment Context', $type->id, [
            ['code' => 'dashboard', 'label' => 'Dashboard', 'layout' => $this->grid([
                [$w['wg-risks-active'], 0, 0, 3, 2], [$w['wg-risks-critical'], 3, 0, 3, 2],
                [$w['wg-risk-heatmap'], 0, 2, 6, 4], [$w['wg-risks-donut-rating'], 6, 2, 6, 4],
            ])],
            ['code' => 'perspective-register', 'label' => 'Perspective Register', 'layout' => $this->grid([
                [$w['wg-risk-register'], 0, 0, 12, 5],
            ])],
            ['code' => 'treatment-overview', 'label' => 'Risk Treatment Overview', 'layout' => $this->grid([
                [$w['wg-treatments-on-track'], 0, 0, 6, 4], [$w['wg-treatments-off-track'], 6, 0, 6, 4],
                [$w['wg-treatments-cumulative'], 0, 4, 12, 4],
            ])],
            ['code' => 'development', 'label' => 'Risk Development Over Time', 'layout' => $this->grid([
                [$w['wg-risks-stacked-area'], 0, 0, 12, 5],
            ])],
            ['code' => 'risk-register', 'label' => 'Risk Register', 'layout' => $this->grid([
                [$w['wg-top-risks'], 0, 0, 12, 5],
            ])],
            ['code' => 'trends', 'label' => 'Trends', 'layout' => $this->grid([
                [$w['wg-risks-trend-quarters'], 0, 0, 12, 5],
            ])],
            ['code' => 'report', 'label' => 'Report', 'layout' => $this->grid([
                [$w['wg-losses-pareto'], 0, 0, 6, 4], [$w['wg-node-network'], 6, 0, 6, 4],
            ])],
        ]);
    }

    /**
     * Write one system dashboard.
     *
     * `version` is deliberately NOT bumped on re-seed. Bumping it would
     * invalidate every user's saved layout_override (DashboardUserPref checks
     * version equality), so a routine `db:seed` would silently throw away the
     * arrangement every CRO had dragged into place. Version belongs to the
     * builder's publish action, which is a human deciding the composition has
     * changed.
     */
    private function publishSystemDashboard(string $code, string $name, ?int $objectTypeId, array $tabs): void
    {
        // bypass(), not just a null attribute: BelongsToOrganization's
        // creating() hook stamps organization_id from TenantContext whenever
        // the attribute is null, so "system" here depends on nobody having set
        // a tenant further up the call stack. Today systemDashboards() runs
        // before TenantContext::set(); making that ordering load-bearing is
        // how a system row quietly becomes one tenant's private dashboard.
        TenantContext::bypass(
            fn () => Dashboard::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => null, 'code' => $code],
                [
                    'name' => $name,
                    'object_type_id' => $objectTypeId,
                    'role_ids' => null,
                    'tabs' => $tabs,
                    // WP-13 — a published dashboard has a live layout as well
                    // as a draft. Writing only `tabs` here would leave
                    // published_tabs NULL, and publishedTabList() falls back to
                    // the draft when it is: the first edit anyone made in the
                    // builder would go live mid-drag, which is the exact
                    // behaviour the split exists to stop.
                    'published_tabs' => $tabs,
                    'is_published' => true,
                ],
            ),
            'seeding system dashboards',
        );
    }

    /** A system object type by code, or null if the registry has not run. */
    private function systemObjectType(string $code): ?ObjectType
    {
        return ObjectType::withoutGlobalScopes()
            ->whereNull('organization_id')
            ->where('code', $code)
            ->first();
    }

    /** System widget ids only — a system dashboard cannot reference tenant rows. */
    private function systemWidgetIds(): array
    {
        return WidgetDefinition::withoutGlobalScopes()
            ->whereNull('organization_id')
            ->pluck('id', 'code')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** The Corporater ERM tab set, as the tenant's default HQ dashboard. */
    private function ermDashboard(Organization $organization): void
    {
        $w = $this->widgetIds($organization);

        $tabs = [
            ['code' => 'dashboard', 'label' => 'Dashboard', 'layout' => $this->grid([
                [$w['wg-risks-active'], 0, 0, 3, 2], [$w['wg-risks-critical'], 3, 0, 3, 2],
                [$w['wg-issues-open'], 6, 0, 3, 2], [$w['wg-treatments-overdue'], 9, 0, 3, 2],
                [$w['wg-risk-heatmap'], 0, 2, 5, 4], [$w['wg-risks-donut-rating'], 5, 2, 3, 4],
                [$w['wg-opportunity-heatmap'], 8, 2, 4, 4],
                [$w['wg-risks-by-unit'], 0, 6, 7, 4], [$w['wg-risks-stacked-area'], 7, 6, 5, 4],
            ])],
            ['code' => 'risk-categories', 'label' => 'Risk Category Dashboard', 'layout' => $this->grid([
                [$w['wg-risks-grouped-bar'], 0, 0, 7, 4], [$w['wg-risks-treemap-category'], 7, 0, 5, 4],
                [$w['wg-risks-donut-rating'], 0, 4, 4, 3], [$w['wg-objects-by-type'], 4, 4, 4, 3],
                [$w['wg-node-network'], 8, 4, 4, 3],
            ])],
            ['code' => 'top-risk', 'label' => 'Top Risk', 'layout' => $this->grid([
                [$w['wg-top-risks'], 0, 0, 7, 5], [$w['wg-risks-bubble'], 7, 0, 5, 5],
            ])],
            ['code' => 'financial-risk', 'label' => 'Financial Risk', 'layout' => $this->grid([
                [$w['wg-losses-gross'], 0, 0, 3, 2], [$w['wg-quant-tornado'], 0, 2, 6, 4],
                [$w['wg-quant-lec'], 6, 2, 6, 4], [$w['wg-losses-pareto'], 0, 6, 7, 4],
            ])],
            ['code' => 'credit-risk', 'label' => 'Credit Risk', 'layout' => $this->grid(array_values(array_filter([
                isset($w['wg-credit-heatmap']) ? [$w['wg-credit-heatmap'], 0, 0, 5, 4] : null,
                isset($w['wg-credit-register']) ? [$w['wg-credit-register'], 5, 0, 7, 4] : null,
            ])))],
            ['code' => 'risk-effectiveness', 'label' => 'Risk Effectiveness', 'layout' => $this->grid(array_values(array_filter([
                [$w['wg-treatments-cumulative'], 0, 0, 6, 4], [$w['wg-controls-table'], 6, 0, 6, 4],
                [$w['wg-controls-not-implemented'], 0, 4, 6, 4],
                [$w['wg-treatments-on-track'], 6, 4, 6, 4],
                [$w['wg-treatments-off-track'], 0, 8, 6, 4],
                isset($w['wg-residual-gauge']) ? [$w['wg-residual-gauge'], 6, 8, 3, 4] : null,
            ])))],
            ['code' => 'risk-register', 'label' => 'Risk Register', 'layout' => $this->grid([
                [$w['wg-risk-register'], 0, 0, 8, 5], [$w['wg-objects-by-type'], 8, 0, 4, 5],
            ])],
            ['code' => 'reports', 'label' => 'Reports', 'layout' => $this->grid([
                [$w['wg-risks-trend-quarters'], 0, 0, 6, 4], [$w['wg-treatments-timeline'], 6, 0, 6, 4],
                [$w['wg-losses-pareto'], 0, 4, 6, 4], [$w['wg-node-network'], 6, 4, 6, 4],
            ])],
        ];

        Dashboard::updateOrCreate(
            ['organization_id' => $organization->id, 'code' => 'erm-hq'],
            [
                'name' => 'Enterprise Risk Management',
                'object_type_id' => null, // the default for every node type
                'role_ids' => null,       // and every role
                'tabs' => $tabs,
                // The live layout, seeded alongside the draft. See the note in
                // publishSystemDashboard().
                'published_tabs' => $tabs,
                'is_published' => true,
                // `version` is not written on re-seed: it is the staleness key
                // for every user's saved layout_override, and resetting it to
                // 1 on a routine db:seed would discard arrangements people had
                // dragged into place. It defaults to 1 on first insert.
            ],
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /** Every widget id visible to the tenant, keyed by code. */
    private function widgetIds(Organization $organization): array
    {
        return WidgetDefinition::withoutGlobalScopes()
            ->where(function ($q) use ($organization) {
                $q->whereNull('organization_id')->orWhere('organization_id', $organization->id);
            })
            ->pluck('id', 'code')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int, 3: int, 4: int}>  $placements  [widget_id, x, y, w, h]
     */
    private function grid(array $placements): array
    {
        return array_map(fn (array $p) => [
            'widget_id' => $p[0],
            'x' => $p[1],
            'y' => $p[2],
            'w' => $p[3],
            'h' => $p[4],
            'overrides' => [],
        ], $placements);
    }
}

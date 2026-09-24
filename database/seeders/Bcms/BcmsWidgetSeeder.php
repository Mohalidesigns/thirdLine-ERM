<?php

namespace Database\Seeders\Bcms;

use App\Models\WidgetDefinition;
use Illuminate\Database\Seeder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The three BCMS widgets for the Dashboards builder — ADR 0021 Amendment 1.
 *
 * SYSTEM ROWS, `organization_id` NULL, so any tenant can place them on any
 * dashboard. Idempotent on `code`, like `TprmWidgetSeeder`'s own shape — the
 * precedent this seeder follows deliberately rather than inventing a second
 * one.
 *
 * BRANCH SCOPE IS A PROPERTY OF DRILLS AND PLANS, NOT OF THE SEVENTEEN
 * RESILIENCE KRIS. The KRIs and the maturity score are organisation-level by
 * definition — no per-unit reading exists to lean on
 * (`KriMeasureBridge::recordMeasurement()` keys one value per KRI object per
 * period, and nothing in it carries a unit) — so exactly ONE KRI tile ships
 * here, named and described as organisation-wide, rather than one KRI widget
 * per branch or a branch widget that silently shows the whole tenant's
 * figure. See `ResilienceKriPublisher`'s own docblock for why `entity_id`
 * and `risk_id` are left unset on every adopted KRI.
 *
 * ORGANISATION-LEVEL ROWS (a group BCP, a corporate drill with no
 * `business_unit_id`) appear at organisation scope only, never on a branch
 * tile — `WidgetQueryEngine`'s `business_unit_ref`/`bcms_definition_units`
 * arms treat an unattributed row as out of scope on every node, the same
 * rule TPRM's `engagementsUnderNodes()` documents for an engagement with no
 * business-unit edge at all.
 */
class BcmsWidgetSeeder extends Seeder
{
    public function run(): void
    {
        // SYSTEM ROWS MUST BE CREATED OUTSIDE A TENANT — the same trap
        // `TprmWidgetSeeder`'s own docblock names: seeding this while a
        // tenant is resolved stamps `organization_id` from the ambient
        // TenantContext and produces three widgets belonging to one tenant,
        // invisible to every other one via the global scope.
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
            ['wg-bcms-drill-calendar', 'Upcoming drills', 'register', [
                'description' => 'Scheduled exercise occurrences on this branch\'s calendar (and its child '
                    .'units\'). A corporate drill run for the whole organisation, with no business unit on '
                    .'its definition, is not attributable to one branch and appears at organisation scope '
                    .'only, never repeated on every branch tile.',
                'query' => [
                    'source' => 'bcms_exercise_occurrences',
                    'columns' => ['status', 'scheduled_date', 'location', 'definition_id'],
                    'sort' => ['by' => 'scheduled_date', 'dir' => 'asc'],
                    'limit' => 10,
                    'filters' => [
                        ['field' => 'status', 'op' => 'not_in', 'value' => ['cancelled', 'completed']],
                        // `$today` is resolved LIVE at render time
                        // (`WidgetQueryEngine::resolveDynamicValue()`), never
                        // frozen at seed time — a literal `date('Y-m-d')`
                        // here would mean "upcoming" stopped moving the day
                        // this row was written (B14, gate 1 code review #1).
                        ['field' => 'scheduled_date', 'op' => 'gte', 'value' => '$today'],
                    ],
                ],
                'drilldown' => ['route' => 'bcms.exercise-programmes.index'],
                'min_w' => 6, 'min_h' => 4,
            ]],

            ['wg-bcms-plan-status', 'Continuity plan status', 'donut', [
                'description' => 'This branch\'s continuity plans by status (and its child units\'). A '
                    .'group-wide plan with no business unit set appears at organisation scope only — '
                    .'counting it on every branch tile would let one plan inflate every branch\'s total.',
                'query' => [
                    'source' => 'bcms_plans',
                    'group_by' => 'status',
                ],
                'drilldown' => ['route' => 'bcms.plans.index'],
                'min_w' => 4, 'min_h' => 4,
            ]],

            ['wg-bcms-resilience-kris', 'Resilience KRIs (organisation-wide)', 'kpi_tile', [
                // B14 (gate 1 code review #1): the previous wording claimed
                // this tile "always shows the whole organisation's count,
                // even when placed on a branch node" — false under
                // Amendment 1. No BCMS-adopted KRI carries a `node_id`, so
                // on a BRANCH node this tile shows ZERO, not the
                // organisation's true count; the honest count is reachable
                // only at organisation scope.
                'description' => 'The seventeen resilience KRIs adopted for this tenant. These are '
                    .'organisation-level by definition — no branch reading exists for any of them — so this '
                    .'tile shows the true count ONLY at organisation scope; placed on a branch node it counts '
                    .'none, because no resilience KRI carries a branch, never a silently repeated org-wide figure.',
                'query' => [
                    'source' => 'key_risk_indicators',
                    'filters' => [
                        ['field' => 'kri_code', 'op' => 'like', 'value' => 'BCMS-'],
                    ],
                ],
                'drilldown' => ['route' => 'bcms.reports.compliance-matrix'],
                'min_w' => 3, 'min_h' => 2,
            ]],
        ];
    }
}

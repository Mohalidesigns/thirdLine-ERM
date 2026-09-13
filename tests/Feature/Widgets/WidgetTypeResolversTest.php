<?php

namespace Tests\Feature\Widgets;

use App\Models\BusinessUnit;
use App\Models\Control;
use App\Models\TreatmentPlan;
use App\Models\WidgetDefinition;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetDataService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-08 TASK 2 — the numbers the type resolvers derive, pinned.
 *
 * Each test freezes the arithmetic a resolver performs on top of the query
 * engine: cumulative percentages, RAG derivation, direction counting,
 * honest exclusion of unscored rows. The engine's scoping itself is pinned
 * by WidgetContextBindingTest.
 */
class WidgetTypeResolversTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private WidgetDataService $widgets;

    private BusinessUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11'));
        \Illuminate\Support\Carbon::setTestNow('2026-08-11');

        $this->bootDomainFixtures();
        $this->widgets = app(WidgetDataService::class);

        foreach (['risk.view', 'loss_event.view', 'treatment.view', 'control.view'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['risk.view', 'loss_event.view', 'treatment.view', 'control.view']);

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'BU-T',
            'name' => 'Test Unit',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function pareto_orders_descending_and_accumulates_to_one_hundred(): void
    {
        foreach ([['INTERNAL_FRAUD', 6000], ['EXTERNAL_FRAUD', 3000], ['EXECUTION_DELIVERY', 1000]] as [$category, $kobo]) {
            $this->makeLossEvent([
                'basel_l1_category' => $category,
                'gross_loss_amount_kobo' => $kobo,
                'business_unit_id' => $this->unit->id,
            ]);
        }

        $result = $this->render('pareto', [
            'query' => [
                'source' => 'loss_events',
                'group_by' => 'basel_l1_category',
                'aggregate' => ['fn' => 'sum', 'field' => 'gross_loss_amount_kobo'],
            ],
        ]);

        $bars = $result['data']['bars'];

        $this->assertSame(['INTERNAL_FRAUD', 'EXTERNAL_FRAUD', 'EXECUTION_DELIVERY'], array_column($bars, 'label'));
        $this->assertSame([60.0, 90.0, 100.0], array_column($bars, 'cumulative_pct'));
    }

    #[Test]
    public function activity_table_derives_rag_from_dates_and_progress(): void
    {
        $risk = $this->makeRisk(['business_unit_id' => $this->unit->id]);

        $make = fn (array $attributes) => TreatmentPlan::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'strategy' => 'mitigate',
            'owner_id' => $this->actor->id,
            'status' => 'in_progress',
            'progress_pct' => 50,
            'created_by' => $this->actor->id,
        ], $attributes));

        $make(['action_title' => 'Late', 'target_date' => '2026-08-01']);
        $make(['action_title' => 'Predictably late', 'target_date' => '2026-08-20', 'progress_pct' => 40]);
        $make(['action_title' => 'Comfortable', 'target_date' => '2026-12-01']);
        $make(['action_title' => 'Done late but done', 'target_date' => '2026-08-01', 'status' => 'completed']);

        $result = $this->render('activity_table', ['query' => ['source' => 'treatment_plans']]);

        $rags = collect($result['data']['rows'])->pluck('rag', 'name');

        $this->assertSame('red', $rags['Late']);
        $this->assertSame('amber', $rags['Predictably late']);
        $this->assertSame('green', $rags['Comfortable']);
        $this->assertSame('green', $rags['Done late but done']);
    }

    #[Test]
    public function cumulative_line_divides_completions_by_the_whole_scope(): void
    {
        $risk = $this->makeRisk(['business_unit_id' => $this->unit->id]);

        foreach ([['2026-03-10', 'completed'], ['2026-05-10', 'completed'], [null, 'in_progress'], [null, 'planned']] as [$done, $status]) {
            TreatmentPlan::create([
                'organization_id' => $this->organization->id,
                'risk_id' => $risk->id,
                'strategy' => 'mitigate',
                'action_title' => 'Plan '.$status.($done ?? 'open'),
                'owner_id' => $this->actor->id,
                'status' => $status,
                'completion_date' => $done,
                'created_by' => $this->actor->id,
            ]);
        }

        $result = $this->render('cumulative_line', [
            'period_binding' => 'range',
            'period_config' => ['type' => 'month', 'count' => 6],
            'query' => ['source' => 'treatment_plans'],
        ]);

        $values = $result['data']['values'];

        // Two of four complete by the window's end; the March completion
        // already counts at the window's start (March onward).
        $this->assertSame(50.0, end($values));
        $this->assertContains(25.0, $values);
    }

    #[Test]
    public function bubble_skips_unscored_risks_rather_than_plotting_them_at_zero(): void
    {
        $this->makeRisk([
            'business_unit_id' => $this->unit->id,
            'title' => 'Scored',
            'residual_score' => 12,
            'control_effectiveness_pct' => 70,
        ]);
        $this->makeRisk([
            'business_unit_id' => $this->unit->id,
            'title' => 'Unscored',
            'residual_score' => null,
            'control_effectiveness_pct' => null,
        ]);

        $result = $this->render('bubble', ['query' => ['source' => 'risks']]);

        $this->assertCount(1, $result['data']['points']);
        $this->assertSame('Scored', $result['data']['points'][0]['label']);
        $this->assertSame(1, $result['data']['skipped']);
    }

    #[Test]
    public function measure_table_reports_implementation_honestly(): void
    {
        Control::create([
            'organization_id' => $this->organization->id,
            'control_code' => 'CTL-A',
            'name' => 'Active tested control',
            'status' => 'active',
            'business_unit_id' => $this->unit->id,
            'last_test_result' => 'passed',
            'created_by' => $this->actor->id,
        ]);
        Control::create([
            'organization_id' => $this->organization->id,
            'control_code' => 'CTL-B',
            'name' => 'Draft control',
            'status' => 'draft',
            'business_unit_id' => $this->unit->id,
            'created_by' => $this->actor->id,
        ]);

        $all = $this->render('measure_table', ['query' => ['source' => 'controls']]);
        $rows = collect($all['data']['rows'])->keyBy('name');

        $this->assertTrue($rows['Active tested control']['implemented']);
        $this->assertSame('passed', $rows['Active tested control']['glyph']);
        $this->assertFalse($rows['Draft control']['implemented']);
        $this->assertNull($rows['Draft control']['glyph']);

        $notImplemented = $this->render('measure_table', [
            'query' => ['source' => 'controls'],
            'visualisation' => ['mode' => 'not_implemented'],
        ]);

        $this->assertSame(['Draft control'], array_column($notImplemented['data']['rows'], 'name'));
    }

    #[Test]
    public function tornado_and_lec_admit_having_no_simulation_rather_than_sketching_one(): void
    {
        $tornado = $this->render('tornado', []);
        $lec = $this->render('lec_curve', []);

        $this->assertTrue($tornado['data']['empty']);
        $this->assertSame([], $tornado['data']['rows']);
        $this->assertTrue($lec['data']['empty']);
        $this->assertSame([], $lec['data']['points']);
    }

    /* ------------------------------------------------------------------ */

    private function render(string $type, array $attributes): array
    {
        static $sequence = 0;

        $definition = WidgetDefinition::create(array_merge([
            'organization_id' => $this->organization->id,
            'code' => 'resolver-test-'.(++$sequence),
            'name' => 'Resolver test',
            'widget_type' => $type,
            'context_binding' => 'inherit_subtree',
            'period_binding' => 'selected',
        ], $attributes));

        return $this->widgets->render($definition, new WidgetContext($this->actor, $this->unit->graphObject()));
    }
}

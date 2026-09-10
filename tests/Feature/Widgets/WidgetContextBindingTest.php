<?php

namespace Tests\Feature\Widgets;

use App\Models\BusinessUnit;
use App\Models\Risk;
use App\Models\WidgetDefinition;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-08 acceptance — "the same widget definition on two different nodes
 * returns two correct results."
 *
 * This is the engine's load-bearing property: context_binding resolved at
 * render time from the page's node, one definition serving every page. If
 * this test fails, the widget engine is a set of copies with extra steps.
 */
class WidgetContextBindingTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private WidgetDataService $widgets;

    private BusinessUnit $group;

    private BusinessUnit $retail;

    private BusinessUnit $treasury;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        $this->widgets = app(WidgetDataService::class);

        Permission::findOrCreate('risk.view');
        $this->actor->givePermissionTo('risk.view');

        $this->group = $this->makeUnit('BU-GRP', 'Group');
        $this->retail = $this->makeUnit('BU-RT', 'Retail', $this->group);
        $this->treasury = $this->makeUnit('BU-TR', 'Treasury', $this->group);

        // Three risks in Retail, two in Treasury — the two subtrees must
        // disagree for the assertion to mean anything.
        foreach ([[4, 4], [3, 2], [2, 5]] as $i => [$l, $c]) {
            $this->makeScoredRisk($this->retail, $l, $c);
        }

        foreach ([[5, 5], [1, 1]] as [$l, $c]) {
            $this->makeScoredRisk($this->treasury, $l, $c);
        }
    }

    #[Test]
    public function the_same_definition_returns_each_nodes_own_result(): void
    {
        $definition = $this->countDefinition('inherit_subtree');

        $retail = $this->widgets->render($definition, $this->contextAt($this->retail));
        $treasury = $this->widgets->render($definition, $this->contextAt($this->treasury));
        $group = $this->widgets->render($definition, $this->contextAt($this->group));

        $this->assertSame('ok', $retail['state']);
        $this->assertSame(3, $retail['data']['value']);
        $this->assertSame(2, $treasury['data']['value']);
        // The group sees both subtrees through the graph roll-up.
        $this->assertSame(5, $group['data']['value']);
    }

    #[Test]
    public function inherit_node_counts_the_node_alone_not_its_subtree(): void
    {
        $definition = $this->countDefinition('inherit_node');

        // The group node itself carries no risks; its children do.
        $group = $this->widgets->render($definition, $this->contextAt($this->group));
        $retail = $this->widgets->render($definition, $this->contextAt($this->retail));

        $this->assertSame(0, (int) $group['data']['value']);
        $this->assertSame(3, $retail['data']['value']);
    }

    #[Test]
    public function no_node_widens_an_inherit_binding_to_the_organization(): void
    {
        $definition = $this->countDefinition('inherit_subtree');

        $result = $this->widgets->render($definition, new WidgetContext($this->actor));

        $this->assertSame(5, $result['data']['value']);
    }

    #[Test]
    public function a_fixed_node_ignores_the_page_it_is_placed_on(): void
    {
        $definition = $this->makeDefinition([
            'context_binding' => 'fixed_node',
            'query' => [
                'source' => 'risks',
                'node_id' => $this->treasury->graphObject()->id,
            ],
        ]);

        // Placed on the Retail page, still answers for Treasury.
        $result = $this->widgets->render($definition, $this->contextAt($this->retail));

        $this->assertSame(2, $result['data']['value']);
    }

    #[Test]
    public function a_deleted_fixed_node_renders_empty_rather_than_widening(): void
    {
        $definition = $this->makeDefinition([
            'context_binding' => 'fixed_node',
            'query' => ['source' => 'risks', 'node_id' => 999999],
        ]);

        $result = $this->widgets->render($definition, $this->contextAt($this->retail));

        $this->assertSame('ok', $result['state']);
        $this->assertNull($result['data']['value']);
    }

    #[Test]
    public function the_heatmap_counts_cells_within_the_context_and_carries_drill_filters(): void
    {
        $definition = $this->makeDefinition([
            'widget_type' => 'heatmap',
            'context_binding' => 'inherit_subtree',
            'query' => ['source' => 'risks', 'filters' => [
                ['field' => 'status', 'op' => 'eq', 'value' => 'active'],
            ]],
        ]);

        $result = $this->widgets->render($definition, $this->contextAt($this->retail));

        $this->assertSame('ok', $result['state']);
        $this->assertSame(3, $result['data']['total']);

        $cells = collect($result['data']['cells']);

        $hot = $cells->first(fn (array $c) => $c['likelihood'] === 4 && $c['impact'] === 4);
        $this->assertSame(1, $hot['count']);
        $this->assertSame(
            ['residual_likelihood' => 4, 'residual_impact' => 4],
            $hot['filters'],
        );

        // A cell with no risks still renders, with an honest zero.
        $cold = $cells->first(fn (array $c) => $c['likelihood'] === 1 && $c['impact'] === 1);
        $this->assertSame(0, $cold['count']);
    }

    #[Test]
    public function a_register_pages_within_its_context(): void
    {
        $definition = $this->makeDefinition([
            'widget_type' => 'register',
            'context_binding' => 'inherit_subtree',
            'query' => [
                'source' => 'risks',
                'columns' => ['risk_code', 'title', 'residual_rating'],
                'limit' => 2,
            ],
        ]);

        $result = $this->widgets->render($definition, $this->contextAt($this->retail));

        $this->assertSame(3, $result['data']['total']);
        $this->assertCount(2, $result['data']['rows']);
        $this->assertSame(['risk_code', 'title', 'residual_rating'], array_column($result['data']['columns'], 'key'));
    }

    #[Test]
    public function a_viewer_without_the_source_permission_gets_a_locked_panel(): void
    {
        $this->actor->revokePermissionTo('risk.view');

        $definition = $this->countDefinition('inherit_subtree');
        $result = $this->widgets->render($definition, $this->contextAt($this->retail));

        $this->assertSame('forbidden', $result['state']);
        $this->assertSame([], $result['data']);
    }

    #[Test]
    public function an_unknown_source_degrades_to_an_error_panel_not_an_exception(): void
    {
        $definition = $this->makeDefinition([
            'query' => ['source' => 'users'],
        ]);

        $result = $this->widgets->render($definition, $this->contextAt($this->retail));

        $this->assertSame('error', $result['state']);
    }

    #[Test]
    public function a_filter_on_a_non_whitelisted_column_is_refused(): void
    {
        $definition = $this->makeDefinition([
            'query' => [
                'source' => 'risks',
                'filters' => [['field' => 'created_by', 'op' => 'eq', 'value' => 1]],
            ],
        ]);

        $result = $this->widgets->render($definition, $this->contextAt($this->retail));

        $this->assertSame('error', $result['state']);
    }

    /* ------------------------------------------------------------------ */
    /*  Fixtures */
    /* ------------------------------------------------------------------ */

    private function makeUnit(string $code, string $name, ?BusinessUnit $parent = null): BusinessUnit
    {
        return BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'parent_id' => $parent?->id,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function makeScoredRisk(BusinessUnit $unit, int $likelihood, int $impact): Risk
    {
        return $this->makeRisk([
            'business_unit_id' => $unit->id,
            'residual_likelihood' => $likelihood,
            'residual_impact' => $impact,
            'residual_score' => $likelihood * $impact,
        ]);
    }

    private function countDefinition(string $contextBinding): WidgetDefinition
    {
        return $this->makeDefinition(['context_binding' => $contextBinding]);
    }

    private function makeDefinition(array $attributes = []): WidgetDefinition
    {
        static $sequence = 0;

        return WidgetDefinition::create(array_merge([
            'organization_id' => $this->organization->id,
            'code' => 'test-widget-'.(++$sequence),
            'name' => 'Test widget',
            'widget_type' => 'kpi_tile',
            'context_binding' => 'inherit_subtree',
            'period_binding' => 'selected',
            'query' => ['source' => 'risks'],
        ], $attributes));
    }

    private function contextAt(BusinessUnit $unit): WidgetContext
    {
        return new WidgetContext($this->actor, $unit->graphObject());
    }
}

<?php

namespace Tests\Feature\Graph;

use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\GraphObject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Graph\GraphQueryService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-03 TASK 6 — reading the graph, and not reading what you may not.
 */
class GraphQueryServiceTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private GraphQueryService $graph;

    private BusinessUnit $group;

    private BusinessUnit $retail;

    private BusinessUnit $lagos;

    private BusinessProcess $cashHandling;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        $this->graph = app(GraphQueryService::class);

        $this->group = $this->makeUnit('BU-GRP', 'Group');
        $this->retail = $this->makeUnit('BU-RT', 'Retail', $this->group);
        $this->lagos = $this->makeUnit('BU-LG', 'Lagos Region', $this->retail);

        $this->cashHandling = BusinessProcess::create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->lagos->id,
            'code' => 'PRC-CASH',
            'name' => 'Cash handling',
            'is_active' => true,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Traversal */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function descendants_returns_the_whole_subtree_at_any_depth(): void
    {
        $descendants = $this->graph->descendants($this->group->graphObject()->id);

        $this->assertEqualsCanonicalizing(
            ['Retail', 'Lagos Region', 'Cash handling'],
            $descendants->pluck('name')->all()
        );
    }

    #[Test]
    public function descendants_can_be_limited_by_depth_and_by_type(): void
    {
        $rootId = $this->group->graphObject()->id;

        $this->assertSame(['Retail'], $this->graph->descendants($rootId, maxDepth: 1)->pluck('name')->all());

        $this->assertSame(
            ['Cash handling'],
            $this->graph->descendants($rootId, types: ['Process'])->pluck('name')->all()
        );
    }

    #[Test]
    public function descendants_excludes_the_root_itself(): void
    {
        $rootId = $this->group->graphObject()->id;

        $this->assertFalse($this->graph->descendants($rootId)->contains('id', $rootId));
    }

    #[Test]
    public function ancestors_are_returned_nearest_first(): void
    {
        $ancestors = $this->graph->ancestors($this->cashHandling->graphObject()->id);

        $this->assertSame(['Lagos Region', 'Retail', 'Group'], $ancestors->pluck('name')->all());
    }

    #[Test]
    public function related_follows_an_edge_in_either_direction(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();
        $control->relate('mitigates', $risk);

        $riskObjectId = $risk->graphObject()->id;
        $controlObjectId = $control->graphObject()->id;

        $this->assertSame([$riskObjectId], $this->graph->related($controlObjectId, 'mitigates')->pluck('id')->all());
        $this->assertSame([$controlObjectId], $this->graph->related($riskObjectId, 'mitigates', 'in')->pluck('id')->all());
    }

    #[Test]
    public function related_follows_multiple_hops_without_looping(): void
    {
        $a = $this->makeRisk();
        $b = $this->makeRisk();
        $c = $this->makeRisk();

        $a->relate('causes', $b);
        $b->relate('causes', $c);
        // A cycle back to the start: the walk must terminate.
        $c->relate('causes', $a);

        $reached = $this->graph->related($a->graphObject()->id, 'causes', depth: 5);

        $this->assertEqualsCanonicalizing(
            [$b->graphObject()->id, $c->graphObject()->id],
            $reached->pluck('id')->all()
        );
    }

    #[Test]
    public function traverse_walks_a_named_sequence_of_relationships(): void
    {
        // 'supports' only permits a service-shaped thing on the far end, so the
        // payments service is a Process; the vendor's card processor is a node
        // of its own and 'depends_on' is deliberately generic.
        $service = BusinessProcess::create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->retail->id,
            'code' => 'PRC-PAY',
            'name' => 'Payments service',
            'is_active' => true,
        ]);

        $vendor = $this->makeUnit('BU-VND', 'Card processor');

        $service->relate('depends_on', $vendor);
        $this->cashHandling->relate('supports', $service);

        $result = $this->graph->traverse(
            $this->cashHandling->graphObject()->id,
            ['supports', 'depends_on']
        );

        $this->assertSame(['Card processor'], $result->pluck('name')->all());
    }

    #[Test]
    public function traverse_returns_nothing_when_the_path_breaks(): void
    {
        $result = $this->graph->traverse($this->cashHandling->graphObject()->id, ['supports', 'depends_on']);

        $this->assertTrue($result->isEmpty());
    }

    /* ------------------------------------------------------------------ */
    /*  Roll-up */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function roll_up_sums_a_configured_measure_across_the_subtree(): void
    {
        $this->setMeasure($this->retail, 'headcount', 120);
        $this->setMeasure($this->lagos, 'headcount', 45);
        $this->setMeasure($this->cashHandling, 'headcount', 5);

        $result = $this->graph->rollUp($this->group->graphObject()->id, 'headcount');

        $this->assertSame(170.0, (float) $result['value']);
        $this->assertSame(3, $result['contributors']);
    }

    #[Test]
    public function roll_up_returns_null_rather_than_zero_when_there_is_no_data(): void
    {
        $result = $this->graph->rollUp($this->group->graphObject()->id, 'headcount');

        // Zero is a number somebody will act on. "No data" is not.
        $this->assertNull($result['value']);
        $this->assertSame(0, $result['contributors']);
    }

    #[Test]
    public function roll_up_applies_the_weight_on_a_weighted_edge(): void
    {
        $this->setMeasure($this->retail, 'exposure', 100);
        // Retail carries a quarter of the group's exposure.
        $this->retail->relate('reports_to', $this->group, weight: 0.25);

        $result = $this->graph->rollUp($this->group->graphObject()->id, 'exposure');

        $this->assertSame(25.0, (float) $result['value']);
    }

    #[Test]
    public function roll_up_refuses_to_answer_for_a_period_it_cannot_yet_see(): void
    {
        $this->setMeasure($this->retail, 'headcount', 120);

        // The period-aware measure model arrives in WP-04. Until then a request
        // for a specific period must not hand back today's number wearing a
        // past date.
        $result = $this->graph->rollUp($this->group->graphObject()->id, 'headcount', periodId: 7);

        $this->assertNull($result['value']);
    }

    /* ------------------------------------------------------------------ */
    /*  Authorization */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function another_tenants_nodes_are_invisible(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHR',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::actingAs($otherOrg->id, function () use ($otherOrg) {
            BusinessUnit::create([
                'organization_id' => $otherOrg->id,
                'code' => 'BU-X',
                'name' => 'Their Retail',
                'is_active' => true,
            ]);
        });

        TenantContext::set($this->organization->id);

        $this->assertFalse(
            $this->graph->descendants($this->group->graphObject()->id)->contains('name', 'Their Retail')
        );
        $this->assertSame(0, GraphObject::where('name', 'Their Retail')->count());
    }

    #[Test]
    public function a_user_pinned_to_a_subtree_cannot_read_above_it(): void
    {
        $entityType = EntityType::create([
            'organization_id' => $this->organization->id,
            'code' => 'DIVISION',
            'name' => 'Division',
            'level' => 2,
        ]);

        $scopeEntity = Entity::create([
            'organization_id' => $this->organization->id,
            'entity_type_id' => $entityType->id,
            'entity_code' => 'ENT-SCOPE',
            'name' => 'Scoped Division',
            'status' => 'active',
        ]);

        $child = Entity::create([
            'organization_id' => $this->organization->id,
            'entity_type_id' => $entityType->id,
            'entity_code' => 'ENT-CHILD',
            'name' => 'Scoped Child',
            'parent_id' => $scopeEntity->id,
            'status' => 'active',
        ]);

        $pinned = User::create([
            'name' => 'Divisional officer',
            'email' => 'pinned@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
            'scope_entity_id' => $scopeEntity->id,
        ]);

        $visible = $this->graph->descendants($scopeEntity->graphObject()->id, user: $pinned);
        $this->assertSame(['Scoped Child'], $visible->pluck('name')->all());

        // The Group sits outside the pin entirely.
        $this->assertTrue(
            $this->graph->descendants($this->group->graphObject()->id, user: $pinned)->isEmpty(),
            'A pinned user read a subtree outside their scope'
        );

        $this->assertNotNull($child->graphObject());
    }

    #[Test]
    public function a_pin_at_a_node_with_no_graph_counterpart_fails_closed(): void
    {
        $entityType = EntityType::create([
            'organization_id' => $this->organization->id,
            'code' => 'DIVISION',
            'name' => 'Division',
            'level' => 2,
        ]);

        $orphanEntity = Entity::create([
            'organization_id' => $this->organization->id,
            'entity_type_id' => $entityType->id,
            'entity_code' => 'ENT-GONE',
            'name' => 'Vanished Division',
            'status' => 'active',
        ]);

        // Remove the graph node while the pin stays.
        GraphObject::where('source_model_type', 'entity')
            ->where('source_model_id', $orphanEntity->id)
            ->forceDelete();

        $pinned = User::create([
            'name' => 'Stranded officer',
            'email' => 'stranded@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
            'scope_entity_id' => $orphanEntity->id,
        ]);

        $this->assertTrue(
            $this->graph->descendants($this->group->graphObject()->id, user: $pinned)->isEmpty(),
            'A pin pointing at a missing node must fail closed, not widen to the organization'
        );
    }

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

    private function setMeasure(BusinessUnit|BusinessProcess $model, string $code, int|float $value): void
    {
        $object = $model->graphObject();
        $object->setCustomAttributes(array_merge($object->customAttributes(), [$code => $value]));
        $object->saveQuietly();
    }
}

<?php

namespace Tests\Feature\Widgets;

use App\Models\BusinessUnit;
use App\Models\WidgetDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Migration Phase 2 — risk/widgets/{widget}/payload serves what the Livewire
 * WidgetPanel used to resolve on every render: the engine's envelope for one
 * widget in one node's context, plus the URLs the React panel needs.
 */
class WidgetPayloadEndpointTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $retail;

    private WidgetDefinition $widget;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['dashboard.view', 'risk.view'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['dashboard.view', 'risk.view']);

        $this->retail = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'BU-RT',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);

        $this->widget = WidgetDefinition::withoutGlobalScopes()->create([
            'organization_id' => null,
            'code' => 'wg-count',
            'name' => 'Risk Count',
            'widget_type' => 'kpi_tile',
            'query' => ['source' => 'risks', 'aggregate' => 'count'],
            'context_binding' => 'inherit_subtree',
            'period_binding' => 'selected',
            'is_system' => true,
        ]);
    }

    #[Test]
    public function the_payload_is_the_engine_envelope_with_urls(): void
    {
        $node = $this->retail->graphObject();

        $this->actingAs($this->actor)
            ->getJson(route('risk.widgets.payload', [$this->widget, 'node' => $node->id]))
            ->assertOk()
            ->assertJsonPath('state', 'ok')
            ->assertJsonPath('widget_id', $this->widget->id)
            ->assertJsonPath('type', 'kpi_tile')
            ->assertJsonPath('title', 'Risk Count')
            ->assertJsonPath('request.node', $node->id)
            ->assertJsonStructure(['data', 'meta', 'urls' => ['payload', 'export', 'drill', 'create']]);
    }

    #[Test]
    public function an_override_title_wins(): void
    {
        $this->actingAs($this->actor)
            ->getJson(route('risk.widgets.payload', [$this->widget, 'overrides' => ['title' => 'Open risks']]))
            ->assertOk()
            ->assertJsonPath('title', 'Open risks');
    }

    #[Test]
    public function the_export_is_a_csv_download(): void
    {
        $response = $this->actingAs($this->actor)->get(route('risk.widgets.export', $this->widget));

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('wg-count.csv', (string) $response->headers->get('content-disposition'));
    }

    #[Test]
    public function the_endpoint_requires_dashboard_view(): void
    {
        $this->actor->revokePermissionTo('dashboard.view');

        $this->actingAs($this->actor)
            ->getJson(route('risk.widgets.payload', $this->widget))
            ->assertForbidden();
    }

    #[Test]
    public function a_node_from_another_tenant_is_not_a_context(): void
    {
        $other = $this->otherOrganization();

        // Build the foreign unit AS the other tenant so it projects into that
        // tenant's graph, then come back.
        \ThirdLine\Platform\Tenancy\TenantContext::set($other->id);
        $foreign = BusinessUnit::create([
            'organization_id' => $other->id,
            'code' => 'BU-X',
            'name' => 'Foreign Unit',
            'is_active' => true,
        ]);
        $foreignNodeId = (int) $foreign->graphObject()->id;
        \ThirdLine\Platform\Tenancy\TenantContext::set($this->organization->id);

        $this->actingAs($this->actor)
            ->getJson(route('risk.widgets.payload', [$this->widget, 'node' => $foreignNodeId]))
            ->assertOk()
            ->assertJsonPath('meta.node', null);
    }

    private function otherOrganization(): \App\Models\Organization
    {
        return \App\Models\Organization::create([
            'name' => 'Other Bank',
            'short_name' => 'OTHR'.random_int(100, 999),
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);
    }
}

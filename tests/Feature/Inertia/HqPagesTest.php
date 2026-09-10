<?php

namespace Tests\Feature\Inertia;

use App\Models\BusinessUnit;
use App\Models\GraphObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Migration Phase 2 — Business HQ is an Inertia page. The props are the
 * contract the React page renders from: the node, its ancestors, the org tree
 * and either a dashboard with resolved widget payloads or the material for
 * the empty state.
 */
class HqPagesTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $retail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['hq.view', 'dashboard.view', 'risk.view'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['hq.view', 'dashboard.view', 'risk.view']);

        $this->retail = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'BU-RT',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function the_index_lands_on_a_root_node(): void
    {
        $this->actingAs($this->actor)->get('/hq')->assertRedirect();
    }

    #[Test]
    public function a_node_page_carries_the_node_its_ancestors_and_the_tree(): void
    {
        $node = $this->retail->graphObject();

        $this->actingAs($this->actor)->get('/hq/'.$node->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Hq/Show')
                ->where('object.id', (int) $node->id)
                ->where('object.name', 'Retail Banking')
                ->has('ancestors')
                ->has('tree')
                ->has('tabs')
                ->has('payloads')
                ->where('canManage', false)
                ->where('preview', null)
                ->where('previewRefused', null));
    }

    #[Test]
    public function the_tree_is_nested_and_marks_depth(): void
    {
        $node = $this->retail->graphObject();

        $this->actingAs($this->actor)->get('/hq/'.$node->id)
            ->assertInertia(fn (Assert $page) => $page
                ->has('tree.0', fn (Assert $root) => $root
                    ->has('id')->has('name')->has('type')->has('icon')->has('children')
                    ->where('depth', 0)
                    ->etc()));
    }

    #[Test]
    public function a_node_of_another_tenant_is_not_found(): void
    {
        $foreign = GraphObject::withoutGlobalScopes()
            ->where('organization_id', '!=', $this->organization->id)
            ->first();

        if ($foreign === null) {
            $this->markTestSkipped('No foreign graph node in the fixtures.');
        }

        $this->actingAs($this->actor)->get('/hq/'.$foreign->id)->assertNotFound();
    }

    #[Test]
    public function an_empty_graph_renders_the_empty_page(): void
    {
        GraphObject::query()->delete();

        $this->actingAs($this->actor)->get('/hq')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Hq/Empty'));
    }
}

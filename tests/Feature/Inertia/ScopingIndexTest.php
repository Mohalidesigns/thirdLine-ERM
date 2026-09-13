<?php

namespace Tests\Feature\Inertia;

use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Migration Phase 2 — the entity register is the pilot grid flip.
 */
class ScopingIndexTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private EntityType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['entity.view', 'entity.create', 'entity.edit'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['entity.view', 'entity.edit']);

        $this->type = EntityType::create([
            'organization_id' => $this->organization->id,
            'code' => 'BRANCH',
            'name' => 'Branch',
            'level' => 2,
            'is_active' => true,
        ]);

        $this->actingAs($this->actor);
    }

    private function makeEntity(array $attributes = []): Entity
    {
        static $sequence = 0;
        $sequence++;

        return Entity::create(array_merge([
            'organization_id' => $this->organization->id,
            'entity_type_id' => $this->type->id,
            'entity_code' => sprintf('ENT-%03d', $sequence),
            'name' => "Entity {$sequence}",
            'status' => 'active',
            'level' => 2,
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    #[Test]
    public function the_index_renders_the_grid_through_inertia(): void
    {
        $entity = $this->makeEntity(['name' => 'Lagos Island Branch']);

        $this->get(route('risk.scoping.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Scoping/Index')
                ->where('totalCount', 1)
                ->where('entityTypes.0.name', 'Branch')
                ->where('entityTypes.0.count', 1)
                ->where('grid.name', 'entities')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.name.text', 'Lagos Island Branch')
                ->where('grid.rows.data.0.cells.entity_code.text', $entity->entity_code)
                ->where('grid.rows.data.0.cells.entity_code.href', route('risk.scoping.show', $entity))
                ->where('grid.rows.data.0.href', route('risk.scoping.show', $entity))
                ->where('grid.rows.data.0.actions.1.label', 'Edit')
                ->where('grid.state.sort', 'entity_code'));
    }

    #[Test]
    public function search_filters_and_sort_come_from_the_url(): void
    {
        $this->makeEntity(['name' => 'Lagos Operations Hub', 'entity_code' => 'ENT-LAG']);
        $this->makeEntity(['name' => 'Abuja Treasury Desk', 'entity_code' => 'ENT-ABJ', 'status' => 'inactive']);

        $this->get(route('risk.scoping.index', ['search' => 'Lagos']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.entity_code.text', 'ENT-LAG'));

        $this->get(route('risk.scoping.index', ['filters' => ['status' => 'inactive']]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.entity_code.text', 'ENT-ABJ')
                ->where('grid.state.filters.status', 'inactive'));

        // An undeclared filter value is ignored, not passed into SQL.
        $this->get(route('risk.scoping.index', ['filters' => ['status' => "x' OR 1=1 --"]]))
            ->assertInertia(fn (Assert $page) => $page->has('grid.rows.data', 2));

        $this->get(route('risk.scoping.index', ['sort' => 'entity_code', 'dir' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.rows.data.0.cells.entity_code.text', 'ENT-LAG')
                ->where('grid.state.dir', 'desc'));

        // An unknown sort column falls back to the default.
        $this->get(route('risk.scoping.index', ['sort' => 'no_such_column']))
            ->assertInertia(fn (Assert $page) => $page->where('grid.state.sort', 'entity_code'));
    }

    #[Test]
    public function another_tenants_entities_never_appear(): void
    {
        $this->makeEntity(['name' => 'Ours']);

        $other = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);
        $otherType = EntityType::create(['organization_id' => $other->id, 'code' => 'BR', 'name' => 'Branch', 'level' => 2, 'is_active' => true]);
        Entity::create([
            'organization_id' => $other->id,
            'entity_type_id' => $otherType->id,
            'entity_code' => 'ENT-FOREIGN',
            'name' => 'Theirs',
            'status' => 'active',
            'level' => 2,
            'created_by' => $this->actor->id,
        ]);

        $this->get(route('risk.scoping.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.name.text', 'Ours'));

        $this->get(route('risk.grids.export', ['entities', 'csv']))
            ->assertOk()
            ->assertDontSee('ENT-FOREIGN');
    }

    #[Test]
    public function the_grid_endpoint_and_export_honour_the_same_state(): void
    {
        $this->makeEntity(['name' => 'Lagos Operations Hub', 'entity_code' => 'ENT-LAG']);
        $this->makeEntity(['name' => 'Abuja Treasury Desk', 'entity_code' => 'ENT-ABJ']);

        $this->getJson(route('risk.grids.show', ['entities', 'search' => 'Abuja']))
            ->assertOk()
            ->assertJsonPath('rows.data.0.cells.entity_code.text', 'ENT-ABJ')
            ->assertJsonCount(1, 'rows.data');

        ob_start();
        $this->get(route('risk.grids.export', ['entities', 'csv', 'search' => 'Abuja']))->assertOk()->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('ENT-ABJ', $csv);
        $this->assertStringNotContainsString('ENT-LAG', $csv);
        $this->assertStringContainsString('Entity Name', $csv);
    }

    #[Test]
    public function the_blade_view_is_gone_and_the_route_is_marked_ported(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/risk/scoping/index.blade.php'));
        $this->assertTrue(\App\Support\Migration\Ported::isRoute('risk.scoping.index'));
    }
}

<?php

namespace Tests\Feature\Grid;

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
 * WP-09 — the entity register on the shared data grid
 * (App\Grids\Definitions\EntitiesGrid).
 */
class EntitiesGridTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private EntityType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('entity.view');
        $this->actor->givePermissionTo('entity.view');

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
    public function the_index_page_renders_a_seeded_entity(): void
    {
        $entity = $this->makeEntity(['name' => 'Lagos Island Branch']);

        $this->get(route('risk.scoping.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Scoping/Index')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.entity_code.text', $entity->entity_code)
                ->where('grid.rows.data.0.cells.name.text', 'Lagos Island Branch'));
    }

    #[Test]
    public function grid_search_narrows_server_side(): void
    {
        $this->makeEntity(['name' => 'Lagos Operations Hub', 'entity_code' => 'ENT-LAG']);
        $this->makeEntity(['name' => 'Abuja Treasury Desk', 'entity_code' => 'ENT-ABJ']);

        $this->get(route('risk.scoping.index'))
            ->assertInertia(fn (Assert $page) => $page->has('grid.rows.data', 2));

        $this->get(route('risk.scoping.index', ['search' => 'Lagos']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.entity_code.text', 'ENT-LAG'));
    }

    #[Test]
    public function another_organizations_entities_never_render(): void
    {
        $mine = $this->makeEntity(['name' => 'Our Branch']);

        $otherOrg = Organization::create(['name' => 'Other Bank', 'slug' => 'other-bank']);
        Entity::create([
            'organization_id' => $otherOrg->id,
            'entity_type_id' => $this->type->id,
            'entity_code' => 'ENT-FOREIGN',
            'name' => 'Their Entity',
            'status' => 'active',
            'level' => 2,
        ]);

        $this->get(route('risk.scoping.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.entity_code.text', $mine->entity_code)
                ->where('grid.rows.data.0.cells.name.text', 'Our Branch'));
    }
}

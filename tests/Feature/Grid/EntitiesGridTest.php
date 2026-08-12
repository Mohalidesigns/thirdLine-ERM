<?php

namespace Tests\Feature\Grid;

use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
            ->assertSee('Entity Register')
            ->assertSee($entity->entity_code)
            ->assertSee('Lagos Island Branch');
    }

    #[Test]
    public function grid_search_narrows_server_side(): void
    {
        // Entity codes distinguish rows: names also appear in the parent_id
        // filter dropdown, so a name cannot carry a DontSee.
        $this->makeEntity(['name' => 'Lagos Operations Hub', 'entity_code' => 'ENT-LAG']);
        $this->makeEntity(['name' => 'Abuja Treasury Desk', 'entity_code' => 'ENT-ABJ']);

        Livewire::test('data-grid', ['grid' => 'entities'])
            ->assertSee('ENT-LAG')
            ->assertSee('ENT-ABJ')
            ->set('search', 'Lagos')
            ->assertSee('ENT-LAG')
            ->assertDontSee('ENT-ABJ');
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

        Livewire::test('data-grid', ['grid' => 'entities'])
            ->assertSee($mine->entity_code)
            ->assertDontSee('ENT-FOREIGN')
            ->assertDontSee('Their Entity');
    }
}

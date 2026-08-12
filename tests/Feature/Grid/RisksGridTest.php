<?php

namespace Tests\Feature\Grid;

use App\Models\Organization;
use App\Models\Risk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-09 — the risk register on the shared data grid
 * (App\Grids\Definitions\RisksGrid).
 */
class RisksGridTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('risk.view');
        $this->actor->givePermissionTo('risk.view');

        $this->actingAs($this->actor);
    }

    #[Test]
    public function the_register_index_page_renders_a_seeded_row(): void
    {
        $this->makeRisk(['title' => 'Vendor concentration exposure']);

        $this->get('/risk/register')
            ->assertOk()
            ->assertSee('Risk Register')
            ->assertSee('Vendor concentration exposure');
    }

    #[Test]
    public function search_narrows_the_grid_server_side(): void
    {
        $this->makeRisk(['title' => 'Vendor concentration exposure']);
        $this->makeRisk(['title' => 'Data centre outage']);

        Livewire::test('data-grid', ['grid' => 'risks'])
            ->assertSee('Vendor concentration exposure')
            ->assertSee('Data centre outage')
            ->set('search', 'Vendor')
            ->assertSee('Vendor concentration exposure')
            ->assertDontSee('Data centre outage');
    }

    #[Test]
    public function another_organizations_risks_never_render(): void
    {
        $this->makeRisk(['title' => 'Our own risk']);

        $otherOrg = Organization::create(['name' => 'Other Bank', 'slug' => 'other-bank']);
        Risk::create([
            'organization_id' => $otherOrg->id,
            'risk_code' => 'RK-FOREIGN-0001',
            'title' => 'Their foreign risk',
            'description' => 'Belongs to another tenant',
            'category_id' => $this->category->id,
            'status' => 'active',
            'created_by' => $this->actor->id,
        ]);

        Livewire::test('data-grid', ['grid' => 'risks'])
            ->assertSee('Our own risk')
            ->assertDontSee('Their foreign risk');
    }
}

<?php

namespace Tests\Feature\Grid;

use App\Models\Organization;
use App\Models\Risk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

        $this->get(route('risk.register.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Register/Index')
                ->where('total', 1)
                ->has('ratingCounts', 4)
                ->where('grid.name', 'risks')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Vendor concentration exposure'));

        $this->assertFileDoesNotExist(resource_path('views/risk/register/index.blade.php'));
        $this->assertFileExists(resource_path('views/risk/register/historic.blade.php'));
    }

    #[Test]
    public function search_narrows_the_grid_server_side(): void
    {
        $this->makeRisk(['title' => 'Vendor concentration exposure']);
        $this->makeRisk(['title' => 'Data centre outage']);

        $this->get(route('risk.register.index'))
            ->assertInertia(fn (Assert $page) => $page->has('grid.rows.data', 2));

        $this->get(route('risk.register.index', ['search' => 'Vendor']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Vendor concentration exposure'));
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

        $this->get(route('risk.register.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Our own risk'));
    }
}

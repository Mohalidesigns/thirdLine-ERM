<?php

namespace Tests\Feature\Grid;

use App\Models\KeyRiskIndicator;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-09 — the KRI library on the shared data grid
 * (App\Grids\Definitions\KrisGrid).
 */
class KrisGridTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['kri.view', 'kri.edit'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['kri.view', 'kri.edit']);

        $this->actingAs($this->actor);
    }

    private function makeIndicator(array $attributes = []): KeyRiskIndicator
    {
        static $sequence = 0;
        $sequence++;

        return KeyRiskIndicator::create(array_merge([
            'organization_id' => $this->organization->id,
            'kri_code' => sprintf('KRI-GRID-%03d', $sequence),
            'name' => "Indicator {$sequence}",
            'description' => 'Fixture indicator',
            'metric_formula' => 'count of events',
            'data_source' => 'core banking',
            'measurement_frequency' => 'monthly',
            'unit_of_measure' => 'pct',
            'threshold_direction' => 'higher_worse',
            'green_threshold_max' => 5,
            'amber_threshold_min' => 5,
            'amber_threshold_max' => 10,
            'red_threshold_min' => 10,
            'current_status' => 'green',
            'owner_id' => $this->actor->id,
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    #[Test]
    public function the_index_page_renders_the_grid_with_a_seeded_row(): void
    {
        $this->makeIndicator(['name' => 'System downtime hours']);

        $this->get(route('risk.kri.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Kri/Index')
                ->where('total', 1)
                ->where('activeBreachCount', 0)
                ->where('grid.name', 'kris')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.name.text', 'System downtime hours'));
    }

    #[Test]
    public function grid_search_narrows_server_side(): void
    {
        $this->makeIndicator(['name' => 'Failed transaction rate']);
        $this->makeIndicator(['name' => 'Staff attrition ratio']);

        $this->get(route('risk.kri.index'))
            ->assertInertia(fn (Assert $page) => $page->has('grid.rows.data', 2));

        $this->get(route('risk.kri.index', ['search' => 'attrition']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.name.text', 'Staff attrition ratio'));
    }

    #[Test]
    public function another_organizations_kris_never_render(): void
    {
        $this->makeIndicator(['name' => 'Our indicator']);

        $otherOrg = Organization::create(['name' => 'Other Bank', 'slug' => 'other-bank']);
        KeyRiskIndicator::create([
            'organization_id' => $otherOrg->id,
            'kri_code' => 'KRI-FOREIGN-001',
            'name' => 'Their indicator',
            'description' => 'Belongs to another tenant.',
            'metric_formula' => 'count of events',
            'data_source' => 'core banking',
            'measurement_frequency' => 'monthly',
            'unit_of_measure' => 'pct',
            'threshold_direction' => 'higher_worse',
            'created_by' => $this->actor->id,
        ]);

        $this->get(route('risk.kri.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.name.text', 'Our indicator'));
    }
}

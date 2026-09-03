<?php

namespace Tests\Feature\Grid;

use App\Models\NearMiss;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-09 — the near-miss register on the shared data grid
 * (App\Grids\Definitions\NearMissesGrid).
 */
class NearMissesGridTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('loss_event.view');
        $this->actor->givePermissionTo('loss_event.view');

        $this->actingAs($this->actor);
    }

    private function makeNearMiss(array $attributes = []): NearMiss
    {
        static $sequence = 0;
        $sequence++;

        return NearMiss::create(array_merge([
            'organization_id' => $this->organization->id,
            'reference' => sprintf('NM-TEST-%03d', $sequence),
            'title' => "Near miss {$sequence}",
            'description' => 'Fixture near miss',
            'date_occurred' => now()->subDays(3)->toDateString(),
            'date_reported' => now()->toDateString(),
            'severity' => 'medium',
            'status' => 'open',
            'reported_by' => $this->actor->id,
        ], $attributes));
    }

    #[Test]
    public function the_index_page_renders_a_seeded_near_miss(): void
    {
        $nearMiss = $this->makeNearMiss(['title' => 'Server room flood averted']);

        $this->get(route('risk.loss-events.near-misses'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('LossEvents/NearMisses')
                ->where('totalNearMisses', 1)
                ->where('openNearMisses', 1)
                ->where('grid.name', 'near_misses')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.reference.text', $nearMiss->reference)
                ->where('grid.rows.data.0.cells.title.text', 'Server room flood averted'));
    }

    #[Test]
    public function grid_search_narrows_server_side(): void
    {
        $flood = $this->makeNearMiss(['title' => 'Server room flood averted']);
        $this->makeNearMiss(['title' => 'Cash counting mismatch caught']);

        $this->get(route('risk.loss-events.near-misses'))
            ->assertInertia(fn (Assert $page) => $page->has('grid.rows.data', 2));

        $this->get(route('risk.loss-events.near-misses', ['search' => 'flood']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.reference.text', $flood->reference));
    }

    #[Test]
    public function another_organizations_near_misses_never_render(): void
    {
        $mine = $this->makeNearMiss(['title' => 'Our near miss']);

        $otherOrg = Organization::create(['name' => 'Other Bank', 'slug' => 'other-bank']);
        NearMiss::create([
            'organization_id' => $otherOrg->id,
            'reference' => 'NM-FOREIGN',
            'title' => 'Their near miss',
            'description' => 'Foreign fixture near miss',
            'date_occurred' => now()->subDays(3)->toDateString(),
            'date_reported' => now()->toDateString(),
            'severity' => 'high',
            'status' => 'open',
            'reported_by' => $this->actor->id,
        ]);

        $this->get(route('risk.loss-events.near-misses'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.reference.text', $mine->reference)
                ->where('grid.rows.data.0.cells.title.text', 'Our near miss'));
    }
}

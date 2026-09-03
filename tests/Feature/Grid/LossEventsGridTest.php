<?php

namespace Tests\Feature\Grid;

use App\Models\LossEvent;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-09 — the loss event register on the shared data grid
 * (App\Grids\Definitions\LossEventsGrid).
 */
class LossEventsGridTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['loss_event.view', 'loss_event.edit'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['loss_event.view', 'loss_event.edit']);

        $this->actingAs($this->actor);
    }

    #[Test]
    public function the_index_page_renders_the_grid_with_a_seeded_row(): void
    {
        $this->makeLossEvent(['title' => 'ATM cash-out fraud incident']);

        $this->get(route('risk.loss-events.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('LossEvents/Index')
                ->where('total', 1)
                ->where('grid.name', 'loss_events')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'ATM cash-out fraud incident'));
    }

    #[Test]
    public function grid_search_narrows_server_side(): void
    {
        $this->makeLossEvent(['title' => 'Wire transfer chargeback']);
        $this->makeLossEvent(['title' => 'Vault shortage discovery']);

        $this->get(route('risk.loss-events.index'))
            ->assertInertia(fn (Assert $page) => $page->has('grid.rows.data', 2));

        $this->get(route('risk.loss-events.index', ['search' => 'Vault shortage']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Vault shortage discovery'));
    }

    #[Test]
    public function another_organizations_loss_events_never_render(): void
    {
        $this->makeLossEvent(['title' => 'Our operational loss']);

        $otherOrg = Organization::create(['name' => 'Other Bank', 'slug' => 'other-bank']);
        LossEvent::create([
            'organization_id' => $otherOrg->id,
            'event_reference' => 'LE-FOREIGN-001',
            'title' => 'Their operational loss',
            'description' => 'Belongs to another tenant.',
            'date_of_loss' => now()->subDays(3)->toDateString(),
            'date_discovered' => now()->subDay()->toDateString(),
            'date_reported' => now()->toDateString(),
            'basel_l1_category' => 'EXTERNAL_FRAUD',
            'basel_l2_category' => 'THEFT_AND_FRAUD',
            'cbn_risk_category' => 'FRAUD_RISK',
            'gross_loss_amount_kobo' => 500000,
            'loss_category' => 'actual_loss',
            'event_severity' => 'MAJOR',
            'current_status' => 'REPORTED',
            'created_by' => $this->actor->id,
        ]);

        $this->get(route('risk.loss-events.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Our operational loss'));
    }
}

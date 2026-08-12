<?php

namespace Tests\Feature\Grid;

use App\Models\LossEvent;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

        $this->get('/risk/loss-events')
            ->assertOk()
            ->assertSee('Loss Event Register')
            ->assertSee('ATM cash-out fraud incident');
    }

    #[Test]
    public function grid_search_narrows_server_side(): void
    {
        $this->makeLossEvent(['title' => 'Wire transfer chargeback']);
        $this->makeLossEvent(['title' => 'Vault shortage discovery']);

        Livewire::test('data-grid', ['grid' => 'loss_events'])
            ->assertSee('Wire transfer chargeback')
            ->assertSee('Vault shortage discovery')
            ->set('search', 'Vault shortage')
            ->assertSee('Vault shortage discovery')
            ->assertDontSee('Wire transfer chargeback');
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

        Livewire::test('data-grid', ['grid' => 'loss_events'])
            ->assertSee('Our operational loss')
            ->assertDontSee('Their operational loss')
            ->assertDontSee('LE-FOREIGN-001');
    }
}

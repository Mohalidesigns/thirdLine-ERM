<?php

namespace Tests\Feature\Grid;

use App\Models\Organization;
use App\Models\TreatmentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-09 — treatment plans on the shared data grid
 * (App\Grids\Definitions\TreatmentPlansGrid).
 */
class TreatmentPlansGridTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['treatment.view', 'treatment.delete'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['treatment.view', 'treatment.delete']);

        $this->actingAs($this->actor);
    }

    private function makePlan(array $attributes = []): TreatmentPlan
    {
        static $sequence = 0;
        $sequence++;

        return TreatmentPlan::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_id' => $this->makeRisk()->id,
            'strategy' => 'mitigate',
            'action_title' => "Treatment plan {$sequence}",
            'action_description' => "Fixture treatment plan {$sequence}",
            'owner_id' => $this->actor->id,
            'target_date' => now()->addDays(60),
            'priority' => 'high',
            'status' => 'in_progress',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    #[Test]
    public function the_treatments_index_page_renders_a_seeded_row(): void
    {
        $this->makePlan(['action_title' => 'Deploy EDR agents']);

        $this->get('/risk/treatments')
            ->assertOk()
            ->assertSee('Treatment Plans')
            ->assertSee('Deploy EDR agents');
    }

    #[Test]
    public function search_narrows_the_grid_server_side(): void
    {
        $this->makePlan(['action_title' => 'Deploy EDR agents']);
        $this->makePlan(['action_title' => 'Revise credit limits']);

        Livewire::test('data-grid', ['grid' => 'treatments'])
            ->assertSee('Deploy EDR agents')
            ->assertSee('Revise credit limits')
            ->set('search', 'EDR')
            ->assertSee('Deploy EDR agents')
            ->assertDontSee('Revise credit limits');
    }

    #[Test]
    public function another_organizations_plans_never_render(): void
    {
        $this->makePlan(['action_title' => 'Our own plan']);

        $otherOrg = Organization::create(['name' => 'Other Bank', 'slug' => 'other-bank']);
        TreatmentPlan::create([
            'organization_id' => $otherOrg->id,
            'risk_id' => $this->makeRisk()->id,
            'strategy' => 'mitigate',
            'action_title' => 'Their foreign plan',
            'action_description' => 'Belongs to another tenant',
            'owner_id' => $this->actor->id,
            'target_date' => now()->addDays(60),
            'priority' => 'high',
            'status' => 'in_progress',
            'created_by' => $this->actor->id,
        ]);

        Livewire::test('data-grid', ['grid' => 'treatments'])
            ->assertSee('Our own plan')
            ->assertDontSee('Their foreign plan');
    }
}

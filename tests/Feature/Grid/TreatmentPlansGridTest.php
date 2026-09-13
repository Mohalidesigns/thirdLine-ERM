<?php

namespace Tests\Feature\Grid;

use App\Models\Organization;
use App\Models\TreatmentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

        $this->get(route('risk.treatments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Treatments/Index')
                ->where('total', 1)
                ->where('grid.name', 'treatments')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Deploy EDR agents'));
    }

    #[Test]
    public function search_narrows_the_grid_server_side(): void
    {
        $this->makePlan(['action_title' => 'Deploy EDR agents']);
        $this->makePlan(['action_title' => 'Revise credit limits']);

        $this->get(route('risk.treatments.index'))
            ->assertInertia(fn (Assert $page) => $page->has('grid.rows.data', 2));

        $this->get(route('risk.treatments.index', ['search' => 'EDR']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Deploy EDR agents'));
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

        $this->get(route('risk.treatments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Our own plan'));
    }
}

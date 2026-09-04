<?php

namespace Tests\Feature\Workflow;

use App\Models\RiskAssessment;
use App\Models\User;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesWorkflowFixtures;
use Tests\TestCase;

/**
 * Every workflow screen renders with real engine data.
 *
 * Worth its own file because the pre-WP-06 views read `stages` and compared
 * `status` to a string — both of which the engine changed underneath them, and
 * neither of which any test would have caught: a Blade that silently renders
 * an empty progress bar returns 200 just as happily as one that works.
 */
class WorkflowScreensTest extends TestCase
{
    use CreatesDomainFixtures, CreatesWorkflowFixtures, RefreshDatabase;

    private User $riskManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();
        $this->actor->assignRole('super-admin');

        $this->riskManager = User::create([
            'name' => 'Risk Manager',
            'email' => 'rm@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $this->riskManager->assignRole('risk-manager');
    }

    #[Test]
    public function the_dashboard_renders_with_a_running_instance(): void
    {
        $this->startReview();

        $this->actingAs($this->actor)
            ->get(route('risk.workflows.dashboard'))
            ->assertOk()
            // Migration Phase 3.7: the page is an Inertia component; the same
            // facts are read from its props. The waiting-on column names the
            // role the step was offered to, which the old "3 of 5" stage
            // counter could not express.
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Workflows/Dashboard')
                ->where('recentInstances.0.name', 'Linear review')
                ->has('stats.overdue')
                ->where('recentInstances.0.waiting_on.0.who', 'any risk-manager'));
    }

    #[Test]
    public function the_definitions_screen_lists_the_shipped_processes(): void
    {
        app(WorkflowPublisher::class)->provision($this->organization->id);

        $this->actingAs($this->actor)
            ->get(route('risk.workflows.definitions'))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Workflows/Definitions')
                ->where('definitions.data', fn ($rows) => collect($rows)->contains(
                    fn ($row) => $row['name'] === 'Loss event approval' && $row['is_system'] === true && $row['is_published'] === true
                )));
    }

    #[Test]
    public function the_instance_screen_shows_the_steps_and_the_actions(): void
    {
        $instance = $this->startReview();

        $this->actingAs($this->riskManager)
            ->get(route('risk.workflows.show-instance', $instance))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Workflows/ShowInstance')
                ->where('steps', fn ($steps) => collect($steps)->contains(
                    fn ($s) => $s['name'] === 'Review' && ($s['task']['who'] ?? null) === 'offered to risk-manager'
                ))
                // The action block (Approve / Reject / Return for rework) renders when canAct is true.
                ->where('canAct', true));
    }

    #[Test]
    public function the_instance_screen_hides_the_actions_from_somebody_who_cannot_act(): void
    {
        $instance = $this->startReview();

        // A CRO: allowed to SEE any workflow, and would pass the
        // approve-risk-assessment gate — but this step was offered to the
        // risk-manager role, so the decision is not theirs to record.
        $cro = User::create([
            'name' => 'Chief Risk Officer',
            'email' => 'cro@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $cro->assignRole('chief-risk-officer');

        $this->actingAs($cro)
            ->get(route('risk.workflows.show-instance', $instance))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('instance.open', true)
                ->where('canAct', false)
                ->where('actionable', []));
    }

    #[Test]
    public function the_designer_screen_renders(): void
    {
        $this->actingAs($this->actor)
            ->get(route('risk.workflows.create-definition'))
            ->assertOk()
            ->assertSee('Workflow designer');
    }

    #[Test]
    public function an_instance_from_another_tenant_is_not_reachable(): void
    {
        $instance = $this->startReview();

        $otherOrg = \App\Support\Tenancy\TenantContext::bypass(fn () => \App\Models\Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHR',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]), 'test fixture');

        $stranger = \App\Support\Tenancy\TenantContext::bypass(fn () => User::create([
            'name' => 'Stranger',
            'email' => 'stranger@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $otherOrg->id,
            'is_active' => true,
        ]), 'test fixture');

        $stranger->assignRole('super-admin');

        $this->actingAs($stranger)
            ->get(route('risk.workflows.show-instance', $instance))
            ->assertNotFound();
    }

    private function startReview(): \App\Models\WorkflowInstance
    {
        $this->linearDefinition();

        $assessment = RiskAssessment::create([
            'organization_id' => $this->organization->id,
            'risk_id' => $this->makeRisk()->id,
            'assessment_date' => now()->toDateString(),
            'assessment_type' => 'periodic',
            'likelihood_score' => 3,
            'impact_financial' => 4,
            'impact_score' => 4,
            'overall_score' => 12,
            'overall_rating' => 'High',
            'status' => 'draft',
            'assessor_id' => $this->actor->id,
        ]);

        return app(WorkflowEngine::class)->startFor('linear_review', $assessment, [], $this->actor);
    }
}

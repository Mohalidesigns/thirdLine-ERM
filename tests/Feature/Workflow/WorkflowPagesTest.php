<?php

namespace Tests\Feature\Workflow;

use App\Models\ApprovalRequest;
use App\Models\Organization;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesWorkflowFixtures;
use Tests\TestCase;

/**
 * Migration Phase 3.7 — the approvals, my-tasks and workflow pages as
 * Inertia components, their policies and their Form Requests.
 */
class WorkflowPagesTest extends TestCase
{
    use CreatesDomainFixtures, CreatesWorkflowFixtures, RefreshDatabase;

    private User $riskManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();
        $this->actor->assignRole('super-admin');
        $this->riskManager = $this->makeUser('rm@example.test', 'risk-manager');
        $this->linearDefinition();
    }

    #[Test]
    public function the_policies_are_discovered(): void
    {
        $this->assertInstanceOf(\App\Policies\WorkflowTaskPolicy::class, Gate::getPolicyFor(WorkflowTask::class));
        $this->assertInstanceOf(\App\Policies\WorkflowInstancePolicy::class, Gate::getPolicyFor(WorkflowInstance::class));
        $this->assertInstanceOf(\App\Policies\WorkflowDefinitionPolicy::class, Gate::getPolicyFor(WorkflowDefinition::class));
        $this->assertInstanceOf(\App\Policies\ApprovalRequestPolicy::class, Gate::getPolicyFor(ApprovalRequest::class));
    }

    #[Test]
    public function my_tasks_index_carries_the_queue_and_the_counters(): void
    {
        $this->startReview();

        $this->actingAs($this->riskManager)->get(route('risk.my-tasks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MyTasks/Index')
                ->has('tasks.data', 1)
                ->where('tasks.data.0.name', 'Review')
                ->where('tasks.data.0.process', 'Linear review')
                ->where('tasks.data.0.subject.reference', fn ($r) => str_starts_with((string) $r, 'ASS-') || str_contains((string) $r, '#'))
                ->has('tasks.links')
                ->where('counts.open', 1)
                ->where('counts.overdue', 0));
    }

    #[Test]
    public function my_task_show_offers_the_decision_only_to_a_holder(): void
    {
        $instance = $this->startReview();
        $task = $instance->openTasks()->first();

        $this->actingAs($this->riskManager)->get(route('risk.my-tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MyTasks/Show')
                ->where('task.id', $task->id)
                ->where('canAct', true)
                ->where('task.allow_delegate', true)
                ->has('delegates')
                ->has('steps', 1)
                ->where('steps.0.who', 'offered to risk-manager')
                ->has('history')
                ->has('urls.act'));

        $analyst = $this->makeUser('analyst@example.test', 'risk-analyst');

        $this->actingAs($analyst)->get(route('risk.my-tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canAct', false)->where('delegates', []));
    }

    #[Test]
    public function delegation_rejects_a_colleague_from_another_tenant(): void
    {
        $instance = $this->startReview();
        $task = $instance->openTasks()->first();

        $other = Organization::create(['name' => 'Other Bank', 'short_name' => 'OTHR', 'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true]);
        $stranger = User::withoutGlobalScopes()->create(['name' => 'Stranger', 'email' => 'stranger@example.test', 'password' => Hash::make('password'), 'organization_id' => $other->id, 'is_active' => true]);

        $this->actingAs($this->riskManager)
            ->from(route('risk.my-tasks.show', $task))
            ->post(route('risk.my-tasks.delegate', $task), ['delegate_to' => $stranger->id, 'reason' => 'Out of office'])
            ->assertSessionHasErrors('delegate_to');

        $this->assertNull($task->fresh()->delegated_to);
    }

    #[Test]
    public function delegation_works_for_a_colleague_in_the_tenant(): void
    {
        $instance = $this->startReview();
        $task = $instance->openTasks()->first();
        $cro = $this->makeUser('cro@example.test', 'chief-risk-officer');

        $this->actingAs($this->riskManager)
            ->post(route('risk.my-tasks.delegate', $task), ['delegate_to' => $cro->id, 'reason' => 'Out of office'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($cro->id, $task->fresh()->delegated_to);
    }

    #[Test]
    public function the_workflow_dashboard_and_definitions_pages_render_their_props(): void
    {
        $this->startReview();

        $this->actingAs($this->actor)->get(route('risk.workflows.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Workflows/Dashboard')
                ->has('stats', fn (Assert $stats) => $stats->where('running', 1)->has('overdue')->has('escalated')->has('published')->etc())
                ->has('recentInstances', 1)
                ->where('recentInstances.0.name', 'Linear review')
                ->where('recentInstances.0.waiting_on.0.who', 'any risk-manager')
                ->has('workload')
                ->has('urls.myTasks'));

        $this->actingAs($this->actor)->get(route('risk.workflows.definitions'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Workflows/Definitions')
                ->has('definitions.data', 1)
                ->where('definitions.data.0.name', 'Linear review')
                ->where('definitions.data.0.is_published', true)
                ->has('definitions.data.0.start_options')
                ->where('canManage', true));
    }

    #[Test]
    public function the_instance_page_carries_steps_history_and_the_actionable_flag(): void
    {
        $instance = $this->startReview();

        $this->actingAs($this->riskManager)->get(route('risk.workflows.show-instance', $instance))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Workflows/ShowInstance')
                ->where('instance.id', $instance->id)
                ->where('instance.open', true)
                ->where('steps', fn ($steps) => collect($steps)->contains(fn ($s) => $s['name'] === 'Review' && ($s['task']['who'] ?? null) === 'offered to risk-manager'))
                ->where('canAct', true)
                ->where('actionable', ['Review'])
                ->has('history'));
    }

    #[Test]
    public function the_approvals_dashboard_groups_pending_requests_and_acts_on_them(): void
    {
        $assessment = $this->assessment();
        $approval = ApprovalRequest::create([
            'organization_id' => $this->organization->id,
            'entity_type' => 'risk_assessment',
            'entity_id' => $assessment->id,
            'action' => 'approve',
            'status' => 'pending',
            'requested_by' => $this->actor->id,
            'requested_at' => now(),
        ]);

        $this->actingAs($this->actor)->get(route('risk.approvals.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Approvals/Dashboard')
                ->where('stats.pending', 1)
                ->has('groups', 1)
                ->where('groups.0.entity_type', 'risk_assessment')
                ->where('groups.0.items.0.id', $approval->id)
                ->has('groups.0.items.0.urls.approve')
                ->where('canAct', true));

        $this->actingAs($this->actor)
            ->from(route('risk.approvals.dashboard'))
            ->post(route('risk.approvals.reject', $approval), [])
            ->assertSessionHasErrors('rejection_reason');

        $this->actingAs($this->actor)
            ->post(route('risk.approvals.approve', $approval), ['comments' => 'Fine'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('approved', $approval->fresh()->status);
    }

    #[Test]
    public function every_workflow_blade_view_is_gone(): void
    {
        // The designer was the exception here until migration Phase 6.5: it
        // stayed on Blade because it was a Livewire screen, and it was the last
        // one in the product.
        foreach ([
            'risk/approvals/dashboard', 'risk/my-tasks/index', 'risk/my-tasks/show',
            'risk/workflows/dashboard', 'risk/workflows/definitions', 'risk/workflows/show-instance',
            'risk/workflows/designer',
        ] as $view) {
            $this->assertFileDoesNotExist(resource_path("views/{$view}.blade.php"));
        }
    }

    private function assessment(): RiskAssessment
    {
        return RiskAssessment::create([
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
    }

    private function startReview(): WorkflowInstance
    {
        return app(WorkflowEngine::class)->startFor('linear_review', $this->assessment(), [], $this->actor);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::create([
            'name' => ucfirst(strtok($email, '@')),
            'email' => $email,
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}

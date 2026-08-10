<?php

namespace Tests\Feature\Workflow;

use App\Enums\WorkflowTaskStatus;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Services\Workflow\TaskQueryService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesWorkflowFixtures;
use Tests\TestCase;

/**
 * WP-06 TASK 5 acceptance: one indexed query returns every open decision a
 * person owes, whatever module raised it, with overdue counts.
 *
 * Before this the answer was spread across approval_requests, four sets of
 * per-module status columns and a workflow_instances cursor with no assignee —
 * so "what do I owe" meant opening six screens and knowing which six.
 */
class MyTasksTest extends TestCase
{
    use CreatesDomainFixtures, CreatesWorkflowFixtures, RefreshDatabase;

    private WorkflowEngine $engine;

    private TaskQueryService $tasks;

    private User $riskManager;

    private User $cro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();

        $this->engine = app(WorkflowEngine::class);
        $this->tasks = app(TaskQueryService::class);

        $this->riskManager = $this->makeUser('rm@example.test', 'risk-manager');
        $this->cro = $this->makeUser('cro@example.test', 'chief-risk-officer');

        $this->linearDefinition();
    }

    #[Test]
    public function a_role_offer_appears_on_every_holders_queue(): void
    {
        $this->startReview();

        $this->assertCount(1, $this->tasks->openFor($this->riskManager));
        $this->assertCount(0, $this->tasks->openFor($this->cro), 'A task offered to risk-manager is not the CRO\'s.');
    }

    #[Test]
    public function the_counters_separate_overdue_from_merely_open(): void
    {
        $onTime = $this->startReview();
        $late = $this->startReview();

        $late->openTasks()->first()->forceFill(['due_at' => now()->subDays(2)])->save();

        $counts = $this->tasks->countsFor($this->riskManager);

        $this->assertSame(2, $counts['open']);
        $this->assertSame(1, $counts['overdue']);
        $this->assertSame(0, $counts['escalated']);
    }

    #[Test]
    public function a_delegated_task_moves_between_queues(): void
    {
        $instance = $this->startReview();
        $task = $instance->openTasks()->first();

        $task->forceFill(['assignee_id' => $this->riskManager->id])->save();

        $this->engine->delegate($task, $this->cro, 'Away this week.', $this->riskManager);

        $this->assertCount(0, $this->tasks->openFor($this->riskManager), 'It is off the delegator\'s list.');
        $this->assertCount(1, $this->tasks->openFor($this->cro), 'It is on the delegate\'s list.');
        $this->assertSame(1, $this->tasks->countsFor($this->cro)['delegated_to_me']);
    }

    #[Test]
    public function the_screen_lists_a_users_tasks(): void
    {
        $this->startReview();

        $this->actingAs($this->riskManager)
            ->get(route('risk.my-tasks.index'))
            ->assertOk()
            ->assertSee('Review');
    }

    #[Test]
    public function a_user_can_decide_from_the_screen(): void
    {
        $instance = $this->startReview();
        $task = $instance->openTasks()->first();

        $this->actingAs($this->riskManager)
            ->post(route('risk.my-tasks.act', $task), [
                'outcome' => 'approve',
                'comments' => 'Looks right.',
            ])
            ->assertRedirect(route('risk.my-tasks.index'));

        $this->assertSame(WorkflowTaskStatus::Completed, $task->fresh()->status);
        $this->assertSame($this->riskManager->id, $task->fresh()->completed_by);
    }

    #[Test]
    public function a_user_the_task_is_not_offered_to_is_refused(): void
    {
        $instance = $this->startReview();
        $task = $instance->openTasks()->first();

        $analyst = $this->makeUser('analyst@example.test', 'risk-analyst');

        $this->actingAs($analyst)
            ->post(route('risk.my-tasks.act', $task), ['outcome' => 'approve'])
            ->assertForbidden();

        $this->assertTrue($task->fresh()->isOpen());
    }

    #[Test]
    public function a_task_from_another_tenant_is_invisible(): void
    {
        $instance = $this->startReview();
        $task = $instance->openTasks()->first();

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

        $stranger->assignRole('risk-manager');

        // 404, not 403: the tenancy global scope means route model binding
        // never resolves another organization's task in the first place, so the
        // response does not even confirm that the id exists. The controller's
        // explicit 403 is the second line of defence behind it.
        $this->actingAs($stranger)
            ->get(route('risk.my-tasks.show', $task))
            ->assertNotFound();

        $this->assertCount(0, $this->tasks->openFor($stranger));
    }

    #[Test]
    public function the_workload_view_counts_open_and_overdue_per_assignee(): void
    {
        $instance = $this->startReview();
        $instance->openTasks()->first()->forceFill([
            'assignee_id' => $this->riskManager->id,
            'due_at' => now()->subDay(),
        ])->save();

        $workload = $this->tasks->workloadFor($this->organization->id);

        $this->assertCount(1, $workload);
        $this->assertSame(1, (int) $workload->first()->open_count);
        $this->assertSame(1, (int) $workload->first()->overdue_count);
    }

    /* ================================================================== */

    private function startReview(): \App\Models\WorkflowInstance
    {
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

        return $this->engine->startFor('linear_review', $assessment, [], $this->actor);
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

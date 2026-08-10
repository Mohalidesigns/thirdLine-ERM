<?php

namespace Tests\Feature\Workflow;

use App\Enums\WorkflowInstanceStatus;
use App\Enums\WorkflowTaskStatus;
use App\Models\ApprovalRequest;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Models\WorkflowTask;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesWorkflowFixtures;
use Tests\TestCase;

/**
 * WP-06 acceptance.
 *
 * Four of these exist because the ENGINE THEY REPLACE did all four as log
 * lines: delegate, escalate and return wrote a workflow_actions row and changed
 * nothing, and a parallel branch could not be represented at all. Each test
 * asserts the state actually moved, not that an action was recorded.
 */
class WorkflowEngineTest extends TestCase
{
    use CreatesDomainFixtures, CreatesWorkflowFixtures, RefreshDatabase;

    private WorkflowEngine $engine;

    private User $riskManager;

    private User $cro;

    private User $complianceOfficer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();

        $this->engine = app(WorkflowEngine::class);

        $this->riskManager = $this->makeUser('rm@example.test', 'risk-manager');
        $this->cro = $this->makeUser('cro@example.test', 'chief-risk-officer');
        $this->complianceOfficer = $this->makeUser('co@example.test', 'compliance-officer');
    }

    /* ================================================================== */

    #[Test]
    public function it_starts_an_instance_and_raises_a_task_for_the_role(): void
    {
        $this->linearDefinition();
        $assessment = $this->makeAssessment();

        $instance = $this->engine->startFor('linear_review', $assessment, [], $this->actor);

        $this->assertNotNull($instance);
        $this->assertSame(['review'], $instance->currentNodeCodes());

        $task = $instance->openTasks()->first();

        $this->assertNotNull($task, 'Reaching a human node must create a task somebody owns.');
        $this->assertSame('review', $task->node_code);
        $this->assertNull($task->assignee_id, 'A role offer is unclaimed until somebody acts.');
        $this->assertSame('risk-manager', $task->assignee_role);
        $this->assertNotNull($task->due_at, 'A node with an SLA must produce a due date.');

        // The subject's own status column moves too, so the register keeps
        // reading correctly for one release.
        $this->assertSame('in_review', $assessment->fresh()->status);
    }

    #[Test]
    public function approving_the_last_step_finishes_the_instance_and_updates_the_subject(): void
    {
        $this->linearDefinition();
        $assessment = $this->makeAssessment(['overall_score' => 12, 'overall_rating' => 'High']);

        $instance = $this->engine->startFor('linear_review', $assessment, [], $this->actor);
        $task = $instance->openTasks()->first();

        $this->engine->advance($task, 'approve', ['comments' => 'Fine.'], $this->riskManager);

        $instance->refresh();

        $this->assertSame(WorkflowInstanceStatus::Completed, $instance->status);
        $this->assertSame('approved', $instance->outcome);
        $this->assertSame([], $instance->currentNodeCodes());

        $assessment->refresh();
        $this->assertSame('approved', $assessment->status);
        $this->assertSame($this->riskManager->id, $assessment->approved_by);

        // The scores reach the parent risk, which is the domain work that used
        // to sit in the controller and now happens however the decision came in.
        $this->assertSame(12, (int) $assessment->risk->fresh()->inherent_score);
    }

    #[Test]
    public function a_rejection_takes_the_rejection_edge(): void
    {
        $this->linearDefinition();
        $assessment = $this->makeAssessment();

        $instance = $this->engine->startFor('linear_review', $assessment, [], $this->actor);

        $this->engine->advance($instance->openTasks()->first(), 'reject', ['comments' => 'Evidence missing.'], $this->riskManager);

        $this->assertSame(WorkflowInstanceStatus::Rejected, $instance->fresh()->status);
        $this->assertSame('rejected', $assessment->fresh()->status);
    }

    /* ================================================================== */
    /*  Parallel execution */
    /* ================================================================== */

    #[Test]
    public function a_parallel_gateway_forks_into_two_open_tasks(): void
    {
        $this->parallelDefinition();
        $event = $this->makeLossEvent();

        $instance = $this->engine->startFor('parallel_review', $event, [], $this->actor);

        $this->assertEqualsCanonicalizing(
            ['risk_review', 'compliance_review'],
            $instance->currentNodeCodes(),
            'A fork must put the instance in both places at once — the shape current_stage could not express.'
        );

        $this->assertCount(2, $instance->openTasks()->get());
    }

    #[Test]
    public function a_join_waits_for_every_branch_before_continuing(): void
    {
        $this->parallelDefinition();
        $event = $this->makeLossEvent();

        $instance = $this->engine->startFor('parallel_review', $event, [], $this->actor);

        $riskTask = $instance->openTasks()->where('node_code', 'risk_review')->first();
        $this->engine->advance($riskTask, 'approve', [], $this->riskManager);

        $instance->refresh();

        $this->assertTrue($instance->isOpen(), 'One branch in is not both branches in.');
        $this->assertSame(
            ['compliance_review', 'join'],
            $instance->currentNodeCodes(),
            'The arrived branch parks on the join; the other keeps running.'
        );

        $complianceTask = $instance->openTasks()->where('node_code', 'compliance_review')->first();
        $this->engine->advance($complianceTask, 'approve', [], $this->complianceOfficer);

        $instance->refresh();

        $this->assertSame(WorkflowInstanceStatus::Completed, $instance->status);
        $this->assertSame('approved', $instance->outcome);
    }

    #[Test]
    public function a_join_emits_exactly_once(): void
    {
        $this->parallelDefinition();
        $event = $this->makeLossEvent();

        $instance = $this->engine->startFor('parallel_review', $event, [], $this->actor);

        foreach ($instance->openTasks()->get() as $task) {
            $this->engine->advance(
                $task->fresh(),
                'approve',
                [],
                $task->node_code === 'risk_review' ? $this->riskManager : $this->complianceOfficer
            );
        }

        // Two branches arriving at a join must not run everything after it
        // twice — on an approval that would be two decisions for one review.
        $completions = $instance->actions()->where('action', 'complete')->count();

        $this->assertSame(1, $completions);
    }

    /* ================================================================== */
    /*  Delegate — must reassign, not log */
    /* ================================================================== */

    #[Test]
    public function delegate_actually_changes_the_assignee(): void
    {
        $this->linearDefinition();
        $assessment = $this->makeAssessment();

        $instance = $this->engine->startFor('linear_review', $assessment, [], $this->actor);
        $task = $instance->openTasks()->first();

        // Claim it first, so there is a real handover to test.
        $task->forceFill(['assignee_id' => $this->riskManager->id])->save();

        $this->engine->delegate($task, $this->cro, 'On leave until Monday.', $this->riskManager);

        $task->refresh();

        $this->assertSame($this->cro->id, $task->assignee_id, 'Delegation that does not move the assignee is a lie in the log.');
        $this->assertSame($this->riskManager->id, $task->delegated_from);
        $this->assertSame(WorkflowTaskStatus::Delegated, $task->status);
        $this->assertTrue($task->isOpen(), 'A delegated task is still owed by somebody.');

        // The role offer is withdrawn, so two people cannot decide one thing.
        $this->assertSame([], $task->candidate_roles);
        $this->assertNull($task->assignee_role);

        $this->assertTrue(
            $this->engine->canAct($task, $this->cro),
            'The delegate must be able to act on what was handed to them.'
        );
    }

    #[Test]
    public function delegation_cannot_cross_an_organization(): void
    {
        $this->linearDefinition();
        $assessment = $this->makeAssessment();

        $instance = $this->engine->startFor('linear_review', $assessment, [], $this->actor);
        $task = $instance->openTasks()->first();

        $otherBank = TenantContext::bypass(fn () => \App\Models\Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHR',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]), 'test fixture');

        $outsider = TenantContext::bypass(fn () => User::create([
            'name' => 'Other Bank Reviewer',
            'email' => 'outsider@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $otherBank->id,
            'is_active' => true,
        ]), 'test fixture');

        $this->expectException(RuntimeException::class);

        $this->engine->delegate($task, $outsider, 'Nope.', $this->riskManager);
    }

    /* ================================================================== */
    /*  Escalation — must reassign, not log */
    /* ================================================================== */

    #[Test]
    public function an_sla_breach_escalates_and_reassigns(): void
    {
        $this->linearDefinition();
        $assessment = $this->makeAssessment();

        $instance = $this->engine->startFor('linear_review', $assessment, [], $this->actor);
        $task = $instance->openTasks()->first();

        $task->forceFill([
            'assignee_id' => $this->riskManager->id,
            'assignee_role' => null,
            'candidate_roles' => [],
            'due_at' => now()->subHours(3),
        ])->save();

        $counts = $this->engine->sweep();

        $this->assertSame(1, $counts['breached']);
        $this->assertSame(1, $counts['escalated']);

        $task->refresh();

        $this->assertSame(WorkflowTaskStatus::Escalated, $task->status);
        $this->assertNotNull($task->escalated_at);
        $this->assertSame(
            'chief-risk-officer',
            $task->assignee_role,
            'Escalation must move the task to the escalation target, not merely record that it was late.'
        );
        $this->assertNull($task->assignee_id, 'The task is now offered to the CRO role, not still held by the person who missed it.');

        $this->assertSame(WorkflowInstanceStatus::Escalated, $instance->fresh()->status);
        $this->assertNotNull($instance->fresh()->breached_at);
    }

    #[Test]
    public function the_sweeper_does_not_escalate_the_same_task_twice(): void
    {
        $this->linearDefinition();
        $assessment = $this->makeAssessment();

        $instance = $this->engine->startFor('linear_review', $assessment, [], $this->actor);
        $instance->openTasks()->first()->forceFill(['due_at' => now()->subHours(3)])->save();

        $this->engine->sweep();
        $second = $this->engine->sweep();

        $this->assertSame(0, $second['escalated'], 'Escalating hourly turns an escalation into noise people filter.');
    }

    #[Test]
    public function a_node_set_to_auto_approve_is_decided_when_it_times_out(): void
    {
        $this->linearDefinition([
            'code' => 'auto_review',
            'definition' => [
                'nodes' => [
                    ['code' => 'start', 'type' => 'start', 'name' => 'Submitted'],
                    [
                        'code' => 'review', 'type' => 'approval', 'name' => 'Review',
                        'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['risk-manager']],
                        'sla_hours' => 1, 'on_timeout' => 'auto_approve',
                    ],
                    ['code' => 'approved', 'type' => 'end', 'name' => 'Approved', 'outcome' => 'approved'],
                ],
                'edges' => [
                    ['from' => 'start', 'to' => 'review'],
                    ['from' => 'review', 'to' => 'approved'],
                ],
            ],
        ]);

        $assessment = $this->makeAssessment();
        $instance = $this->engine->startFor('auto_review', $assessment, [], $this->actor);

        $instance->openTasks()->first()->forceFill(['due_at' => now()->subHours(2)])->save();

        $counts = $this->engine->sweep();

        $this->assertSame(1, $counts['auto_decided']);
        $this->assertSame(WorkflowInstanceStatus::Completed, $instance->fresh()->status);

        // An approval nobody granted must still say so: the actor is null, not
        // whoever the sweeper happened to run as.
        $task = $instance->tasks()->first();
        $this->assertNull($task->completed_by);
        $this->assertSame('approve', $task->outcome);
    }

    /* ================================================================== */
    /*  Return for rework — must move the instance */
    /* ================================================================== */

    #[Test]
    public function return_for_rework_moves_the_instance_back(): void
    {
        $this->twoStepDefinition();
        $assessment = $this->makeAssessment();

        $instance = $this->engine->startFor('two_step_review', $assessment, [], $this->actor);

        $this->engine->advance($instance->openTasks()->first(), 'approve', [], $this->riskManager);
        $instance->refresh();

        $this->assertSame(['second'], $instance->currentNodeCodes());

        $secondTask = $instance->openTasks()->first();
        $this->engine->returnForRework($secondTask, 'first', 'Recalculate the residual score.', $this->cro);

        $instance->refresh();

        $this->assertSame(['first'], $instance->currentNodeCodes(), 'A return that does not move the instance is a comment.');
        $this->assertTrue($instance->isOpen());

        $reopened = $instance->openTasks()->first();
        $this->assertSame('first', $reopened->node_code);
        $this->assertSame('risk-manager', $reopened->assignee_role);

        // The subject goes back to an editable state.
        $this->assertSame('draft', $assessment->fresh()->status);
    }

    #[Test]
    public function a_return_abandons_a_parallel_branch_still_open(): void
    {
        $this->parallelDefinition([
            'code' => 'parallel_with_return',
            'definition' => [
                'nodes' => [
                    ['code' => 'start', 'type' => 'start', 'name' => 'Recorded'],
                    ['code' => 'author', 'type' => 'approval', 'name' => 'Author',
                        'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['risk-manager']]],
                    ['code' => 'fork', 'type' => 'parallel_gateway', 'name' => 'Both at once'],
                    ['code' => 'risk_review', 'type' => 'approval', 'name' => 'Risk review',
                        'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['risk-manager']],
                        'allow_return' => true],
                    ['code' => 'compliance_review', 'type' => 'approval', 'name' => 'Compliance review',
                        'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['compliance-officer']]],
                    ['code' => 'join', 'type' => 'join', 'name' => 'Both complete'],
                    ['code' => 'approved', 'type' => 'end', 'name' => 'Approved', 'outcome' => 'approved'],
                ],
                'edges' => [
                    ['from' => 'start', 'to' => 'author'],
                    ['from' => 'author', 'to' => 'fork'],
                    ['from' => 'fork', 'to' => 'risk_review'],
                    ['from' => 'fork', 'to' => 'compliance_review'],
                    ['from' => 'risk_review', 'to' => 'join'],
                    ['from' => 'compliance_review', 'to' => 'join'],
                    ['from' => 'join', 'to' => 'approved'],
                ],
            ],
        ]);

        $event = $this->makeLossEvent();
        $instance = $this->engine->startFor('parallel_with_return', $event, [], $this->actor);

        $this->engine->advance($instance->openTasks()->first(), 'approve', [], $this->riskManager);
        $instance->refresh();

        $riskTask = $instance->openTasks()->where('node_code', 'risk_review')->first();
        $this->engine->returnForRework($riskTask, 'author', 'Amount is wrong.', $this->riskManager);

        $instance->refresh();

        $this->assertSame(['author'], $instance->currentNodeCodes());
        $this->assertSame(
            0,
            $instance->openTasks()->where('node_code', 'compliance_review')->count(),
            'A colleague approving the version being sent back would be approving something that no longer exists.'
        );
    }

    /* ================================================================== */
    /*  Conditions and authorization */
    /* ================================================================== */

    #[Test]
    public function a_condition_routes_on_a_value_frozen_at_start(): void
    {
        $this->publishDefinition([
            'code' => 'value_routed',
            'name' => 'Value routed',
            'entity_type' => 'loss_event',
            'definition' => [
                'nodes' => [
                    ['code' => 'start', 'type' => 'start', 'name' => 'Recorded'],
                    ['code' => 'gate', 'type' => 'exclusive_gateway', 'name' => 'Material?'],
                    ['code' => 'board', 'type' => 'approval', 'name' => 'Board',
                        'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['chief-risk-officer']]],
                    ['code' => 'approved', 'type' => 'end', 'name' => 'Approved', 'outcome' => 'approved'],
                ],
                'edges' => [
                    ['from' => 'start', 'to' => 'gate'],
                    ['from' => 'gate', 'to' => 'board', 'when' => "get(subject, 'gross_loss_kobo') > 100000000"],
                    ['from' => 'gate', 'to' => 'approved'],
                    ['from' => 'board', 'to' => 'approved'],
                ],
            ],
        ]);

        $small = $this->makeLossEvent(['gross_loss_amount_kobo' => 5_000_000]);
        $large = $this->makeLossEvent(['gross_loss_amount_kobo' => 900_000_000]);

        $this->assertSame(
            WorkflowInstanceStatus::Completed,
            $this->engine->startFor('value_routed', $small, [], $this->actor)->status,
            'Below the limit the decision is delegated and the process just finishes.'
        );

        $this->assertSame(
            ['board'],
            $this->engine->startFor('value_routed', $large, [], $this->actor)->currentNodeCodes()
        );
    }

    #[Test]
    public function only_a_user_the_gate_allows_may_decide(): void
    {
        $this->linearDefinition();
        $assessment = $this->makeAssessment();

        $instance = $this->engine->startFor('linear_review', $assessment, [], $this->actor);
        $task = $instance->openTasks()->first();

        $bystander = $this->makeUser('analyst@example.test', 'risk-analyst');

        $this->assertFalse($this->engine->canAct($task, $bystander), 'A risk analyst is not offered this task.');
        $this->assertTrue($this->engine->canAct($task, $this->riskManager));
    }

    #[Test]
    public function a_required_field_blocks_the_decision(): void
    {
        $this->linearDefinition([
            'code' => 'requires_comment',
            'definition' => [
                'nodes' => [
                    ['code' => 'start', 'type' => 'start', 'name' => 'Submitted'],
                    [
                        'code' => 'review', 'type' => 'approval', 'name' => 'Review',
                        'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['risk-manager']],
                        'required_fields' => ['comments'],
                    ],
                    ['code' => 'approved', 'type' => 'end', 'name' => 'Approved', 'outcome' => 'approved'],
                ],
                'edges' => [
                    ['from' => 'start', 'to' => 'review'],
                    ['from' => 'review', 'to' => 'approved'],
                ],
            ],
        ]);

        $assessment = $this->makeAssessment();
        $instance = $this->engine->startFor('requires_comment', $assessment, [], $this->actor);

        $this->expectException(RuntimeException::class);

        $this->engine->advance($instance->openTasks()->first(), 'approve', [], $this->riskManager);
    }

    /* ================================================================== */
    /*  Versioning and the retired mechanism */
    /* ================================================================== */

    #[Test]
    public function a_running_instance_stays_on_the_version_it_started_with(): void
    {
        $published = $this->linearDefinition();
        $assessment = $this->makeAssessment();

        $instance = $this->engine->startFor('linear_review', $assessment, [], $this->actor);

        // Publish a second version whose review step demands a comment.
        $next = $this->publishDefinition([
            'code' => 'linear_review',
            'name' => 'Linear review',
            'entity_type' => 'risk_assessment',
            'version' => $published->version + 1,
            'definition' => [
                'nodes' => [
                    ['code' => 'start', 'type' => 'start', 'name' => 'Submitted'],
                    [
                        'code' => 'review', 'type' => 'approval', 'name' => 'Review',
                        'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['risk-manager']],
                        'required_fields' => ['comments'],
                    ],
                    ['code' => 'approved', 'type' => 'end', 'name' => 'Approved', 'outcome' => 'approved'],
                ],
                'edges' => [
                    ['from' => 'start', 'to' => 'review'],
                    ['from' => 'review', 'to' => 'approved'],
                ],
            ],
        ]);

        $this->assertSame($published->version + 1, $next->version);
        $this->assertFalse($published->fresh()->is_published, 'Publishing a version retires its predecessor.');

        // The running instance is pinned, so the new required field does not
        // apply to a decision that was already in progress.
        $this->engine->advance($instance->openTasks()->first(), 'approve', [], $this->riskManager);

        $this->assertSame(WorkflowInstanceStatus::Completed, $instance->fresh()->status);
    }

    #[Test]
    public function deciding_through_the_engine_writes_no_approval_request(): void
    {
        $this->linearDefinition();
        $assessment = $this->makeAssessment();

        $instance = $this->engine->startFor('linear_review', $assessment, [], $this->actor);
        $this->engine->advance($instance->openTasks()->first(), 'approve', [], $this->riskManager);

        $this->assertSame(
            0,
            ApprovalRequest::withoutGlobalScopes()->count(),
            'approval_requests is the mechanism WP-06 retires; the engine must not keep feeding it.'
        );

        $this->assertGreaterThan(0, WorkflowTask::withoutGlobalScopes()->count());
    }

    /* ================================================================== */

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

    private function makeAssessment(array $attributes = []): RiskAssessment
    {
        $risk = $this->makeRisk();

        return RiskAssessment::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'assessment_date' => now()->toDateString(),
            'assessment_type' => 'periodic',
            'likelihood_score' => 3,
            // The dimensions matter: AssessmentApproved's listener recomputes
            // the parent risk from them through RiskScoringService, so an
            // assessment with a bare impact_score would push a zero onto the
            // risk. That is pre-existing behaviour the engine must preserve.
            'impact_financial' => 4,
            'impact_operational' => 3,
            'impact_score' => 4,
            'overall_score' => 12,
            'overall_rating' => 'High',
            'status' => 'draft',
            'assessor_id' => $this->actor->id,
            'created_by' => $this->actor->id,
        ], $attributes));
    }
}

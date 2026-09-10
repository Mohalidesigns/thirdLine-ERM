<?php

namespace Tests\Feature\Workflow;

use App\Models\WorkflowDefinition;
use App\Services\Workflow\WorkflowDefinitionValidator;
use App\Services\Workflow\WorkflowPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesWorkflowFixtures;
use Tests\TestCase;

/**
 * WP-06 TASK 3 acceptance: the designer publishes a valid definition and
 * REFUSES an invalid one.
 *
 * Each rejected shape below is one somebody actually draws, and each produces a
 * running instance nobody can clear — a task on a queue with no way to close it
 * and a record stuck in review. That is why publish is where correctness is
 * demanded rather than merely encouraged.
 *
 * Migration Phase 6.5 replaced the Livewire designer with a page that posts the
 * whole graph. The three tests that drove the component now drive the routes,
 * and one of them asserts something stronger than it used to: a rename that
 * leaves an edge pointing at the old code was a UI concern before, and is a
 * server-side refusal now.
 */
class WorkflowDesignerTest extends TestCase
{
    use CreatesDomainFixtures, CreatesWorkflowFixtures, RefreshDatabase;

    private WorkflowDefinitionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();
        $this->actor->assignRole('super-admin');
        $this->actingAs($this->actor);

        $this->validator = app(WorkflowDefinitionValidator::class);
    }

    /* ================================================================== */
    /*  Validation */
    /* ================================================================== */

    #[Test]
    public function a_definition_with_no_start_is_rejected(): void
    {
        $errors = $this->validator->errors([
            'nodes' => [
                ['code' => 'review', 'type' => 'approval', 'name' => 'Review',
                    'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['risk-manager']]],
                ['code' => 'done', 'type' => 'end', 'name' => 'Done', 'outcome' => 'approved'],
            ],
            'edges' => [['from' => 'review', 'to' => 'done']],
        ]);

        $this->assertContains('The workflow has no start step.', $errors);
    }

    #[Test]
    public function a_definition_with_no_end_is_rejected(): void
    {
        $errors = $this->validator->errors([
            'nodes' => [
                ['code' => 'start', 'type' => 'start', 'name' => 'Start'],
                ['code' => 'review', 'type' => 'approval', 'name' => 'Review',
                    'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['risk-manager']]],
            ],
            'edges' => [['from' => 'start', 'to' => 'review']],
        ]);

        $this->assertContains(
            'The workflow has no end step, so no instance of it could ever finish.',
            $errors
        );
    }

    #[Test]
    public function an_unreachable_step_is_rejected(): void
    {
        $errors = $this->validator->errors([
            'nodes' => [
                ['code' => 'start', 'type' => 'start', 'name' => 'Start'],
                ['code' => 'review', 'type' => 'approval', 'name' => 'Review',
                    'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['risk-manager']]],
                ['code' => 'orphan', 'type' => 'approval', 'name' => 'Orphan',
                    'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['risk-manager']]],
                ['code' => 'done', 'type' => 'end', 'name' => 'Done', 'outcome' => 'approved'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'review'],
                ['from' => 'review', 'to' => 'done'],
                ['from' => 'orphan', 'to' => 'done'],
            ],
        ]);

        $this->assertContains('Step [orphan] cannot be reached from the start.', $errors);
    }

    #[Test]
    public function a_branch_that_cannot_reach_an_end_is_rejected(): void
    {
        $errors = $this->validator->errors([
            'nodes' => [
                ['code' => 'start', 'type' => 'start', 'name' => 'Start'],
                ['code' => 'gate', 'type' => 'exclusive_gateway', 'name' => 'Which way'],
                ['code' => 'limbo', 'type' => 'approval', 'name' => 'Limbo',
                    'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['risk-manager']]],
                ['code' => 'done', 'type' => 'end', 'name' => 'Done', 'outcome' => 'approved'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'gate'],
                ['from' => 'gate', 'to' => 'limbo', 'when' => "outcome == 'x'"],
                ['from' => 'gate', 'to' => 'done'],
                ['from' => 'limbo', 'to' => 'limbo'],
            ],
        ]);

        $this->assertContains(
            'No end step can be reached from [limbo]; an instance that gets there never finishes.',
            $errors
        );
    }

    #[Test]
    public function an_unbalanced_gateway_is_rejected(): void
    {
        $errors = $this->validator->errors([
            'nodes' => [
                ['code' => 'start', 'type' => 'start', 'name' => 'Start'],
                ['code' => 'fork', 'type' => 'parallel_gateway', 'name' => 'Fork'],
                ['code' => 'join', 'type' => 'join', 'name' => 'Join'],
                ['code' => 'done', 'type' => 'end', 'name' => 'Done', 'outcome' => 'approved'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'fork'],
                ['from' => 'fork', 'to' => 'join'],
                ['from' => 'join', 'to' => 'done'],
            ],
        ]);

        $this->assertContains('Fork step [fork] has fewer than two outgoing branches; it forks nothing.', $errors);
        $this->assertContains('Join step [join] has fewer than two incoming branches; there is nothing to join.', $errors);
    }

    #[Test]
    public function a_decision_step_with_no_default_branch_is_rejected(): void
    {
        $errors = $this->validator->errors([
            'nodes' => [
                ['code' => 'start', 'type' => 'start', 'name' => 'Start'],
                ['code' => 'gate', 'type' => 'exclusive_gateway', 'name' => 'Which way'],
                ['code' => 'done', 'type' => 'end', 'name' => 'Done', 'outcome' => 'approved'],
                ['code' => 'other', 'type' => 'end', 'name' => 'Other', 'outcome' => 'rejected'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'gate'],
                ['from' => 'gate', 'to' => 'done', 'when' => "outcome == 'a'"],
                ['from' => 'gate', 'to' => 'other', 'when' => "outcome == 'b'"],
            ],
        ]);

        $this->assertTrue(
            collect($errors)->contains(fn (string $e) => str_contains($e, 'has no default branch')),
            'Without a default branch, an unmatched condition strands the instance silently.'
        );
    }

    #[Test]
    public function an_approval_step_offered_to_nobody_is_rejected(): void
    {
        $errors = $this->validator->errors([
            'nodes' => [
                ['code' => 'start', 'type' => 'start', 'name' => 'Start'],
                ['code' => 'review', 'type' => 'approval', 'name' => 'Review', 'assignee_rule' => 'role',
                    'assignee_config' => ['roles' => []]],
                ['code' => 'done', 'type' => 'end', 'name' => 'Done', 'outcome' => 'approved'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'review'],
                ['from' => 'review', 'to' => 'done'],
            ],
        ]);

        $this->assertContains('Step [review] is assigned by [role] but the rule has not been filled in.', $errors);
    }

    #[Test]
    public function an_owner_assigned_step_without_a_fallback_role_is_rejected(): void
    {
        $errors = $this->validator->errors([
            'nodes' => [
                ['code' => 'start', 'type' => 'start', 'name' => 'Start'],
                ['code' => 'review', 'type' => 'approval', 'name' => 'Review', 'assignee_rule' => 'owner'],
                ['code' => 'done', 'type' => 'end', 'name' => 'Done', 'outcome' => 'approved'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'review'],
                ['from' => 'review', 'to' => 'done'],
            ],
        ]);

        $this->assertTrue(
            collect($errors)->contains(fn (string $e) => str_contains($e, 'Add a fallback role')),
            'A record with no owner would otherwise put the task on nobody\'s list.'
        );
    }

    #[Test]
    public function a_malformed_condition_is_rejected(): void
    {
        $errors = $this->validator->errors([
            'nodes' => [
                ['code' => 'start', 'type' => 'start', 'name' => 'Start'],
                ['code' => 'done', 'type' => 'end', 'name' => 'Done', 'outcome' => 'approved'],
                ['code' => 'other', 'type' => 'end', 'name' => 'Other', 'outcome' => 'rejected'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'done', 'when' => 'outcome == ((('],
                ['from' => 'start', 'to' => 'other'],
            ],
        ]);

        $this->assertTrue(collect($errors)->contains(fn (string $e) => str_contains($e, 'does not parse')));
    }

    #[Test]
    public function publishing_an_invalid_definition_throws(): void
    {
        $draft = app(WorkflowPublisher::class)->saveDraft(null, [
            'organization_id' => $this->organization->id,
            'code' => 'broken',
            'name' => 'Broken',
            'entity_type' => 'risk_assessment',
            'definition' => ['nodes' => [['code' => 'start', 'type' => 'start', 'name' => 'Start']], 'edges' => []],
        ]);

        $this->expectException(RuntimeException::class);

        app(WorkflowPublisher::class)->publish($draft);
    }

    /* ================================================================== */
    /*  The designer */
    /* ================================================================== */

    /**
     * The skeleton the designer opens with, with the review step assigned.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function design(array $overrides = []): array
    {
        return array_merge([
            'code' => 'designer_made',
            'name' => 'Designer made',
            'entity_type' => 'risk_assessment',
            'trigger' => 'manual',
            'definition' => [
                'nodes' => [
                    ['code' => 'start', 'type' => 'start', 'name' => 'Submitted', 'x' => 40, 'y' => 120],
                    ['code' => 'review', 'type' => 'approval', 'name' => 'Review',
                        'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['risk-manager']],
                        'sla_hours' => 72, 'on_timeout' => 'escalate',
                        'allow_delegate' => true, 'allow_return' => true, 'x' => 280, 'y' => 120],
                    ['code' => 'approved', 'type' => 'end', 'name' => 'Approved',
                        'outcome' => 'approved', 'x' => 540, 'y' => 60],
                    ['code' => 'rejected', 'type' => 'end', 'name' => 'Rejected',
                        'outcome' => 'rejected', 'x' => 540, 'y' => 200],
                ],
                'edges' => [
                    ['from' => 'start', 'to' => 'review'],
                    ['from' => 'review', 'to' => 'rejected', 'when' => "outcome == 'reject'", 'label' => 'Rejected'],
                    ['from' => 'review', 'to' => 'approved', 'label' => 'Approved'],
                ],
            ],
        ], $overrides);
    }

    #[Test]
    public function the_designer_saves_a_draft_and_publishes_it(): void
    {
        $this->post(route('risk.workflows.create-design'), $this->design())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $definition = WorkflowDefinition::withoutGlobalScopes()->where('code', 'designer_made')->firstOrFail();

        $this->post(route('risk.workflows.publish-definition', $definition->id))
            ->assertSessionHasNoErrors();

        $definition->refresh();

        $this->assertTrue($definition->is_published);
        $this->assertSame(1, $definition->version);
        $this->assertTrue($definition->isGraphBased());
    }

    #[Test]
    public function the_designer_refuses_to_publish_a_broken_graph(): void
    {
        // Both ends removed, leaving the review step with nowhere to go. The
        // DRAFT still saves — a half-drawn process is a normal thing to have —
        // and publishing is what refuses.
        $design = $this->design(['code' => 'still_broken', 'name' => 'Still broken']);
        $design['definition']['nodes'] = array_slice($design['definition']['nodes'], 0, 2);
        $design['definition']['edges'] = [['from' => 'start', 'to' => 'review']];

        $this->post(route('risk.workflows.create-design'), $design)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $definition = WorkflowDefinition::withoutGlobalScopes()->where('code', 'still_broken')->firstOrFail();

        $this->post(route('risk.workflows.publish-definition', $definition->id))
            ->assertSessionHas('error');

        $this->assertFalse((bool) $definition->fresh()->is_published);
    }

    #[Test]
    public function an_edge_pointing_at_a_step_that_is_not_on_the_canvas_is_refused(): void
    {
        // Renaming a step without rewiring is the mistake this refusal exists
        // to prevent: it leaves edges pointing at a step that no longer exists,
        // and the failure surfaces as a workflow that silently stops. The
        // designer rewires on rename; this is the backstop, and it names the
        // exact edge so the page can point at it.
        $design = $this->design();
        $design['definition']['nodes'][1]['code'] = 'risk_manager_review';

        $this->post(route('risk.workflows.create-design'), $design)
            ->assertSessionHasErrors(['definition.edges.0.to', 'definition.edges.1.from']);

        $this->assertDatabaseMissing('workflow_definitions', ['code' => 'designer_made']);
    }

    #[Test]
    public function an_escalation_rule_cannot_name_a_step_that_does_not_exist(): void
    {
        // A deadline that passes in silence is the one thing an escalation rule
        // exists to prevent, and LifecycleBuilder's sibling defect — a rule
        // validated by nothing at all — was the same shape.
        $this->post(route('risk.workflows.create-design'), $this->design([
            'escalation_rules' => [
                ['node' => 'no_such_step', 'after_hours' => 24, 'action' => 'escalate'],
            ],
        ]))->assertSessionHasErrors('escalation_rules.0.node');
    }

    #[Test]
    public function editing_a_published_definition_starts_the_next_version_rather_than_mutating_it(): void
    {
        $published = $this->linearDefinition();

        $draft = app(WorkflowPublisher::class)->draftNewVersion($published, $this->actor);

        $this->assertNotSame($published->id, $draft->id);
        $this->assertSame($published->version + 1, $draft->version);
        $this->assertFalse($draft->is_published);
        $this->assertTrue($published->fresh()->is_published, 'The live version stays live until the draft is published.');
    }
}

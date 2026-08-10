<?php

namespace Tests\Feature\Workflow;

use App\Livewire\Admin\WorkflowDesigner;
use App\Models\WorkflowDefinition;
use App\Services\Workflow\WorkflowDefinitionValidator;
use App\Services\Workflow\WorkflowPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
    /*  The Livewire designer */
    /* ================================================================== */

    #[Test]
    public function the_designer_saves_a_draft_and_publishes_it(): void
    {
        Livewire::test(WorkflowDesigner::class)
            ->set('code', 'designer_made')
            ->set('name', 'Designer made')
            ->set('entityType', 'risk_assessment')
            ->set('nodes.1.assignee_config.roles', ['risk-manager'])
            ->call('publish')
            ->assertHasNoErrors();

        $definition = WorkflowDefinition::withoutGlobalScopes()->where('code', 'designer_made')->first();

        $this->assertNotNull($definition);
        $this->assertTrue($definition->is_published);
        $this->assertSame(1, $definition->version);
        $this->assertTrue($definition->isGraphBased());
    }

    #[Test]
    public function the_designer_refuses_to_publish_a_broken_graph(): void
    {
        $component = Livewire::test(WorkflowDesigner::class)
            ->set('code', 'still_broken')
            ->set('name', 'Still broken')
            ->set('entityType', 'risk_assessment')
            ->set('nodes.1.assignee_config.roles', ['risk-manager'])
            // Delete both ends, leaving the review step with nowhere to go.
            ->call('deleteNode', 'approved')
            ->call('deleteNode', 'rejected')
            ->call('publish');

        $errors = $component->get('publishErrors');

        $this->assertNotEmpty($errors);
        $this->assertFalse(
            WorkflowDefinition::withoutGlobalScopes()->where('code', 'still_broken')->value('is_published') ?? false
        );
    }

    #[Test]
    public function renaming_a_step_rewires_its_connections(): void
    {
        $component = Livewire::test(WorkflowDesigner::class)
            ->call('renameNode', 'review', 'risk manager review');

        $edges = collect($component->get('edges'));

        $this->assertTrue(
            $edges->contains(fn (array $e) => $e['from'] === 'risk_manager_review' || $e['to'] === 'risk_manager_review'),
            'A rename that leaves edges pointing at the old code produces a workflow that silently stops.'
        );
        $this->assertFalse($edges->contains(fn (array $e) => $e['from'] === 'review' || $e['to'] === 'review'));
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

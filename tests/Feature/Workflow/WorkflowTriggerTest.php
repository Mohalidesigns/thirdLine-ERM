<?php

namespace Tests\Feature\Workflow;

use App\Models\WorkflowInstance;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesWorkflowFixtures;
use Tests\TestCase;

/**
 * workflow_definitions.trigger must be a column something reads.
 *
 * WP-06 exists partly because escalation_rules was written by a designer and
 * read by nothing at all. Adding a `trigger` enum with the same property would
 * be the identical mistake in a new table, so each of its values is exercised
 * here — and the last test asserts the default: installing WP-06 starts nothing
 * automatically, because none of the ten shipped processes triggers itself.
 */
class WorkflowTriggerTest extends TestCase
{
    use CreatesDomainFixtures, CreatesWorkflowFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();
    }

    #[Test]
    public function a_definition_triggering_on_create_starts_when_the_record_is_created(): void
    {
        $this->parallelDefinition([
            'code' => 'auto_on_create',
            'entity_type' => 'loss_event',
            'trigger' => 'on_create',
        ]);

        $event = $this->makeLossEvent();

        $this->assertNotNull(
            WorkflowInstance::withoutGlobalScopes()->forEntity($event)->first(),
            'A definition that says "start when the record is created" must start when the record is created.'
        );
    }

    #[Test]
    public function a_scope_filter_narrows_what_a_trigger_fires_over(): void
    {
        $this->parallelDefinition([
            'code' => 'material_only',
            'entity_type' => 'loss_event',
            'trigger' => 'on_create',
            'scope_filter' => ['event_severity' => ['SEVERE', 'CRITICAL']],
        ]);

        $minor = $this->makeLossEvent(['event_severity' => 'MODERATE']);
        $severe = $this->makeLossEvent(['event_severity' => 'SEVERE']);

        $this->assertNull(
            WorkflowInstance::withoutGlobalScopes()->forEntity($minor)->first(),
            'Without a scope filter, "start on create" means every record — which is how an automation becomes ten thousand tasks.'
        );

        $this->assertNotNull(WorkflowInstance::withoutGlobalScopes()->forEntity($severe)->first());
    }

    #[Test]
    public function a_definition_triggering_on_transition_starts_when_the_status_moves(): void
    {
        $this->parallelDefinition([
            'code' => 'on_escalation',
            'entity_type' => 'loss_event',
            'trigger' => 'on_transition',
            'trigger_config' => ['to' => ['ESCALATED']],
        ]);

        $event = $this->makeLossEvent(['current_status' => 'NEW']);

        $this->assertNull(WorkflowInstance::withoutGlobalScopes()->forEntity($event)->first());

        $event->update(['current_status' => 'UNDER_INVESTIGATION']);
        $this->assertNull(
            WorkflowInstance::withoutGlobalScopes()->forEntity($event)->first(),
            'A transition to a state the definition does not name must not start it.'
        );

        $event->update(['current_status' => 'ESCALATED']);
        $this->assertNotNull(WorkflowInstance::withoutGlobalScopes()->forEntity($event)->first());
    }

    #[Test]
    public function a_trigger_never_opens_a_second_review_of_the_same_record(): void
    {
        $this->parallelDefinition([
            'code' => 'on_any_change',
            'entity_type' => 'loss_event',
            'trigger' => 'on_transition',
            'trigger_config' => ['to' => ['ESCALATED', 'UNDER_INVESTIGATION']],
        ]);

        $event = $this->makeLossEvent(['current_status' => 'NEW']);

        $event->update(['current_status' => 'UNDER_INVESTIGATION']);
        $event->update(['current_status' => 'ESCALATED']);

        $this->assertSame(
            1,
            WorkflowInstance::withoutGlobalScopes()->forEntity($event)->count(),
            'Two open instances over one record means two competing sets of tasks and no defensible answer to "was this approved".'
        );
    }

    #[Test]
    public function a_scheduled_definition_starts_over_everything_in_scope(): void
    {
        $this->parallelDefinition([
            'code' => 'quarterly_attestation',
            'entity_type' => 'loss_event',
            'trigger' => 'on_schedule',
            'trigger_config' => ['cadence' => 'quarterly'],
            'scope_filter' => ['current_status' => 'NEW'],
        ]);

        $inScope = $this->makeLossEvent(['current_status' => 'NEW']);
        $outOfScope = $this->makeLossEvent(['current_status' => 'CLOSED']);

        // The wrong cadence starts nothing.
        $this->artisan('workflow:run-scheduled', ['--cadence' => 'daily'])->assertExitCode(0);
        $this->assertNull(WorkflowInstance::withoutGlobalScopes()->forEntity($inScope)->first());

        $this->artisan('workflow:run-scheduled', ['--cadence' => 'quarterly'])->assertExitCode(0);

        $this->assertNotNull(WorkflowInstance::withoutGlobalScopes()->forEntity($inScope)->first());
        $this->assertNull(WorkflowInstance::withoutGlobalScopes()->forEntity($outOfScope)->first());
    }

    #[Test]
    public function nothing_the_platform_ships_starts_itself(): void
    {
        app(\App\Services\Workflow\WorkflowPublisher::class)->provision($this->organization->id);

        $event = $this->makeLossEvent();
        $risk = $this->makeRisk();

        $this->assertNull(
            WorkflowInstance::withoutGlobalScopes()->forEntity($event)->first(),
            'Installing WP-06 must not change what happens when somebody records a loss event.'
        );
        $this->assertNull(WorkflowInstance::withoutGlobalScopes()->forEntity($risk)->first());

        // ...but the same record started deliberately does run.
        $this->assertNotNull(
            app(WorkflowEngine::class)->startFor('loss_event_approval', $event, [], $this->actor)
        );
    }
}

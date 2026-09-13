<?php

namespace Tests\Feature\Workflow;

use App\Models\RiskAssessment;
use App\Models\User;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesWorkflowFixtures;
use Tests\TestCase;

/**
 * The workflow clocks must survive an update to their own row.
 *
 * Same defect class as measure_breaches.breached_at in WP-04: on MySQL/MariaDB
 * with `explicit_defaults_for_timestamp = OFF` the first TIMESTAMP column in a
 * table is implicitly given ON UPDATE CURRENT_TIMESTAMP.
 *
 * It became dangerous in WP-06 specifically. The old engine wrote an instance
 * twice in its life, so a resetting started_at was nearly invisible. The v2
 * engine writes one on every step — entering a node, leaving it, merging
 * context — and writes a task on every claim, delegation and escalation. A
 * due_at that moved on each of those would mean nothing was ever overdue, and
 * the SLA sweeper would be a job that runs hourly and finds nothing, forever.
 *
 * SQLite stores TIMESTAMP and DATETIME identically and has no automatic update
 * behaviour, so it cannot express the failure. The structural assertions skip
 * there rather than passing vacuously; the behavioural ones run everywhere.
 */
class WorkflowTimestampTest extends TestCase
{
    use CreatesDomainFixtures, CreatesWorkflowFixtures, RefreshDatabase;

    #[Test]
    public function the_workflow_clocks_carry_no_automatic_update_behaviour(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Only MySQL/MariaDB apply an implicit ON UPDATE CURRENT_TIMESTAMP, so only they can fail this.'
            );
        }

        $clocks = [
            'workflow_tasks' => ['due_at', 'escalated_at', 'completed_at'],
            'workflow_instances' => ['started_at', 'completed_at', 'sla_due_at', 'breached_at'],
        ];

        foreach ($clocks as $table => $columns) {
            $described = collect(DB::select("SHOW COLUMNS FROM {$table}"))->keyBy('Field');

            foreach ($columns as $column) {
                $this->assertTrue($described->has($column), "{$table}.{$column} is missing.");

                $this->assertStringNotContainsStringIgnoringCase(
                    'on update',
                    (string) $described[$column]->Extra,
                    "{$table}.{$column} must not reset itself when the row is updated."
                );

                $this->assertStringNotContainsStringIgnoringCase(
                    'timestamp',
                    (string) $described[$column]->Type,
                    "{$table}.{$column} must be DATETIME: TIMESTAMP re-acquires the implicit ON UPDATE clause."
                );
            }
        }
    }

    #[Test]
    public function a_due_date_survives_every_write_the_engine_makes_to_its_task(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();
        $this->linearDefinition();

        $engine = app(WorkflowEngine::class);

        $riskManager = User::create([
            'name' => 'Risk Manager',
            'email' => 'rm-clock@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $riskManager->assignRole('risk-manager');

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

        $instance = $engine->startFor('linear_review', $assessment, [], $this->actor);
        $task = $instance->openTasks()->first();

        $startedAt = $instance->started_at->toDateTimeString();
        $dueAt = $task->due_at->toDateTimeString();

        // Backdate so the task is genuinely overdue, then put it through the
        // writes the engine makes in ordinary use.
        $task->forceFill(['due_at' => now()->subDays(2), 'assignee_id' => $riskManager->id])->save();
        $backdated = $task->fresh()->due_at->toDateTimeString();

        $engine->delegate($task->fresh(), $riskManager, 'Reassigned.', $this->actor);
        $engine->sweep();

        $this->assertSame(
            $backdated,
            $task->fresh()->due_at->toDateTimeString(),
            'A delegation and an escalation must not move the deadline the SLA is measured against.'
        );

        $this->assertTrue($task->fresh()->isOverdue(), 'An overdue task must still be overdue after being touched.');

        $this->assertSame(
            $startedAt,
            $instance->fresh()->started_at->toDateTimeString(),
            'Every step the engine takes writes the instance; none of them may move when it began.'
        );

        $this->assertNotSame($dueAt, $backdated, 'The fixture must actually have backdated the task.');
    }
}

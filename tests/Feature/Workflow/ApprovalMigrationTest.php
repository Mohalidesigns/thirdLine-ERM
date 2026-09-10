<?php

namespace Tests\Feature\Workflow;

use App\Models\ApprovalRequest;
use App\Models\ControlTest;
use App\Models\Issue;
use App\Models\LossEventApproval;
use App\Models\RiskAssessment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowLibrary;
use App\Services\Workflow\WorkflowPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-06 TASK 4 acceptance: one engine drives the approvals in every module, the
 * shipped definitions are publishable, and approval_requests stops receiving
 * new writes.
 */
class ApprovalMigrationTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();

        $this->engine = app(WorkflowEngine::class);

        app(WorkflowPublisher::class)->provision($this->organization->id);
    }

    #[Test]
    public function every_shipped_definition_installs_and_publishes(): void
    {
        $expected = collect(WorkflowLibrary::definitions())->pluck('code')->sort()->values();

        $installed = WorkflowDefinition::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->where('is_published', true)
            ->pluck('code')
            ->sort()
            ->values();

        $this->assertSame($expected->all(), $installed->all());
        $this->assertCount(10, $installed, 'WP-06 ships ten processes; every one must be publishable as drawn.');
    }

    #[Test]
    public function the_engine_drives_approvals_in_at_least_six_modules(): void
    {
        $subjects = [
            'risk_assessment_approval' => $this->makeAssessment(),
            'treatment_plan_approval' => $this->makeTreatmentPlan(),
            'control_test_review' => $this->makeControlTest(),
            'loss_event_approval' => $this->makeLossEvent(),
            'issue_closure_approval' => $this->makeIssue(),
            'risk_acceptance_approval' => $this->makeRisk(),
            'risk_appetite_approval' => $this->makeAppetite(),
        ];

        $started = 0;

        foreach ($subjects as $code => $subject) {
            $instance = $this->engine->startFor($code, $subject, [], $this->actor);

            $this->assertNotNull($instance, "[{$code}] must start over its subject.");
            $this->assertNotNull(
                $instance->openTasks()->first(),
                "[{$code}] must raise a task somebody owns."
            );

            $started++;
        }

        $this->assertGreaterThanOrEqual(6, $started);
    }

    #[Test]
    public function a_cbn_reportable_loss_event_runs_risk_and_compliance_in_parallel(): void
    {
        $event = $this->makeLossEvent(['cbn_reportable' => true]);

        $instance = $this->engine->startFor('loss_event_approval', $event, [], $this->actor);
        $lossManager = $this->makeUser('lem@example.test', 'loss-event-manager');

        $this->engine->advance($instance->openTasks()->first(), 'approve', [], $lossManager);

        $instance->refresh();

        $this->assertEqualsCanonicalizing(
            ['level_2', 'compliance_review'],
            $instance->currentNodeCodes(),
            'The CBN clock is not spent queueing: the CRO and compliance review at the same time.'
        );
    }

    #[Test]
    public function loss_event_stage_history_is_still_written_for_the_cbn_screens(): void
    {
        $event = $this->makeLossEvent(['cbn_reportable' => false, 'gross_loss_amount_kobo' => 1_000_000]);

        $instance = $this->engine->startFor('loss_event_approval', $event, [], $this->actor);
        $lossManager = $this->makeUser('lem2@example.test', 'loss-event-manager');

        $this->engine->advance($instance->openTasks()->first(), 'approve', ['comments' => 'Reconciled to GL.'], $lossManager);

        $history = LossEventApproval::where('loss_event_id', $event->id)->get();

        $this->assertCount(1, $history);
        $this->assertSame('level_1', $history->first()->stage);
        $this->assertSame($lossManager->id, $history->first()->actioned_by);
        $this->assertSame('APPROVED', $event->fresh()->current_status);
    }

    #[Test]
    public function an_expensive_treatment_plan_picks_up_the_cro_step(): void
    {
        $cheap = $this->makeTreatmentPlan(['cost_estimate_ngn' => 1_000_000]);
        $expensive = $this->makeTreatmentPlan(['cost_estimate_ngn' => 250_000_000]);

        $riskManager = $this->makeUser('rm2@example.test', 'risk-manager');

        $cheapInstance = $this->engine->startFor('treatment_plan_approval', $cheap, [], $this->actor);
        $this->engine->advance($cheapInstance->openTasks()->first(), 'approve', [], $riskManager);

        $this->assertSame('approved', $cheap->fresh()->status);

        $expensiveInstance = $this->engine->startFor('treatment_plan_approval', $expensive, [], $this->actor);
        $this->engine->advance($expensiveInstance->openTasks()->first(), 'approve', [], $riskManager);

        $this->assertSame(['cro_review'], $expensiveInstance->fresh()->currentNodeCodes());
        $this->assertSame(
            'pending_review',
            $expensive->fresh()->status,
            'A plan over the delegated limit is not approved until the CRO says so.'
        );
    }

    #[Test]
    public function a_regulatory_issue_needs_compliance_before_it_can_be_closed(): void
    {
        $internal = $this->makeIssue(['regulatory_reportable' => false, 'cbn_reportable' => false]);
        $regulatory = $this->makeIssue(['regulatory_reportable' => true]);

        $issueManager = $this->makeUser('im@example.test', 'issue-manager');

        $internalInstance = $this->engine->startFor('issue_closure_approval', $internal, [], $this->actor);
        $this->engine->advance($internalInstance->openTasks()->first(), 'approve', [], $issueManager);

        $this->assertSame('CLOSED', $internal->fresh()->issue_status);

        $regulatoryInstance = $this->engine->startFor('issue_closure_approval', $regulatory, [], $this->actor);
        $this->engine->advance($regulatoryInstance->openTasks()->first(), 'approve', [], $issueManager);

        $this->assertSame(['compliance_signoff'], $regulatoryInstance->fresh()->currentNodeCodes());
        $this->assertSame(
            'PENDING_CLOSURE',
            $regulatory->fresh()->issue_status,
            'Closing a CBN examination finding used to be one click by whoever opened the screen.'
        );
    }

    #[Test]
    public function approving_a_risk_acceptance_records_who_accepted_it_and_until_when(): void
    {
        $risk = $this->makeRisk(['residual_rating' => 'Medium']);
        $riskManager = $this->makeUser('rm3@example.test', 'risk-manager');

        $instance = $this->engine->startFor('risk_acceptance_approval', $risk, [], $this->actor);
        $this->engine->advance(
            $instance->openTasks()->first(),
            'approve',
            ['comments' => 'Cost of treatment exceeds the exposure.'],
            $riskManager,
        );

        $risk->refresh();

        $this->assertSame('accept', $risk->treatment_strategy);
        $this->assertSame($riskManager->id, $risk->accepted_by);
        $this->assertNotNull($risk->accepted_at);
        $this->assertNotNull(
            $risk->acceptance_expires_at,
            'An acceptance with no expiry is indistinguishable from a risk nobody is looking at.'
        );
    }

    #[Test]
    public function a_high_residual_risk_acceptance_needs_the_cro(): void
    {
        $risk = $this->makeRisk(['residual_rating' => 'High']);
        $riskManager = $this->makeUser('rm4@example.test', 'risk-manager');

        $instance = $this->engine->startFor('risk_acceptance_approval', $risk, [], $this->actor);
        $this->engine->advance($instance->openTasks()->first(), 'approve', ['comments' => 'Accepted.'], $riskManager);

        $this->assertSame(['cro_approval'], $instance->fresh()->currentNodeCodes());
        $this->assertNull($risk->fresh()->accepted_by);
    }

    #[Test]
    public function no_module_writes_a_new_approval_request(): void
    {
        $assessment = $this->makeAssessment();
        $riskManager = $this->makeUser('rm5@example.test', 'risk-manager');

        $instance = $this->engine->startFor('risk_assessment_approval', $assessment, [], $this->actor);
        $this->engine->advance($instance->openTasks()->first(), 'approve', [], $riskManager);

        $this->assertSame(0, ApprovalRequest::withoutGlobalScopes()->count());
    }

    #[Test]
    public function an_open_approval_request_is_migrated_onto_the_engine(): void
    {
        // The shape the upgrade migration finds on a live deployment.
        $assessment = $this->makeAssessment(['status' => 'in_review']);
        $reviewer = $this->makeUser('reviewer@example.test', 'risk-manager');

        $approval = ApprovalRequest::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'entity_type' => $assessment->getMorphClass(),
            'entity_id' => $assessment->id,
            'action' => 'approve_risk_assessment',
            'status' => 'pending',
            'payload' => ['reviewer_id' => $reviewer->id],
            'requested_by' => $this->actor->id,
            'requested_at' => now()->subDays(3),
        ]);

        $this->runMigration('2026_08_14_120004_migrate_open_approvals_onto_the_engine');

        $instance = WorkflowInstance::withoutGlobalScopes()->forEntity($assessment)->first();

        $this->assertNotNull($instance, 'An open decision must not be lost in the move.');
        $this->assertTrue($instance->isOpen());

        $task = $instance->openTasks()->withoutGlobalScopes()->first();

        $this->assertNotNull($task);
        $this->assertSame($reviewer->id, $task->assignee_id, 'The reviewer who had it keeps it.');

        // The clock keeps running from when the item was raised, not from the
        // deploy — otherwise every ageing report resets on upgrade.
        $this->assertTrue($instance->started_at->isSameDay(now()->subDays(3)));

        $this->assertSame(
            'superseded',
            $approval->fresh()->status,
            'The old row is closed as evidence of where this came from, not deleted.'
        );
    }

    #[Test]
    public function a_decided_approval_request_is_left_exactly_as_it_is(): void
    {
        $assessment = $this->makeAssessment(['status' => 'approved']);

        $approval = ApprovalRequest::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'entity_type' => $assessment->getMorphClass(),
            'entity_id' => $assessment->id,
            'action' => 'approve_risk_assessment',
            'status' => 'approved',
            'requested_by' => $this->actor->id,
            'requested_at' => now()->subDays(9),
            'reviewed_by' => $this->actor->id,
            'reviewed_at' => now()->subDays(8),
        ]);

        $this->runMigration('2026_08_14_120004_migrate_open_approvals_onto_the_engine');

        $this->assertSame('approved', $approval->fresh()->status);
        $this->assertNull(
            WorkflowInstance::withoutGlobalScopes()->forEntity($assessment)->first(),
            'Rewriting settled compliance history into a different table is not a migration.'
        );
    }

    /* ================================================================== */

    private function runMigration(string $name): void
    {
        $migration = require database_path('migrations/'.$name.'.php');

        $migration->up();
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

    private function makeAssessment(array $attributes = []): RiskAssessment
    {
        return RiskAssessment::create(array_merge([
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
        ], $attributes));
    }

    private function makeTreatmentPlan(array $attributes = []): TreatmentPlan
    {
        return TreatmentPlan::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_id' => $this->makeRisk()->id,
            'treatment_code' => 'TP-'.uniqid(),
            'action_title' => 'Deploy the compensating control',
            'strategy' => 'mitigate',
            'owner_id' => $this->actor->id,
            'status' => 'draft',
            'target_date' => now()->addMonths(3)->toDateString(),
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function makeControlTest(array $attributes = []): ControlTest
    {
        return ControlTest::create(array_merge([
            'organization_id' => $this->organization->id,
            'control_id' => $this->makeControl()->id,
            'test_code' => 'CT-'.uniqid(),
            'title' => 'Quarterly walkthrough',
            'test_type' => 'operating_effectiveness',
            'tester_id' => $this->actor->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'in_progress',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function makeIssue(array $attributes = []): Issue
    {
        return Issue::create(array_merge([
            'organization_id' => $this->organization->id,
            'issue_reference' => 'ISS-'.uniqid(),
            'title' => 'Reconciliation backlog',
            'description' => 'Daily reconciliation not completed for five days.',
            'issue_source' => 'internal_audit',
            'issue_category' => 'process',
            'priority' => 'high',
            'issue_status' => 'IN_PROGRESS',
            'responsible_owner_id' => $this->actor->id,
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function makeAppetite(array $attributes = []): \App\Models\RiskAppetite
    {
        return \App\Models\RiskAppetite::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_category_id' => $this->category->id,
            'appetite_level' => 'moderate',
            'appetite_statement' => 'We accept moderate operational risk.',
            'tolerance_metric' => 'Annual operational loss',
            'max_tolerance' => 500_000_000,
            'target_min' => 0,
            'target_max' => 250_000_000,
            'unit_of_measure' => 'NGN',
            'effective_date' => now()->toDateString(),
        ], $attributes));
    }
}

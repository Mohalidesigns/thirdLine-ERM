<?php

namespace Tests\Feature\Characterisation;

use App\Enums\WorkflowTaskStatus;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Services\Workflow\WorkflowDashboardService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesWorkflowFixtures;
use Tests\TestCase;

/**
 * Characterisation of the workflow dashboard's six counters, which lived
 * inline in WorkflowController::dashboard before Phase 3.7.
 */
class WorkflowDashboardStatsTest extends TestCase
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
        $this->linearDefinition();
    }

    #[Test]
    public function the_counters_follow_the_open_tasks(): void
    {
        $service = app(WorkflowDashboardService::class);

        $before = $service->stats($this->organization->id, $this->riskManager);
        $this->assertSame(['running' => 0, 'completed_today' => 0, 'waiting_on_me' => 0, 'overdue' => 0, 'escalated' => 0, 'published' => 1], $before);

        $instance = $this->startReview();
        $task = $instance->openTasks()->first();

        $running = $service->stats($this->organization->id, $this->riskManager);
        $this->assertSame(1, $running['running']);
        $this->assertSame(1, $running['waiting_on_me'], 'a step offered to risk-manager waits on the risk manager');
        $this->assertSame(0, $service->stats($this->organization->id, $this->actor)['waiting_on_me']);

        $task->forceFill(['due_at' => now()->subHours(3), 'status' => WorkflowTaskStatus::Escalated])->save();
        $late = $service->stats($this->organization->id, $this->riskManager);
        $this->assertSame(1, $late['overdue']);
        $this->assertSame(1, $late['escalated']);

        $workload = $service->workload($this->organization->id);
        $this->assertSame('Unclaimed — risk-manager', $workload[0]['assignee']);
        $this->assertSame(1, $workload[0]['open']);
        $this->assertSame(1, $workload[0]['overdue']);
    }

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

        return app(WorkflowEngine::class)->startFor('linear_review', $assessment, [], $this->actor);
    }
}

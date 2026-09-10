<?php

namespace App\Services\Workflow;

use App\Enums\WorkflowInstanceStatus;
use App\Enums\WorkflowTaskStatus;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use Illuminate\Support\Collection;

/**
 * The workflow dashboard's figures (migration Phase 3.7 — extracted from
 * WorkflowController::dashboard; characterised in
 * tests/Feature/Characterisation/WorkflowDashboardStatsTest).
 */
class WorkflowDashboardService
{
    public function __construct(private readonly TaskQueryService $tasks) {}

    /**
     * @return array{running:int, completed_today:int, waiting_on_me:int, overdue:int, escalated:int, published:int}
     */
    public function stats(int $organizationId, User $user): array
    {
        $openTasks = WorkflowTask::query()->where('organization_id', $organizationId)->open();

        return [
            'running' => WorkflowInstance::query()->where('organization_id', $organizationId)->open()->count(),
            'completed_today' => WorkflowInstance::query()->where('organization_id', $organizationId)
                ->where('status', WorkflowInstanceStatus::Completed->value)
                ->whereDate('completed_at', today())
                ->count(),
            'waiting_on_me' => $this->tasks->countsFor($user)['open'],
            'overdue' => (clone $openTasks)->overdue()->count(),
            'escalated' => (clone $openTasks)->where('status', WorkflowTaskStatus::Escalated->value)->count(),
            'published' => WorkflowDefinition::query()->where('organization_id', $organizationId)->published()->count(),
        ];
    }

    /**
     * @return Collection<int, WorkflowInstance>
     */
    public function recentInstances(int $organizationId, int $limit = 15): Collection
    {
        return WorkflowInstance::query()
            ->where('organization_id', $organizationId)
            ->with(['definition', 'initiator', 'tasks.assignee'])
            ->latest()
            ->take($limit)
            ->get();
    }

    /**
     * Open decisions per assignee, with the names resolved in one query
     * (the Blade did a User::find per row).
     *
     * @return list<array{assignee:string, open:int, overdue:int}>
     */
    public function workload(int $organizationId): array
    {
        $rows = $this->tasks->workloadFor($organizationId);
        $names = User::query()->whereIn('id', $rows->pluck('assignee_id')->filter())->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'assignee' => $names[$row->assignee_id] ?? ($row->assignee_role ? 'Unclaimed — '.$row->assignee_role : 'Unassigned'),
            'open' => (int) $row->open_count,
            'overdue' => (int) $row->overdue_count,
        ])->values()->all();
    }
}

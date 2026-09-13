<?php

namespace App\Services\Workflow;

use App\Enums\WorkflowTaskStatus;
use App\Models\User;
use App\Models\WorkflowTask;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WP-06 TASK 5 — everything a person owes, in one indexed query.
 *
 * The foundation for My Responsibilities (WP-08). It answers the question the
 * platform could not answer at all before: a user's open decisions were spread
 * across approval_requests, four sets of per-module status columns, and a
 * workflow_instances cursor with no assignee — so "what do I owe" meant opening
 * six screens and knowing which ones to open.
 *
 * The index is (assignee_id, status, due_at) on workflow_tasks, with a second
 * on (organization_id, assignee_role, status) for the unclaimed role offers.
 */
class TaskQueryService
{
    /**
     * A user's open tasks, newest deadline first, with the subject loaded.
     *
     * @return LengthAwarePaginator<WorkflowTask>
     */
    public function paginateFor(User $user, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->baseQuery($user, $filters)
            ->with(['instance.definition', 'assignee', 'delegatedFrom'])
            ->orderByRaw('case when due_at is null then 1 else 0 end')
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @return Collection<int, WorkflowTask> */
    public function openFor(User $user, int $limit = 50): Collection
    {
        return $this->baseQuery($user)
            ->with('instance.definition')
            ->orderByRaw('case when due_at is null then 1 else 0 end')
            ->orderBy('due_at')
            ->limit($limit)
            ->get();
    }

    /**
     * The counters the header badge and the My Responsibilities tiles read.
     *
     * @return array{open:int, overdue:int, due_today:int, escalated:int, delegated_to_me:int}
     */
    public function countsFor(User $user): array
    {
        $open = $this->baseQuery($user)->get(['id', 'status', 'due_at', 'delegated_to']);

        return [
            'open' => $open->count(),
            'overdue' => $open->filter(fn (WorkflowTask $t) => $t->isOverdue())->count(),
            'due_today' => $open->filter(
                fn (WorkflowTask $t) => $t->due_at !== null && $t->due_at->isToday() && ! $t->due_at->isPast()
            )->count(),
            'escalated' => $open->where('status', WorkflowTaskStatus::Escalated)->count(),
            'delegated_to_me' => $open->where('delegated_to', $user->id)->count(),
        ];
    }

    /**
     * Open tasks per assignee for an organization — the supervisor view, and
     * the input to an SLA report.
     *
     * @return Collection<int, object>
     */
    public function workloadFor(int $organizationId): Collection
    {
        return WorkflowTask::query()
            ->where('organization_id', $organizationId)
            ->open()
            ->selectRaw('assignee_id, assignee_role, count(*) as open_count')
            ->selectRaw('sum(case when due_at is not null and due_at < ? then 1 else 0 end) as overdue_count', [now()])
            ->groupBy('assignee_id', 'assignee_role')
            ->orderByDesc('open_count')
            ->get();
    }

    /**
     * @param  array{status?:string, overdue?:bool, definition?:string, entity_type?:string}  $filters
     */
    private function baseQuery(User $user, array $filters = []): Builder
    {
        $query = WorkflowTask::query()
            ->where('organization_id', $user->organization_id)
            ->open()
            ->actionableBy($user);

        if (($filters['overdue'] ?? false) === true) {
            $query->whereNotNull('due_at')->where('due_at', '<', now());
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['definition'])) {
            $query->whereHas('instance.definition', fn (Builder $q) => $q->where('code', $filters['definition']));
        }

        if (! empty($filters['entity_type'])) {
            $query->whereHas('instance', fn (Builder $q) => $q->where('entity_type', $filters['entity_type']));
        }

        return $query;
    }
}

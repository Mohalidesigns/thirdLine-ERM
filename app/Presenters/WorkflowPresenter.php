<?php

namespace App\Presenters;

use App\Enums\WorkflowNodeType;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Services\Workflow\TaskSubjectResolver;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Migration Phase 3.7 — the shapes the workflow, my-tasks and approvals
 * pages render from. Every label the Blade views composed (status badge
 * tone, "offered to <role>", "Nh over") is composed here once.
 */
class WorkflowPresenter
{
    public function __construct(
        private readonly TaskSubjectResolver $subjects,
        private readonly WorkflowEngine $engine,
    ) {}

    /**
     * @param  array<string, Model>  $subjects
     * @return array<string, mixed>
     */
    public function taskRow(WorkflowTask $task, array $subjects = []): array
    {
        $key = $task->instance?->entity_type.':'.$task->instance?->entity_id;
        $subject = $this->subjects->describe($subjects[$key] ?? null, $task->instance?->entity_type, $task->instance?->entity_id);

        return [
            'id' => $task->id,
            'name' => $task->node_name ?? $task->node_code,
            'process' => $task->instance?->definition?->name,
            'subject' => $subject,
            'delegated_by' => $task->delegated_from ? ($task->delegatedFrom?->name ?? 'a colleague') : null,
            'due_at' => $task->due_at?->toIso8601String(),
            'due_display' => $task->due_at?->format('d M H:i'),
            'overdue' => $task->isOverdue(),
            'hours_overdue' => $task->hoursOverdue(),
            'status' => $this->status($task->status->label(), $task->status->color()),
            'url' => route('risk.my-tasks.show', $task),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function step(WorkflowTask $task): array
    {
        $who = $task->assignee
            ? $task->assignee->name
            : ($task->assignee_role ? 'offered to '.$task->assignee_role : null);

        return [
            'id' => $task->id,
            'code' => $task->node_code,
            'name' => $task->node_name ?? $task->node_code,
            'open' => $task->isOpen(),
            'status' => $this->status($task->status->label(), $task->status->color()),
            'outcome' => $task->outcome,
            'who' => $who,
            'overdue' => $task->isOverdue(),
            'hours_overdue' => $task->hoursOverdue(),
            'delegated_by' => $task->delegated_from ? $task->delegatedFrom?->name : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function action(WorkflowAction $action): array
    {
        return [
            'id' => $action->id,
            'action' => $action->action,
            'actor' => $action->actorLabel(),
            'stage' => $action->stage_name ?? $action->node_code,
            'comments' => $action->comments,
            'at' => $action->acted_at?->toIso8601String(),
            'at_human' => $action->acted_at?->diffForHumans(),
        ];
    }

    /**
     * The instance's step list from its pinned graph: only the nodes that
     * wait for a human (and the end), each paired with its task if one has
     * been created. A graph process can be in two places at once, which is
     * why this is a list and not a "3 of 5".
     *
     * @return list<array<string, mixed>>
     */
    public function graphSteps(WorkflowInstance $instance): array
    {
        $graph = $instance->definitionSnapshot();
        $current = $instance->currentNodeCodes();
        $steps = [];

        foreach ($graph->nodes() as $node) {
            $type = WorkflowNodeType::tryFrom($node['type'] ?? '');

            if (! $type?->waitsForHuman() && $type !== WorkflowNodeType::End) {
                continue;
            }

            $task = $instance->tasks->firstWhere('node_code', $node['code']);

            $steps[] = [
                'code' => $node['code'],
                'name' => $node['name'] ?? $node['code'],
                'current' => in_array($node['code'], $current, true),
                'done' => $task !== null && ! $task->isOpen(),
                'task' => $task ? $this->step($task) : null,
            ];
        }

        return $steps;
    }

    /**
     * @return array<string, mixed>
     */
    public function instanceSummary(WorkflowInstance $instance, ?Model $subject = null): array
    {
        $described = $this->subjects->describe($subject, $instance->entity_type, $instance->entity_id);

        return [
            'id' => $instance->id,
            'name' => $instance->definition?->name,
            'entity_type' => $instance->entity_type,
            'entity_type_label' => str_replace('_', ' ', (string) $instance->entity_type),
            'entity_id' => $instance->entity_id,
            'subject' => $described,
            'version' => $instance->definition_version,
            'status' => $this->status($instance->status->label(), $instance->status->color()),
            'open' => $instance->isOpen(),
            'initiator' => $instance->initiator?->name,
            'started_at' => $instance->started_at?->toIso8601String(),
            'started_human' => $instance->started_at?->diffForHumans(),
            'url' => route('risk.workflows.show-instance', $instance),
        ];
    }

    /**
     * The dashboard's "recent activity" row: the summary plus who each open
     * step is waiting on.
     *
     * @return array<string, mixed>
     */
    public function recentInstance(WorkflowInstance $instance): array
    {
        $waiting = $instance->tasks->filter->isOpen()->map(fn (WorkflowTask $task) => [
            'name' => $task->node_name ?? $task->node_code,
            'who' => $task->assignee?->name ?? ($task->assignee_role ? 'any '.$task->assignee_role : 'unassigned'),
            'hours_overdue' => $task->hoursOverdue(),
        ])->values()->all();

        return $this->instanceSummary($instance) + ['waiting_on' => $waiting];
    }

    /**
     * @param  array<string, list<array{id:int, label:string}>>  $startOptions
     * @return array<string, mixed>
     */
    public function definitionRow(WorkflowDefinition $definition, array $startOptions): array
    {
        $steps = count($definition->graph()->nodes());
        $canStart = $definition->is_published && isset($startOptions[$definition->entity_type]);

        return [
            'id' => $definition->id,
            'name' => $definition->name,
            'code' => $definition->code,
            'is_system' => (bool) $definition->is_system,
            'entity_type' => $definition->entity_type,
            'entity_type_label' => str_replace('_', ' ', (string) $definition->entity_type),
            'steps' => $steps,
            'version' => (int) $definition->version,
            'is_published' => (bool) $definition->is_published,
            'urls' => [
                'edit' => route('risk.workflows.edit-definition', $definition),
                'publish' => $definition->is_published ? null : route('risk.workflows.publish-definition', $definition),
            ],
            'start_options' => $canStart ? $startOptions[$definition->entity_type] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function approval(ApprovalRequest $approval): array
    {
        return [
            'id' => $approval->id,
            'entity_type' => $approval->entity_type,
            'entity_id' => $approval->entity_id,
            'action' => $approval->action,
            'requested_by' => [
                'name' => $approval->requestedBy?->name ?? 'Unknown',
                'email' => $approval->requestedBy?->email ?? '',
            ],
            'requested_at' => $approval->requested_at?->format('d M Y H:i'),
            'requested_human' => $approval->requested_at?->diffForHumans(),
            'urls' => [
                'approve' => route('risk.approvals.approve', $approval),
                'reject' => route('risk.approvals.reject', $approval),
            ],
        ];
    }

    /**
     * Who can be handed a task: active colleagues in the tenant, minus the
     * person holding it.
     *
     * @return list<array{id:int, name:string}>
     */
    public function delegateOptions(WorkflowTask $task, User $user): array
    {
        return User::query()
            ->where('organization_id', $task->organization_id)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->all();
    }

    public function canAct(WorkflowTask $task, User $user): bool
    {
        return $this->engine->canAct($task, $user);
    }

    /**
     * Laravel paginator → {data, links, meta} for Components/Pagination.jsx.
     *
     * @return array<string, mixed>
     */
    public function paginate(LengthAwarePaginator $paginator, callable $map): array
    {
        return [
            'data' => collect($paginator->items())->map($map)->values()->all(),
            'links' => $paginator->linkCollection()->toArray(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array{label:string, color:string}
     */
    private function status(string $label, string $color): array
    {
        return ['label' => $label, 'color' => $color];
    }
}

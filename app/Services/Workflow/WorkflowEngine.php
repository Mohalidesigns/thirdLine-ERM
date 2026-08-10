<?php

namespace App\Services\Workflow;

use App\Enums\WorkflowInstanceStatus;
use App\Enums\WorkflowNodeType;
use App\Enums\WorkflowTaskStatus;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Services\AuditTrailService;
use App\Services\NotificationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * WP-06 TASK 2 — the single approval engine.
 *
 * WHAT THE PREVIOUS ENGINE DID NOT DO, AND WHY IT MATTERS
 *
 *   delegate()  wrote a workflow_actions row saying a task had been delegated
 *               and left the task exactly where it was. The delegate never saw
 *               it; the original assignee still owed it. On an audited platform
 *               that is worse than not offering delegation at all, because the
 *               log now asserts a handover that did not happen.
 *   escalate()  the same, plus escalation_rules on the definition were stored
 *               and never read by anything.
 *   return()    the same, and the instance stayed on the stage it was on.
 *
 * Every one of those now moves state. The tests in
 * tests/Feature/Workflow/WorkflowEngineTest are written specifically to fail if
 * any of them regresses to logging.
 *
 * EXECUTION MODEL. current_nodes is a set of active node codes. Human nodes
 * (task, approval) create a workflow_task and wait. Everything else executes
 * the moment control reaches it, so advance() runs a loop until every active
 * branch is either parked on a human node or finished. A parallel gateway puts
 * several codes in the set at once; a join removes them and emits one.
 *
 * TENANCY. Every write goes through models carrying BelongsToOrganization, and
 * the sweeper resolves each organization explicitly via TenantContext::actingAs
 * rather than running untenanted — a cross-tenant escalation would reassign one
 * bank's approval to another bank's CRO.
 */
class WorkflowEngine
{
    public function __construct(
        private AssigneeResolver $assignees,
        private ConditionEvaluator $conditions,
        private SubjectRegistry $subjects,
    ) {}

    /* ================================================================== */
    /*  Starting */
    /* ================================================================== */

    /**
     * Start the published definition with this code over this subject.
     *
     * Returns null when the organization has no published definition for the
     * code. That is not an error: an organization that has not configured a
     * loss-event workflow still has to be able to record a loss event, and the
     * caller falls back to its direct status change.
     *
     * @param  array<string, mixed>  $context
     */
    public function startFor(string $code, Model $subject, array $context = [], ?User $initiator = null): ?WorkflowInstance
    {
        $organizationId = $subject->getAttribute('organization_id') ?? TenantContext::organizationIdOrNull();

        $definition = WorkflowDefinition::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->published()
            ->orderByDesc('version')
            ->first();

        if ($definition === null || ! $definition->isGraphBased()) {
            return null;
        }

        return $this->start($definition, $subject, $context, $initiator);
    }

    /**
     * The open instance for a subject, if the process is already running.
     *
     * Callers use this to avoid starting a second review of the same thing —
     * two open instances over one subject means two competing sets of tasks and
     * no defensible answer to "was this approved".
     */
    public function openInstanceFor(Model $subject): ?WorkflowInstance
    {
        return WorkflowInstance::withoutGlobalScopes()
            ->forEntity($subject)
            ->open()
            ->orderByDesc('id')
            ->first();
    }

    /** @param array<string, mixed> $context */
    public function start(WorkflowDefinition $definition, Model $subject, array $context = [], ?User $initiator = null): WorkflowInstance
    {
        $graph = $definition->graph();
        $start = $graph->startNode();

        if ($start === null) {
            throw new RuntimeException(
                "Workflow definition [{$definition->code}] v{$definition->version} has no single start node and cannot be run."
            );
        }

        $binding = $this->subjects->for($subject);

        return DB::transaction(function () use ($definition, $subject, $context, $initiator, $graph, $start, $binding) {
            $instance = WorkflowInstance::withoutGlobalScopes()->create([
                'organization_id' => $subject->getAttribute('organization_id') ?? $definition->organization_id,
                'definition_id' => $definition->id,
                'definition_version' => $definition->version,
                'entity_type' => $subject->getMorphClass(),
                'entity_id' => $subject->getKey(),
                'current_stage' => 0,
                'current_nodes' => [$start['code']],
                'context' => array_merge(
                    ['subject' => $binding?->context($subject) ?? [], '_joins' => []],
                    $context,
                ),
                'status' => WorkflowInstanceStatus::Active->value,
                'started_at' => now(),
                'initiated_by' => $initiator?->id ?? auth()->id(),
            ]);

            $this->log($instance, null, $start['code'], 'start', $initiator?->id ?? auth()->id(), null);

            $binding?->onStarted($subject, $instance);

            $this->run($instance, [$start['code']], $graph, $subject, null);

            return $instance->refresh();
        });
    }

    /* ================================================================== */
    /*  Authorization */
    /* ================================================================== */

    /**
     * May this user decide this task?
     *
     * Two conditions, both required:
     *   1. the task is theirs — assigned by name, or offered to a role or
     *      shortlist they belong to;
     *   2. the subject's Gate lets them.
     *
     * The Gates are the ones the modules already had — approve-risk-assessment,
     * review-control-test, approve-loss-event. WP-06 deliberately does not
     * reinvent that layer: those Gates encode rules ("the assigned reviewer OR
     * a risk-manager/CRO") that a definition should not have to restate, and
     * restating them is how the two drift apart.
     *
     * A node may name an extra permission via `required_permission`, which is
     * checked on top.
     */
    public function canAct(WorkflowTask $task, User $user): bool
    {
        if (! $task->isOpen()) {
            return false;
        }

        $isMine = WorkflowTask::withoutGlobalScopes()
            ->whereKey($task->id)
            ->actionableBy($user)
            ->exists();

        if (! $isMine) {
            return false;
        }

        $node = $task->instance?->definitionSnapshot()->node($task->node_code) ?? [];

        if (! empty($node['required_permission']) && ! $user->can($node['required_permission'])) {
            return false;
        }

        $binding = $this->subjects->forType($task->instance?->entity_type);
        $gate = $binding?->gate();

        if ($gate === null) {
            return true;
        }

        $subject = $task->instance ? $this->subjectOf($task->instance) : null;

        return $subject === null ? true : $user->can($gate, $subject);
    }

    /**
     * The open task on this subject that this user may act on, if any.
     *
     * The controllers use it to turn "the user pressed Approve on the loss
     * event screen" into "advance this specific task", without the screen
     * needing to know the process has three nodes.
     */
    public function taskFor(Model $subject, User $user): ?WorkflowTask
    {
        $instance = $this->openInstanceFor($subject);

        if ($instance === null) {
            return null;
        }

        return $instance->openTasks()
            ->withoutGlobalScopes()
            ->get()
            ->first(fn (WorkflowTask $task) => $this->canAct($task, $user));
    }

    /* ================================================================== */
    /*  Acting on a task */
    /* ================================================================== */

    /**
     * Record an outcome on a task and move the instance on.
     *
     * @param  array<string, mixed>  $payload  form data captured at the node
     */
    public function advance(WorkflowTask $task, string $outcome, array $payload = [], ?User $actor = null): WorkflowInstance
    {
        $instance = $task->instance;

        if (! $task->isOpen()) {
            throw new RuntimeException('That task has already been closed.');
        }

        if (! $instance->isOpen()) {
            throw new RuntimeException('That workflow is no longer running.');
        }

        $graph = $instance->definitionSnapshot();
        $node = $graph->node($task->node_code) ?? [];

        $this->assertRequiredFields($node, $payload);

        $subject = $this->subjectOf($instance);
        $actorId = $actor?->id ?? auth()->id();

        return DB::transaction(function () use ($task, $instance, $graph, $outcome, $payload, $actor, $actorId, $subject) {
            $task->forceFill([
                'status' => WorkflowTaskStatus::Completed->value,
                'outcome' => $outcome,
                'completed_at' => now(),
                'completed_by' => $actorId,
                // The role holder who acted claims the task, so the history
                // shows who decided rather than only who could have.
                'assignee_id' => $task->assignee_id ?? $actorId,
                'comments' => $payload['comments'] ?? $task->comments,
                'form_data' => array_merge((array) $task->form_data, $payload),
            ])->save();

            $this->log($instance, $task, $task->node_code, $outcome, $actorId, $payload['comments'] ?? null);

            // Merge the node's captured fields into the instance context so a
            // downstream condition can read a decision made three nodes back.
            $instance->forceFill([
                'context' => array_merge((array) $instance->context, [
                    $task->node_code => array_merge($payload, ['outcome' => $outcome]),
                ]),
                'current_stage' => $instance->tasks()
                    ->where('status', WorkflowTaskStatus::Completed->value)->count(),
            ])->save();

            $next = $this->nextNodes($graph, $task->node_code, $outcome, $instance, $task);

            $this->leave($instance, $task->node_code);
            $this->run($instance, $next, $graph, $subject, $actor);

            return $instance->refresh();
        });
    }

    /**
     * Hand a task to somebody else. THE ASSIGNEE ACTUALLY CHANGES.
     */
    public function delegate(WorkflowTask $task, User $to, ?string $reason = null, ?User $actor = null): WorkflowTask
    {
        if (! $task->isOpen()) {
            throw new RuntimeException('A closed task cannot be delegated.');
        }

        $node = $task->instance->definitionSnapshot()->node($task->node_code) ?? [];

        if (($node['allow_delegate'] ?? true) === false) {
            throw new RuntimeException("The [{$task->node_code}] step does not allow delegation.");
        }

        if ($to->organization_id !== $task->organization_id) {
            throw new RuntimeException('A task cannot be delegated outside its organization.');
        }

        $from = $task->assignee_id ?? $actor?->id ?? auth()->id();

        $task->forceFill([
            'assignee_id' => $to->id,
            'delegated_from' => $from,
            'delegated_to' => $to->id,
            'status' => WorkflowTaskStatus::Delegated->value,
            // The offer to a role is withdrawn: the task now belongs to a named
            // person, and leaving the role candidates in place would let two
            // people act on one decision.
            'candidate_roles' => [],
            'candidate_user_ids' => [],
            'assignee_role' => null,
        ])->save();

        $this->log($task->instance, $task, $task->node_code, 'delegate', $actor?->id ?? auth()->id(), $reason, $to->id);

        $this->notify(
            [$to->id],
            $task,
            'Delegated to you: '.$this->taskLabel($task),
            trim(($actor?->name ?? 'The platform').' has delegated this decision to you.'.($reason ? "\n\nReason: {$reason}" : ''))
        );

        return $task->refresh();
    }

    /**
     * Escalate a task. THE ASSIGNEE ACTUALLY CHANGES, and the instance is
     * marked escalated so the SLA screens can count it.
     *
     * Target comes from the node's escalate_to, then the definition's
     * escalation_rules — which until now were written by the designer and read
     * by nothing at all.
     */
    public function escalate(WorkflowTask $task, ?string $reason = null, ?User $actor = null): WorkflowTask
    {
        if (! $task->isOpen()) {
            throw new RuntimeException('A closed task cannot be escalated.');
        }

        $instance = $task->instance;
        $graph = $instance->definitionSnapshot();
        $node = $graph->node($task->node_code) ?? [];

        $target = $this->escalationTarget($node, $instance);

        $resolution = $this->assignees->resolve(
            ['code' => $task->node_code, 'assignee_rule' => $target['rule'], 'assignee_config' => $target['config']],
            $instance,
            $this->subjectOf($instance),
            $this->subjects->forType($instance->entity_type),
        );

        $previous = $task->assignee_id;

        $task->forceFill([
            'assignee_id' => $resolution['assignee_id'],
            'assignee_role' => $resolution['assignee_role'],
            'candidate_roles' => $resolution['candidate_roles'],
            'candidate_user_ids' => $resolution['candidate_user_ids'],
            'status' => WorkflowTaskStatus::Escalated->value,
            'escalated_at' => now(),
        ])->save();

        $instance->forceFill([
            'status' => WorkflowInstanceStatus::Escalated->value,
            'breached_at' => $instance->breached_at ?? now(),
        ])->save();

        $this->log($instance, $task, $task->node_code, 'escalate', $actor?->id ?? auth()->id(), $reason);

        $recipients = $this->assignees->recipients($resolution, $instance->organization_id);

        if ($recipients === [] && $previous !== null) {
            // Nowhere to escalate to is worth saying out loud rather than
            // quietly leaving the task with the person who already missed it.
            Log::warning('Workflow escalation resolved to nobody; the task stays with its current assignee.', [
                'instance_id' => $instance->id,
                'task_id' => $task->id,
            ]);

            $task->forceFill(['assignee_id' => $previous])->save();
        }

        $this->notify(
            $recipients,
            $task,
            'Escalated to you: '.$this->taskLabel($task),
            trim('This decision has been escalated'.($reason ? ": {$reason}" : '.').' It is overdue at the level below.')
        );

        return $task->refresh();
    }

    /**
     * Send the instance back to an earlier node for rework. THE INSTANCE
     * ACTUALLY MOVES: the current task is closed, every other open task on the
     * instance is cancelled, and the target node is re-entered.
     */
    public function returnForRework(WorkflowTask $task, ?string $toNode = null, ?string $reason = null, ?User $actor = null): WorkflowInstance
    {
        $instance = $task->instance;
        $graph = $instance->definitionSnapshot();
        $node = $graph->node($task->node_code) ?? [];

        if (($node['allow_return'] ?? true) === false) {
            throw new RuntimeException("The [{$task->node_code}] step does not allow return for rework.");
        }

        $target = $toNode ?? $node['return_to'] ?? $this->firstHumanPredecessor($graph, $task->node_code);

        if ($target === null || $graph->node($target) === null) {
            throw new RuntimeException('There is no earlier step to return this to.');
        }

        $subject = $this->subjectOf($instance);
        $binding = $this->subjects->for($subject);
        $actorId = $actor?->id ?? auth()->id();

        return DB::transaction(function () use ($task, $instance, $graph, $target, $reason, $actor, $actorId, $subject, $binding) {
            $task->forceFill([
                'status' => WorkflowTaskStatus::Completed->value,
                'outcome' => 'return',
                'completed_at' => now(),
                'completed_by' => $actorId,
                'assignee_id' => $task->assignee_id ?? $actorId,
                'comments' => $reason,
            ])->save();

            // A return abandons the whole round, including any parallel branch
            // still open: the thing being reviewed is going back to its author,
            // so a colleague's concurrent approval of the old version is moot.
            $this->cancelOpenTasks($instance, $task->id, 'Returned for rework.');

            $this->log($instance, $task, $task->node_code, 'return', $actorId, $reason);

            $instance->forceFill([
                'current_nodes' => [],
                'context' => array_merge((array) $instance->context, ['_joins' => []]),
                'status' => WorkflowInstanceStatus::Active->value,
            ])->save();

            if ($subject !== null && $binding !== null) {
                $binding->onReturned($subject, $instance, $actor, $reason);
            }

            $this->run($instance, [$target], $graph, $subject, $actor);

            return $instance->refresh();
        });
    }

    public function cancel(WorkflowInstance $instance, ?string $reason = null, ?User $actor = null): WorkflowInstance
    {
        if (! $instance->isOpen()) {
            return $instance;
        }

        $subject = $this->subjectOf($instance);

        return DB::transaction(function () use ($instance, $reason, $actor, $subject) {
            $this->cancelOpenTasks($instance, null, $reason);

            $instance->forceFill([
                'status' => WorkflowInstanceStatus::Cancelled->value,
                'current_nodes' => [],
                'completed_at' => now(),
                'cancellation_reason' => $reason,
                'cancelled_by' => $actor?->id ?? auth()->id(),
            ])->save();

            $this->log($instance, null, null, 'cancel', $actor?->id ?? auth()->id(), $reason);

            if ($subject !== null) {
                $this->subjects->for($subject)?->onCancelled($subject, $instance, $actor, $reason);
            }

            return $instance->refresh();
        });
    }

    /* ================================================================== */
    /*  Execution */
    /* ================================================================== */

    /**
     * Walk the graph from a set of entry nodes until every branch is parked on
     * a human node or finished.
     *
     * @param  list<string>  $entryNodes
     */
    private function run(WorkflowInstance $instance, array $entryNodes, WorkflowGraph $graph, ?Model $subject, ?User $actor): void
    {
        $queue = $entryNodes;
        $guard = 0;

        while ($queue !== []) {
            // A definition with a condition-free cycle would spin forever and
            // take the request with it. The validator rejects the shapes that
            // cause it; this is the belt to that pair of braces.
            if (++$guard > 200) {
                Log::error('Workflow execution exceeded 200 steps; stopping to avoid a loop.', [
                    'instance_id' => $instance->id,
                ]);
                break;
            }

            $code = array_shift($queue);
            $node = $graph->node($code);

            if ($node === null) {
                Log::warning('Workflow edge points at a node that does not exist.', [
                    'instance_id' => $instance->id,
                    'node' => $code,
                ]);

                continue;
            }

            $type = WorkflowNodeType::tryFrom($node['type'] ?? '') ?? WorkflowNodeType::Task;

            if ($type->waitsForHuman()) {
                $this->enter($instance, $code);
                $this->raiseTask($instance, $node, $subject);

                continue;
            }

            foreach ($this->execute($instance, $node, $type, $graph, $subject, $actor) as $followUp) {
                $queue[] = $followUp;
            }
        }

        $this->settle($instance);
    }

    /**
     * Run a non-human node and return the nodes control passes to next.
     *
     * @return list<string>
     */
    private function execute(
        WorkflowInstance $instance,
        array $node,
        WorkflowNodeType $type,
        WorkflowGraph $graph,
        ?Model $subject,
        ?User $actor,
    ): array {
        $code = $node['code'];

        return match ($type) {
            WorkflowNodeType::End => $this->finish($instance, $node, $subject, $actor),

            WorkflowNodeType::ParallelGateway => $this->fork($instance, $graph, $code),

            WorkflowNodeType::Join => $this->join($instance, $graph, $code),

            WorkflowNodeType::Timer => $this->wait($instance, $node, $graph),

            WorkflowNodeType::Notification => $this->announce($instance, $node, $subject, $graph),

            WorkflowNodeType::Escalation => $this->escalateBranch($instance, $node, $graph, $actor),

            // start, exclusive_gateway, service_task and sub_process all reduce
            // to "evaluate the outgoing edges and go". A service_task with an
            // unrecognised `service` is a no-op rather than a failure: the
            // engine refusing to advance would strand the instance, which is a
            // worse outcome than a step that did nothing and said so.
            default => $this->pass($instance, $node, $graph, $type),
        };
    }

    /** @return list<string> */
    private function pass(WorkflowInstance $instance, array $node, WorkflowGraph $graph, WorkflowNodeType $type): array
    {
        if ($type === WorkflowNodeType::ServiceTask) {
            Log::info('Workflow service task passed through.', [
                'instance_id' => $instance->id,
                'node' => $node['code'],
                'service' => $node['service'] ?? null,
            ]);
        }

        $this->leave($instance, $node['code']);

        return $this->nextNodes($graph, $node['code'], null, $instance, null);
    }

    /**
     * A parallel gateway activates EVERY outgoing edge whose condition holds.
     *
     * @return list<string>
     */
    private function fork(WorkflowInstance $instance, WorkflowGraph $graph, string $code): array
    {
        $this->leave($instance, $code);

        $targets = [];

        foreach ($graph->edgesFrom($code) as $edge) {
            if ($this->conditions->evaluate($edge['when'] ?? null, $this->variables($instance, null, null))) {
                $targets[] = $edge['to'];
            }
        }

        return $targets;
    }

    /**
     * A join waits for all its incoming branches, then emits once.
     *
     * Arrivals are counted in context['_joins'][node]; the branch that
     * completes the count carries on and the others stop here. Without the
     * count a two-branch fork would run everything after the join twice, which
     * on an approval means two decisions recorded for one review.
     *
     * @return list<string>
     */
    private function join(WorkflowInstance $instance, WorkflowGraph $graph, string $code): array
    {
        $joins = (array) $instance->contextValue('_joins', []);
        $arrived = (int) ($joins[$code] ?? 0) + 1;
        $joins[$code] = $arrived;

        $instance->forceFill([
            'context' => array_merge((array) $instance->context, ['_joins' => $joins]),
        ])->save();

        $this->leave($instance, $code);

        if ($arrived < $graph->joinArity($code)) {
            // Park the join itself as active so the instance is not mistaken
            // for finished while the other branches are still running.
            $this->enter($instance, $code);

            return [];
        }

        $joins[$code] = 0;
        $instance->forceFill([
            'context' => array_merge((array) $instance->context, ['_joins' => $joins]),
        ])->save();

        return $this->nextNodes($graph, $code, null, $instance, null);
    }

    /**
     * A timer parks the branch until its due time.
     *
     * The SLA sweeper releases it. The instance's sla_due_at is the earliest
     * unreleased timer, so a single indexed query finds everything due.
     *
     * @return list<string>
     */
    private function wait(WorkflowInstance $instance, array $node, WorkflowGraph $graph): array
    {
        $hours = (float) ($node['wait_hours'] ?? $node['sla_hours'] ?? 0);

        if ($hours <= 0) {
            return $this->pass($instance, $node, $graph, WorkflowNodeType::Timer);
        }

        $due = now()->addMinutes((int) round($hours * 60));

        $this->enter($instance, $node['code']);

        $context = (array) $instance->context;
        $context['_timers'][$node['code']] = $due->toIso8601String();

        $instance->forceFill([
            'context' => $context,
            'sla_due_at' => $instance->sla_due_at && $instance->sla_due_at->lt($due) ? $instance->sla_due_at : $due,
        ])->save();

        return [];
    }

    /** @return list<string> */
    private function announce(WorkflowInstance $instance, array $node, ?Model $subject, WorkflowGraph $graph): array
    {
        $resolution = $this->assignees->resolve($node, $instance, $subject, $this->subjects->forType($instance->entity_type));
        $recipients = $this->assignees->recipients($resolution, $instance->organization_id);

        $label = $this->instanceLabel($instance, $subject);

        foreach ($recipients as $userId) {
            NotificationService::send(
                $instance->organization_id,
                $userId,
                'workflow_notification',
                ($node['name'] ?? 'Workflow update').': '.$label,
                $node['message'] ?? "The workflow for {$label} has reached the {$node['code']} step.",
                ['workflow_instance_id' => $instance->id, 'entity_type' => $instance->entity_type, 'entity_id' => $instance->entity_id],
            );
        }

        return $this->pass($instance, $node, $graph, WorkflowNodeType::Notification);
    }

    /**
     * An escalation node escalates every open task on the instance and then
     * carries on — the "shout about it and keep going" branch of a timer.
     *
     * @return list<string>
     */
    private function escalateBranch(WorkflowInstance $instance, array $node, WorkflowGraph $graph, ?User $actor): array
    {
        foreach ($instance->openTasks()->get() as $open) {
            $this->escalate($open, $node['message'] ?? 'Escalated by the workflow.', $actor);
        }

        return $this->pass($instance, $node, $graph, WorkflowNodeType::Escalation);
    }

    /**
     * Reaching an end node finishes the instance and tells the subject binding
     * what the answer was.
     *
     * @return list<string>
     */
    private function finish(WorkflowInstance $instance, array $node, ?Model $subject, ?User $actor): array
    {
        $outcome = $node['outcome'] ?? 'completed';

        $this->leave($instance, $node['code']);
        $this->cancelOpenTasks($instance, null, 'The workflow reached '.($node['name'] ?? $node['code']).'.');

        $status = match ($outcome) {
            'rejected', 'reject' => WorkflowInstanceStatus::Rejected,
            'cancelled' => WorkflowInstanceStatus::Cancelled,
            default => WorkflowInstanceStatus::Completed,
        };

        $instance->forceFill([
            'status' => $status->value,
            'outcome' => $outcome,
            'completed_at' => now(),
            'current_nodes' => [],
            'sla_due_at' => null,
        ])->save();

        $this->log($instance, null, $node['code'], 'complete', $actor?->id ?? auth()->id(), null);

        if ($subject !== null) {
            $binding = $this->subjects->for($subject);
            $comments = $this->lastComment($instance);

            match ($status) {
                WorkflowInstanceStatus::Rejected => $binding?->onRejected($subject, $instance, $actor, $comments),
                WorkflowInstanceStatus::Cancelled => $binding?->onCancelled($subject, $instance, $actor, $comments),
                default => $binding?->onApproved($subject, $instance, $actor, $comments),
            };

            AuditTrailService::record(
                $subject,
                'workflow_'.$outcome,
                null,
                null,
                null,
                'Workflow '.($instance->definition?->name ?? $instance->definition?->code).' finished: '.$outcome.'.',
            );
        }

        return [];
    }

    /* ================================================================== */
    /*  Tasks */
    /* ================================================================== */

    private function raiseTask(WorkflowInstance $instance, array $node, ?Model $subject): WorkflowTask
    {
        $binding = $this->subjects->for($subject);
        $resolution = $this->assignees->resolve($node, $instance, $subject, $binding);

        $slaHours = isset($node['sla_hours']) ? (float) $node['sla_hours'] : null;

        $task = WorkflowTask::withoutGlobalScopes()->create([
            'organization_id' => $instance->organization_id,
            'instance_id' => $instance->id,
            'node_code' => $node['code'],
            'node_name' => $node['name'] ?? $node['code'],
            'node_type' => $node['type'] ?? WorkflowNodeType::Approval->value,
            'assignee_id' => $resolution['assignee_id'],
            'assignee_role' => $resolution['assignee_role'],
            'candidate_roles' => $resolution['candidate_roles'],
            'candidate_user_ids' => $resolution['candidate_user_ids'],
            'status' => WorkflowTaskStatus::Pending->value,
            'due_at' => $slaHours !== null && $slaHours > 0
                ? now()->addMinutes((int) round($slaHours * 60))
                : null,
        ]);

        if ($task->due_at !== null) {
            $instance->forceFill([
                'sla_due_at' => $instance->sla_due_at && $instance->sla_due_at->lt($task->due_at)
                    ? $instance->sla_due_at
                    : $task->due_at,
            ])->save();
        }

        $this->log($instance, $task, $node['code'], 'assign', null, null, $resolution['assignee_id']);

        $recipients = $this->assignees->recipients($resolution, $instance->organization_id);

        $this->notify(
            $recipients,
            $task,
            'Action required: '.$this->taskLabel($task),
            ($node['instructions'] ?? 'A decision is waiting for you.')
            .($task->due_at ? "\n\nDue by ".$task->due_at->format('d M Y H:i').'.' : ''),
        );

        if ($subject !== null) {
            $binding?->onTaskRaised($subject, $task);
        }

        return $task;
    }

    private function cancelOpenTasks(WorkflowInstance $instance, ?int $exceptTaskId, ?string $reason): void
    {
        $query = $instance->openTasks();

        if ($exceptTaskId !== null) {
            $query->whereKeyNot($exceptTaskId);
        }

        foreach ($query->get() as $open) {
            $open->forceFill([
                'status' => WorkflowTaskStatus::Cancelled->value,
                'completed_at' => now(),
                'comments' => $reason ?? $open->comments,
            ])->save();

            $this->log($instance, $open, $open->node_code, 'cancel', null, $reason);
        }
    }

    /* ================================================================== */
    /*  SLA */
    /* ================================================================== */

    /**
     * Act on everything whose time is up, for one organization.
     *
     * Called by workflow:sweep-slas. Honours the node's on_timeout —
     * escalate | auto_approve | auto_reject | notify — and reads
     * workflow_definitions.escalation_rules, which nothing read before.
     *
     * @return array{breached:int, escalated:int, auto_decided:int, timers_released:int}
     */
    public function sweep(?Carbon $now = null): array
    {
        $now = $now ?? now();
        $counts = ['breached' => 0, 'escalated' => 0, 'auto_decided' => 0, 'timers_released' => 0];

        // The grace window stops a task being escalated the instant its due
        // time passes while its assignee is mid-decision on it.
        $cutoff = $now->copy()->subMinutes((int) config('workflow.sla.grace_minutes', 15));

        $due = WorkflowTask::withoutGlobalScopes()
            ->whereIn('status', WorkflowTaskStatus::openValues())
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $cutoff)
            ->with('instance.definition')
            ->orderBy('due_at')
            ->get();

        foreach ($due as $task) {
            $instance = $task->instance;

            if ($instance === null || ! $instance->isOpen()) {
                continue;
            }

            $counts['breached']++;

            $node = $instance->definitionSnapshot()->node($task->node_code) ?? [];
            $action = $this->timeoutAction($node, $instance, $task, $now);

            match ($action) {
                'auto_approve' => $this->autoDecide($task, 'approve', $counts),
                'auto_reject' => $this->autoDecide($task, 'reject', $counts),
                'notify' => $this->remind($task),
                default => $this->sweepEscalate($task, $counts),
            };
        }

        $counts['timers_released'] = $this->releaseTimers($now);

        return $counts;
    }

    /**
     * What to do when a task runs out of time.
     *
     * Node-level on_timeout wins. Otherwise the definition's escalation_rules
     * are consulted: a list of {after_hours, action, escalate_to} matched on
     * how long the task has been overdue, most specific (longest) first. THIS
     * IS THE FIRST CODE IN THE PLATFORM THAT READS THAT COLUMN.
     */
    private function timeoutAction(array $node, WorkflowInstance $instance, WorkflowTask $task, Carbon $now): string
    {
        if (isset($node['on_timeout'])) {
            return (string) $node['on_timeout'];
        }

        $overdueHours = $task->due_at ? $task->due_at->diffInMinutes($now) / 60 : 0;

        $rules = collect((array) ($instance->definition?->escalation_rules ?? []))
            ->filter(fn ($rule) => is_array($rule))
            ->sortByDesc(fn (array $rule) => (float) ($rule['after_hours'] ?? 0));

        foreach ($rules as $rule) {
            $applies = $rule['node'] ?? null;

            if ($applies !== null && $applies !== $task->node_code) {
                continue;
            }

            if ($overdueHours >= (float) ($rule['after_hours'] ?? 0)) {
                return (string) ($rule['action'] ?? 'escalate');
            }
        }

        return 'escalate';
    }

    private function sweepEscalate(WorkflowTask $task, array &$counts): void
    {
        // Escalating the same task every hour turns an escalation into spam and
        // teaches people to filter it. One escalation per level per task.
        if ($task->status === WorkflowTaskStatus::Escalated) {
            return;
        }

        TenantContext::actingAs($task->organization_id, function () use ($task) {
            $this->escalate($task, 'The service level for this decision has been exceeded.', null);
        });

        $counts['escalated']++;
    }

    private function autoDecide(WorkflowTask $task, string $outcome, array &$counts): void
    {
        TenantContext::actingAs($task->organization_id, function () use ($task, $outcome) {
            $this->advance($task, $outcome, [
                'comments' => 'Decided automatically: the step timed out and its definition says to '
                    .str_replace('_', ' ', 'auto_'.$outcome).'.',
                'auto_decided' => true,
            ], null);
        });

        $counts['auto_decided']++;
    }

    private function remind(WorkflowTask $task): void
    {
        $recipients = $this->assignees->recipients([
            'assignee_id' => $task->assignee_id,
            'candidate_roles' => (array) $task->candidate_roles,
            'candidate_user_ids' => (array) $task->candidate_user_ids,
        ], $task->organization_id);

        $this->notify(
            $recipients,
            $task,
            'Overdue: '.$this->taskLabel($task),
            'This decision passed its due date on '.optional($task->due_at)->format('d M Y H:i').'.',
        );
    }

    /** Release timer nodes whose wait has elapsed. */
    private function releaseTimers(Carbon $now): int
    {
        $released = 0;

        $waiting = WorkflowInstance::withoutGlobalScopes()
            ->open()
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<=', $now)
            ->with('definition')
            ->get();

        foreach ($waiting as $instance) {
            $timers = (array) $instance->contextValue('_timers', []);

            foreach ($timers as $code => $dueAt) {
                if (! in_array($code, $instance->currentNodeCodes(), true) || Carbon::parse($dueAt)->gt($now)) {
                    continue;
                }

                TenantContext::actingAs($instance->organization_id, function () use ($instance, $code) {
                    $graph = $instance->definitionSnapshot();
                    $this->leave($instance, $code);

                    $context = (array) $instance->context;
                    unset($context['_timers'][$code]);
                    $instance->forceFill(['context' => $context])->save();

                    $this->run(
                        $instance,
                        $this->nextNodes($graph, $code, null, $instance, null),
                        $graph,
                        $this->subjectOf($instance),
                        null,
                    );
                });

                $released++;
            }
        }

        return $released;
    }

    /* ================================================================== */
    /*  Graph helpers */
    /* ================================================================== */

    /**
     * The nodes control passes to after leaving $from with $outcome.
     *
     * Exclusive: the first satisfied edge in document order. Anything else:
     * every satisfied edge — which for a plain node with one outgoing edge is
     * the same thing, and for a fork is the point.
     *
     * @return list<string>
     */
    private function nextNodes(
        WorkflowGraph $graph,
        string $from,
        ?string $outcome,
        WorkflowInstance $instance,
        ?WorkflowTask $task,
    ): array {
        $exclusive = $graph->typeOf($from) !== WorkflowNodeType::ParallelGateway;
        $variables = $this->variables($instance, $outcome, $task);
        $targets = [];

        foreach ($graph->edgesFrom($from) as $edge) {
            if (! $this->conditions->evaluate($edge['when'] ?? null, $variables)) {
                continue;
            }

            $targets[] = $edge['to'];

            if ($exclusive) {
                break;
            }
        }

        if ($targets === [] && $graph->edgesFrom($from) !== []) {
            Log::warning('No workflow edge condition matched; the branch stops here.', [
                'instance_id' => $instance->id,
                'from' => $from,
                'outcome' => $outcome,
            ]);
        }

        return $targets;
    }

    /** @return array<string, mixed> */
    private function variables(WorkflowInstance $instance, ?string $outcome, ?WorkflowTask $task): array
    {
        return [
            'outcome' => $outcome,
            'context' => (array) $instance->context,
            'subject' => (array) $instance->contextValue('subject', []),
            'task' => $task === null ? [] : [
                'node_code' => $task->node_code,
                'assignee_id' => $task->assignee_id,
                'overdue' => $task->isOverdue(),
            ],
            'escalated' => $instance->breached_at !== null,
        ];
    }

    /** The nearest earlier human node, used when a return names no target. */
    private function firstHumanPredecessor(WorkflowGraph $graph, string $from): ?string
    {
        $queue = array_column($graph->edgesTo($from), 'from');
        $seen = [$from];

        while ($queue !== []) {
            $code = array_shift($queue);

            if (in_array($code, $seen, true)) {
                continue;
            }

            $seen[] = $code;

            if ($graph->typeOf($code)?->waitsForHuman()) {
                return $code;
            }

            foreach ($graph->edgesTo($code) as $edge) {
                $queue[] = $edge['from'];
            }
        }

        // Nothing human upstream: return to the start, which re-runs the
        // process rather than stranding the instance.
        return $graph->startNode()['code'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $payload
     */
    private function assertRequiredFields(array $node, array $payload): void
    {
        foreach ((array) ($node['required_fields'] ?? []) as $field) {
            if (blank($payload[$field] ?? null)) {
                throw new RuntimeException("The [{$field}] field is required at this step.");
            }
        }
    }

    /* ================================================================== */
    /*  State bookkeeping */
    /* ================================================================== */

    private function enter(WorkflowInstance $instance, string $code): void
    {
        $nodes = $instance->currentNodeCodes();

        if (! in_array($code, $nodes, true)) {
            $nodes[] = $code;
            $instance->forceFill(['current_nodes' => array_values($nodes)])->save();
        }
    }

    private function leave(WorkflowInstance $instance, string $code): void
    {
        $nodes = array_values(array_filter($instance->currentNodeCodes(), fn (string $c) => $c !== $code));

        $instance->forceFill(['current_nodes' => $nodes])->save();
    }

    /**
     * An instance with nothing active and no open task has nowhere left to go.
     *
     * That is a definition bug (a branch with no end node), and leaving it
     * "active" forever would hide it. Marking it completed with a null outcome
     * makes it findable, and the warning names the definition.
     */
    private function settle(WorkflowInstance $instance): void
    {
        $instance->refresh();

        if (! $instance->isOpen() || $instance->currentNodeCodes() !== []) {
            return;
        }

        if ($instance->openTasks()->exists()) {
            return;
        }

        Log::warning('Workflow instance ran out of nodes without reaching an end node.', [
            'instance_id' => $instance->id,
            'definition' => $instance->definition?->code,
        ]);

        $instance->forceFill([
            'status' => WorkflowInstanceStatus::Completed->value,
            'outcome' => null,
            'completed_at' => now(),
        ])->save();
    }

    /* ================================================================== */
    /*  Odds and ends */
    /* ================================================================== */

    private function subjectOf(WorkflowInstance $instance): ?Model
    {
        try {
            return $instance->entity()->withoutGlobalScopes()->first();
        } catch (\Throwable $e) {
            Log::warning('Workflow subject could not be resolved.', [
                'instance_id' => $instance->id,
                'entity_type' => $instance->entity_type,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** @param array{rule:string, config:array<string, mixed>} $_ */
    private function escalationTarget(array $node, WorkflowInstance $instance): array
    {
        $target = $node['escalate_to'] ?? null;

        if (is_array($target) && $target !== []) {
            return [
                'rule' => $target['rule'] ?? 'role',
                'config' => $target['config'] ?? $target,
            ];
        }

        foreach ((array) ($instance->definition?->escalation_rules ?? []) as $rule) {
            if (is_array($rule) && isset($rule['escalate_to'])) {
                return [
                    'rule' => $rule['escalate_to']['rule'] ?? 'role',
                    'config' => $rule['escalate_to']['config'] ?? $rule['escalate_to'],
                ];
            }
        }

        return ['rule' => 'role', 'config' => ['roles' => (array) config('workflow.default_escalation_roles', [])]];
    }

    private function log(
        WorkflowInstance $instance,
        ?WorkflowTask $task,
        ?string $nodeCode,
        string $action,
        ?int $actorId,
        ?string $comments,
        ?int $delegatedTo = null,
    ): void {
        WorkflowAction::create([
            'instance_id' => $instance->id,
            'task_id' => $task?->id,
            'stage' => $instance->current_stage,
            'stage_name' => $task?->node_name ?? $nodeCode,
            'node_code' => $nodeCode,
            'actor_id' => $actorId,
            'action' => $action,
            'comments' => $comments,
            'delegated_to' => $delegatedTo,
            'acted_at' => now(),
        ]);
    }

    /** @param list<int> $userIds */
    private function notify(array $userIds, WorkflowTask $task, string $subject, string $body): void
    {
        foreach (array_unique($userIds) as $userId) {
            NotificationService::send(
                $task->organization_id,
                $userId,
                'workflow_task',
                $subject,
                $body,
                [
                    'workflow_task_id' => $task->id,
                    'workflow_instance_id' => $task->instance_id,
                    'entity_type' => $task->instance?->entity_type,
                    'entity_id' => $task->instance?->entity_id,
                ],
                route('risk.my-tasks.index'),
                $task->isOverdue() ? 'high' : 'medium',
            );
        }
    }

    private function taskLabel(WorkflowTask $task): string
    {
        $instance = $task->instance;

        return trim(($task->node_name ?? $task->node_code).' — '.$this->instanceLabel($instance, $this->subjectOf($instance)));
    }

    private function instanceLabel(WorkflowInstance $instance, ?Model $subject): string
    {
        if ($subject === null) {
            return $instance->entity_type.' #'.$instance->entity_id;
        }

        return $this->subjects->for($subject)?->label($subject)
            ?? class_basename($subject).' #'.$subject->getKey();
    }

    private function lastComment(WorkflowInstance $instance): ?string
    {
        return $instance->tasks()
            ->whereNotNull('comments')
            ->orderByDesc('completed_at')
            ->value('comments');
    }
}

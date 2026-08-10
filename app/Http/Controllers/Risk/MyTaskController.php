<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkflowTask;
use App\Services\Workflow\TaskQueryService;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * WP-06 TASK 5 — My Tasks.
 *
 * One queue for every decision a person owes, whatever module it came from.
 * Deciding here does exactly what deciding on the module's own screen does,
 * because both call the same engine.
 */
class MyTaskController extends Controller
{
    public function __construct(
        private TaskQueryService $tasks,
        private WorkflowEngine $engine,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $filters = [
            'overdue' => $request->boolean('overdue'),
            'status' => $request->string('status')->toString() ?: null,
            'definition' => $request->string('definition')->toString() ?: null,
        ];

        $tasks = $this->tasks->paginateFor($user, array_filter($filters), 25);
        $counts = $this->tasks->countsFor($user);

        // Each task's subject, resolved once here rather than per row in the
        // view: the alternative is a query per task and a list screen that
        // slows down as somebody's queue grows.
        $subjects = $this->resolveSubjects($tasks->getCollection());

        return view('risk.my-tasks.index', compact('tasks', 'counts', 'subjects', 'filters'));
    }

    public function show(WorkflowTask $task)
    {
        $this->authorizeTenant($task);

        $task->load(['instance.definition', 'instance.tasks.assignee', 'instance.actions.actor', 'assignee']);

        return view('risk.my-tasks.show', [
            'task' => $task,
            'node' => $task->node(),
            'subject' => $task->instance?->entity()->withoutGlobalScopes()->first(),
            'canAct' => $this->engine->canAct($task, auth()->user()),
            'delegates' => $this->delegateOptions($task),
        ]);
    }

    public function act(Request $request, WorkflowTask $task)
    {
        $this->authorizeTenant($task);

        $validated = $request->validate([
            'outcome' => 'required|string|max:30',
            'comments' => 'nullable|string|max:3000',
        ]);

        abort_unless($this->engine->canAct($task, $request->user()), 403,
            'This decision is not yours to make.');

        try {
            $this->engine->advance($task, $validated['outcome'], $validated, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('risk.my-tasks.index')
            ->with('success', 'Recorded: '.$validated['outcome'].'.');
    }

    public function delegate(Request $request, WorkflowTask $task)
    {
        $this->authorizeTenant($task);

        $validated = $request->validate([
            'delegate_to' => 'required|integer|exists:users,id',
            'reason' => 'required|string|max:1000',
        ]);

        abort_unless($this->engine->canAct($task, $request->user()), 403,
            'This decision is not yours to delegate.');

        $to = User::where('organization_id', TenantContext::organizationId())
            ->findOrFail($validated['delegate_to']);

        try {
            $this->engine->delegate($task, $to, $validated['reason'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Delegated to '.$to->name.'. It is now on their list, not yours.');
    }

    public function returnForRework(Request $request, WorkflowTask $task)
    {
        $this->authorizeTenant($task);

        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
            'to_node' => 'nullable|string|max:80',
        ]);

        abort_unless($this->engine->canAct($task, $request->user()), 403,
            'This decision is not yours to return.');

        try {
            $this->engine->returnForRework($task, $validated['to_node'] ?? null, $validated['reason'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('risk.my-tasks.index')
            ->with('success', 'Returned for rework. It is back with the previous step.');
    }

    private function authorizeTenant(WorkflowTask $task): void
    {
        abort_unless($task->organization_id === TenantContext::organizationId(), 403);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, WorkflowTask>  $tasks
     * @return array<string, \Illuminate\Database\Eloquent\Model>
     */
    private function resolveSubjects($tasks): array
    {
        $subjects = [];

        $tasks->groupBy(fn (WorkflowTask $task) => $task->instance?->entity_type)
            ->each(function ($group, $entityType) use (&$subjects) {
                if (blank($entityType)) {
                    return;
                }

                $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($entityType);

                if ($class === null || ! class_exists($class)) {
                    return;
                }

                $ids = $group->map(fn (WorkflowTask $task) => $task->instance?->entity_id)->filter()->unique();

                foreach ($class::query()->whereIn('id', $ids)->get() as $model) {
                    $subjects[$entityType.':'.$model->getKey()] = $model;
                }
            });

        return $subjects;
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function delegateOptions(WorkflowTask $task)
    {
        return User::where('organization_id', $task->organization_id)
            ->where('is_active', true)
            ->whereKeyNot(auth()->id())
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}

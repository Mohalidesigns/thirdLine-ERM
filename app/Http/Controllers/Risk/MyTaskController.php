<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\ActOnTaskRequest;
use App\Http\Requests\Workflow\DelegateTaskRequest;
use App\Http\Requests\Workflow\ReturnTaskRequest;
use App\Models\User;
use App\Models\WorkflowTask;
use App\Presenters\WorkflowPresenter;
use App\Services\Workflow\TaskQueryService;
use App\Services\Workflow\TaskSubjectResolver;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use RuntimeException;

/**
 * WP-06 TASK 5 — My Tasks.
 *
 * One queue for every decision a person owes, whatever module it came from.
 * Deciding here does exactly what deciding on the module's own screen does,
 * because both call the same engine. Migration Phase 3.7: Inertia pages,
 * Form Requests, WorkflowTaskPolicy.
 */
class MyTaskController extends Controller
{
    public function __construct(
        private readonly TaskQueryService $tasks,
        private readonly WorkflowEngine $engine,
        private readonly TaskSubjectResolver $subjects,
        private readonly WorkflowPresenter $presenter,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', WorkflowTask::class);

        $user = $request->user();

        $filters = [
            'overdue' => $request->boolean('overdue'),
            'status' => $request->string('status')->toString() ?: null,
            'definition' => $request->string('definition')->toString() ?: null,
        ];

        $tasks = $this->tasks->paginateFor($user, array_filter($filters), 25);

        // Each task's subject, resolved once here rather than per row.
        $subjects = $this->subjects->resolve($tasks->getCollection());

        return Inertia::render('MyTasks/Index', [
            'tasks' => $this->presenter->paginate($tasks, fn (WorkflowTask $task) => $this->presenter->taskRow($task, $subjects)),
            'counts' => $this->tasks->countsFor($user),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, WorkflowTask $task)
    {
        Gate::authorize('view', $task);

        $task->load(['instance.definition', 'instance.tasks.assignee', 'instance.actions.actor', 'assignee']);

        $node = $task->node() ?? [];
        $subject = $task->instance?->entity()->withoutGlobalScopes()->first();
        $canAct = $this->engine->canAct($task, $request->user());

        return Inertia::render('MyTasks/Show', [
            'task' => $this->presenter->taskRow($task, $subject ? [$task->instance?->entity_type.':'.$subject->getKey() => $subject] : []) + [
                'due_full' => $task->due_at?->format('d M Y H:i'),
                'instructions' => $node['instructions'] ?? null,
                'comments_required' => in_array('comments', (array) ($node['required_fields'] ?? []), true),
                'allow_delegate' => ($node['allow_delegate'] ?? true) !== false,
                'allow_return' => ($node['allow_return'] ?? true) !== false,
            ],
            'canAct' => $canAct,
            'delegates' => $canAct ? $this->presenter->delegateOptions($task, $request->user()) : [],
            'steps' => ($task->instance ? $task->instance->tasks : collect())->map(fn (WorkflowTask $t) => $this->presenter->step($t))->values()->all(),
            'history' => ($task->instance ? $task->instance->actions : collect())->map(fn ($a) => $this->presenter->action($a))->values()->all(),
            'urls' => [
                'index' => route('risk.my-tasks.index'),
                'act' => route('risk.my-tasks.act', $task),
                'delegate' => route('risk.my-tasks.delegate', $task),
                'return' => route('risk.my-tasks.return', $task),
            ],
        ]);
    }

    public function act(ActOnTaskRequest $request, WorkflowTask $task)
    {
        $validated = $request->validated();

        try {
            $this->engine->advance($task, $validated['outcome'], $validated, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('risk.my-tasks.index')
            ->with('success', 'Recorded: '.$validated['outcome'].'.');
    }

    public function delegate(DelegateTaskRequest $request, WorkflowTask $task)
    {
        $validated = $request->validated();
        $to = User::query()->findOrFail($validated['delegate_to']);

        try {
            $this->engine->delegate($task, $to, $validated['reason'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Delegated to '.$to->name.'. It is now on their list, not yours.');
    }

    public function returnForRework(ReturnTaskRequest $request, WorkflowTask $task)
    {
        $validated = $request->validated();

        try {
            $this->engine->returnForRework($task, $validated['to_node'] ?? null, $validated['reason'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('risk.my-tasks.index')
            ->with('success', 'Returned for rework. It is back with the previous step.');
    }
}

<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\ActOnInstanceRequest;
use App\Http\Requests\Workflow\StartWorkflowRequest;
use App\Http\Requests\Workflow\StoreWorkflowDefinitionRequest;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Presenters\WorkflowPresenter;
use App\Services\Workflow\StartableSubjects;
use App\Services\Workflow\WorkflowDashboardService;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowPublisher;
use App\Support\MorphTypes;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use RuntimeException;

/**
 * Workflow dashboard, definitions and instances (migration Phase 3.7).
 * The designer (createDefinition / editDefinition) stays a Livewire screen
 * until Phase 6.
 */
class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly WorkflowPublisher $publisher,
        private readonly WorkflowDashboardService $dashboard,
        private readonly StartableSubjects $startable,
        private readonly WorkflowPresenter $presenter,
    ) {}

    public function dashboard(Request $request)
    {
        Gate::authorize('viewAny', WorkflowInstance::class);

        $organizationId = (int) TenantContext::organizationId();

        return Inertia::render('Workflows/Dashboard', [
            'stats' => $this->dashboard->stats($organizationId, $request->user()),
            'recentInstances' => $this->dashboard->recentInstances($organizationId)
                ->map(fn (WorkflowInstance $i) => $this->presenter->recentInstance($i))
                ->values()
                ->all(),
            'workload' => $this->dashboard->workload($organizationId),
            'urls' => [
                'myTasks' => route('risk.my-tasks.index'),
                'newDefinition' => route('risk.workflows.create-definition'),
            ],
        ]);
    }

    public function definitions()
    {
        Gate::authorize('viewAny', WorkflowDefinition::class);

        $organizationId = (int) TenantContext::organizationId();
        $startOptions = $this->startable->options($organizationId);

        $definitions = WorkflowDefinition::query()
            ->where('organization_id', $organizationId)
            ->with('creator', 'publisher')
            ->orderBy('code')
            ->orderByDesc('version')
            ->paginate(25);

        return Inertia::render('Workflows/Definitions', [
            'definitions' => $this->presenter->paginate($definitions, fn (WorkflowDefinition $d) => $this->presenter->definitionRow($d, $startOptions)),
            'canManage' => Gate::allows('create', WorkflowDefinition::class),
            'urls' => [
                'newDefinition' => route('risk.workflows.create-definition'),
                'start' => route('risk.workflows.start'),
            ],
        ]);
    }

    /**
     * The designer. The old create-definition form built a linear stage list;
     * the graph designer replaces it, and the route name is kept so existing
     * links do not break. Livewire until Phase 6.
     */
    public function createDefinition(Request $request)
    {
        return view('risk.workflows.designer', [
            'definitionId' => $request->integer('definition') ?: null,
        ]);
    }

    public function editDefinition(WorkflowDefinition $definition)
    {
        Gate::authorize('update', $definition);

        // A published version is immutable; editing it opens a draft of the
        // next version so running instances keep the graph they started on.
        $draft = $definition->is_published
            ? $this->publisher->draftNewVersion($definition, auth()->user())
            : $definition;

        return view('risk.workflows.designer', ['definitionId' => $draft->id]);
    }

    /**
     * Kept for the legacy linear form and for API-shaped posts. The designer
     * saves through Livewire rather than here.
     */
    public function storeDefinition(StoreWorkflowDefinitionRequest $request)
    {
        $definition = $this->publisher->saveDraft(null, $request->validated(), $request->user());

        return redirect()
            ->route('risk.workflows.edit-definition', $definition)
            ->with('success', 'Draft saved. Publish it when the process is complete.');
    }

    public function publishDefinition(WorkflowDefinition $definition)
    {
        Gate::authorize('publish', $definition);

        try {
            $this->publisher->publish($definition, auth()->user());
        } catch (RuntimeException $e) {
            return back()->with('error', "This workflow cannot be published yet:\n".$e->getMessage());
        }

        return back()->with('success', "Published version {$definition->version}. New instances start on it; "
            .'anything already running stays on the version it began with.');
    }

    public function unpublishDefinition(WorkflowDefinition $definition)
    {
        Gate::authorize('publish', $definition);

        $this->publisher->unpublish($definition);

        return back()->with('success', 'Unpublished. No new instances will start on this version.');
    }

    public function startWorkflow(StartWorkflowRequest $request)
    {
        $validated = $request->validated();

        $definition = WorkflowDefinition::query()->findOrFail($validated['definition_id']);

        Gate::authorize('start', $definition);

        $entityType = MorphTypes::normalise($validated['entity_type']);

        abort_if($entityType === null, 422, "Unknown workflow entity type [{$validated['entity_type']}].");

        if ($entityType !== $definition->entity_type) {
            return back()->with('error', "That workflow runs over {$definition->entity_type} records, not {$entityType}.");
        }

        $class = Relation::getMorphedModel($entityType);
        $subject = $class::query()->findOrFail($validated['entity_id']);

        if ($this->engine->openInstanceFor($subject) !== null) {
            return back()->with('error', 'That record already has a workflow running. Finish or cancel it first.');
        }

        if (! $definition->isGraphBased()) {
            return back()->with('error', 'That definition has no steps drawn yet. Open it in the designer first.');
        }

        $this->engine->start($definition, $subject, [], $request->user());

        return back()->with('success', 'Workflow started.');
    }

    /**
     * Act on the instance from its own screen.
     *
     * Every action goes through the engine, which is the point of WP-06: the
     * previous version of this method wrote a workflow_actions row for
     * delegate, escalate and return, and changed nothing.
     */
    public function actOnWorkflow(ActOnInstanceRequest $request, WorkflowInstance $instance)
    {
        $validated = $request->validated();
        $user = $request->user();

        if ($validated['action'] === 'cancel') {
            $this->engine->cancel($instance, $validated['comments'] ?? null, $user);

            return back()->with('success', 'Workflow cancelled.');
        }

        $task = $instance->openTasks()->get()->first(fn ($t) => $this->engine->canAct($t, $user));

        if ($task === null) {
            return back()->with('error', 'There is no open step here that you can act on.');
        }

        try {
            match ($validated['action']) {
                'delegate' => $this->engine->delegate(
                    $task,
                    User::query()->where('organization_id', $instance->organization_id)->findOrFail($validated['delegated_to'] ?? 0),
                    $validated['comments'] ?? null,
                    $user,
                ),
                'escalate' => $this->engine->escalate($task, $validated['comments'] ?? null, $user),
                'return' => $this->engine->returnForRework($task, $validated['to_node'] ?? null, $validated['comments'] ?? null, $user),
                'comment' => $this->comment($task, $validated['comments'] ?? null, $user),
                default => $this->engine->advance($task, $validated['action'], $validated, $user),
            };
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Workflow action recorded.');
    }

    public function showInstance(Request $request, WorkflowInstance $instance)
    {
        Gate::authorize('view', $instance);

        $instance->load(['definition', 'initiator', 'actions.actor', 'tasks.assignee', 'tasks.delegatedFrom']);

        $user = $request->user();
        $actionable = $instance->openTasks()->get()->filter(fn ($task) => $this->engine->canAct($task, $user));

        return Inertia::render('Workflows/ShowInstance', [
            'instance' => $this->presenter->instanceSummary($instance, $instance->entity()->withoutGlobalScopes()->first()),
            'steps' => $this->presenter->graphSteps($instance),
            'history' => $instance->actions->map(fn ($a) => $this->presenter->action($a))->values()->all(),
            'actionable' => $actionable->map(fn (WorkflowTask $t) => $t->node_name ?? $t->node_code)->values()->all(),
            'canAct' => $actionable->isNotEmpty() && Gate::allows('act', $instance),
            'canCancel' => $instance->isOpen() && Gate::allows('cancel', $instance),
            'urls' => ['act' => route('risk.workflows.act', $instance)],
        ]);
    }

    /**
     * A comment leaves the task where it is — the one action that is meant to
     * change nothing but the record.
     */
    private function comment(WorkflowTask $task, ?string $comments, User $user): void
    {
        WorkflowAction::create([
            'instance_id' => $task->instance_id,
            'task_id' => $task->id,
            'stage' => $task->instance?->current_stage,
            'stage_name' => $task->node_name,
            'node_code' => $task->node_code,
            'actor_id' => $user->id,
            'action' => 'comment',
            'comments' => $comments,
            'acted_at' => now(),
        ]);
    }
}

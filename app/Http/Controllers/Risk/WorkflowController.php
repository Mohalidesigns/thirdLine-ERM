<?php

namespace App\Http\Controllers\Risk;

use App\Enums\WorkflowNodeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\ActOnInstanceRequest;
use App\Http\Requests\Workflow\SaveWorkflowDesignRequest;
use App\Http\Requests\Workflow\StartWorkflowRequest;
use App\Http\Requests\Workflow\StoreWorkflowDefinitionRequest;
use App\Models\ObjectType;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Presenters\WorkflowPresenter;
use App\Services\Workflow\StartableSubjects;
use App\Services\Workflow\SubjectRegistry;
use App\Services\Workflow\WorkflowDashboardService;
use App\Services\Workflow\WorkflowDefinitionValidator;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowPublisher;
use App\Support\MorphTypes;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use RuntimeException;
use Spatie\Permission\Models\Role;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Workflow dashboard, definitions and instances (migration Phase 3.7; the
 * designer followed in Phase 6.5).
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
        Gate::authorize('create', WorkflowDefinition::class);

        $existing = $request->integer('definition')
            ? WorkflowDefinition::find($request->integer('definition'))
            : null;

        return Inertia::render('Workflows/Designer', $this->designerProps($existing));
    }

    public function editDefinition(WorkflowDefinition $definition)
    {
        Gate::authorize('update', $definition);

        // A published version is immutable; editing it opens a draft of the
        // next version so running instances keep the graph they started on.
        $draft = $definition->is_published
            ? $this->publisher->draftNewVersion($definition, auth()->user())
            : $definition;

        return Inertia::render('Workflows/Designer', $this->designerProps($draft));
    }

    /**
     * Save the whole design as a draft (migration Phase 6.5).
     *
     * VALIDATION IS ON PUBLISH, NOT HERE. A half-drawn process is a normal
     * thing to have saved; what must never happen is a half-drawn process
     * being the one new instances start on. So this accepts anything that
     * could become a workflow and `liveErrors` reports what still stands
     * between it and publishing, while publishDefinition() refuses.
     */
    public function updateDefinition(SaveWorkflowDesignRequest $request, ?WorkflowDefinition $definition = null)
    {
        $saved = $this->publisher->saveDraft($definition, $request->payload(), $request->user());

        return redirect()
            ->route('risk.workflows.edit-definition', $saved)
            ->with('success', "Draft saved as version {$saved->version}.");
    }

    /**
     * Everything the designer needs, for a new canvas or an existing draft.
     *
     * @return array<string, mixed>
     */
    private function designerProps(?WorkflowDefinition $definition): array
    {
        $graph = [
            'nodes' => array_values((array) data_get($definition?->definition, 'nodes', [])),
            'edges' => array_values((array) data_get($definition?->definition, 'edges', [])),
        ];

        return [
            'definition' => $definition === null ? null : array_merge($definition->only([
                'id', 'code', 'name', 'description', 'entity_type', 'object_type_id',
                'trigger', 'version', 'is_published',
            ]), [
                'graph' => $graph,
                'escalation_rules' => array_values((array) ($definition->escalation_rules ?? [])),
                'can_publish' => Gate::allows('publish', $definition),
            ]),
            // What "New workflow" starts from. A blank canvas is not a useful
            // starting point for a process: every approval begins somewhere and
            // ends two ways, so the designer opens with that skeleton drawn.
            'skeleton' => self::SKELETON,
            'options' => [
                'nodeTypes' => collect(WorkflowNodeType::cases())->map(fn (WorkflowNodeType $type) => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'waits_for_human' => $type->waitsForHuman(),
                ])->values(),
                'roles' => Role::query()->orderBy('name')->pluck('name')->values(),
                'users' => User::query()
                    ->where('organization_id', TenantContext::organizationId())
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->values(),
                'subjectTypes' => app(SubjectRegistry::class)->boundTypes(),
                'objectTypes' => ObjectType::query()->orderBy('name')->get(['id', 'name', 'code'])->values(),
                'triggers' => SaveWorkflowDesignRequest::TRIGGERS,
            ],
            // What stands between this draft and publishing, reported the way
            // publish will report it.
            'liveErrors' => app(WorkflowDefinitionValidator::class)->errors($graph),
        ];
    }

    /**
     * @var array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private const SKELETON = [
        'nodes' => [
            ['code' => 'start', 'type' => 'start', 'name' => 'Submitted', 'x' => 40, 'y' => 120],
            [
                'code' => 'review', 'type' => 'approval', 'name' => 'Review',
                'assignee_rule' => 'role', 'assignee_config' => ['roles' => []],
                'sla_hours' => 72, 'on_timeout' => 'escalate',
                'allow_delegate' => true, 'allow_return' => true,
                'x' => 280, 'y' => 120,
            ],
            ['code' => 'approved', 'type' => 'end', 'name' => 'Approved', 'outcome' => 'approved', 'x' => 540, 'y' => 60],
            ['code' => 'rejected', 'type' => 'end', 'name' => 'Rejected', 'outcome' => 'rejected', 'x' => 540, 'y' => 200],
        ],
        'edges' => [
            ['from' => 'start', 'to' => 'review'],
            ['from' => 'review', 'to' => 'rejected', 'when' => "outcome == 'reject'", 'label' => 'Rejected'],
            ['from' => 'review', 'to' => 'approved', 'label' => 'Approved'],
        ],
    ];

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

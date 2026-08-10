<?php

namespace App\Http\Controllers\Risk;

use App\Enums\WorkflowInstanceStatus;
use App\Enums\WorkflowTaskStatus;
use App\Http\Controllers\Controller;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Services\Workflow\SubjectRegistry;
use App\Services\Workflow\TaskQueryService;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowPublisher;
use App\Support\MorphTypes;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use RuntimeException;

class WorkflowController extends Controller
{
    public function __construct(
        private WorkflowEngine $engine,
        private WorkflowPublisher $publisher,
        private TaskQueryService $tasks,
        private SubjectRegistry $subjects,
    ) {}

    public function dashboard()
    {
        $orgId = TenantContext::organizationId();

        $openTasks = \App\Models\WorkflowTask::where('organization_id', $orgId)->open();

        return view('risk.workflows.dashboard', [
            'activeWorkflows' => WorkflowInstance::where('organization_id', $orgId)->open()->count(),
            'completedToday' => WorkflowInstance::where('organization_id', $orgId)
                ->where('status', WorkflowInstanceStatus::Completed->value)
                ->whereDate('completed_at', today())
                ->count(),
            // What was "how many instances sit on a stage whose approver_role
            // matches one of mine", computed by loading every open instance
            // into PHP. It is now an index lookup on workflow_tasks.
            'pendingMyAction' => $this->tasks->countsFor(auth()->user())['open'],
            'overdueTasks' => (clone $openTasks)->overdue()->count(),
            'escalatedTasks' => (clone $openTasks)->where('status', WorkflowTaskStatus::Escalated->value)->count(),
            'totalDefinitions' => WorkflowDefinition::where('organization_id', $orgId)->published()->count(),
            'recentInstances' => WorkflowInstance::where('organization_id', $orgId)
                ->with(['definition', 'initiator', 'tasks.assignee'])
                ->latest()
                ->take(15)
                ->get(),
            'workload' => $this->tasks->workloadFor($orgId),
        ]);
    }

    public function definitions()
    {
        $orgId = TenantContext::organizationId();

        $definitions = WorkflowDefinition::where('organization_id', $orgId)
            ->with('creator', 'publisher')
            ->orderBy('code')
            ->orderByDesc('version')
            ->paginate(25);

        return view('risk.workflows.definitions', [
            'definitions' => $definitions,
            'entityOptions' => $this->startableSubjects($orgId),
            'subjectTypes' => $this->subjects->boundTypes(),
        ]);
    }

    /**
     * The designer. The old create-definition form built a linear stage list;
     * the graph designer replaces it, and the route name is kept so existing
     * links do not break.
     */
    public function createDefinition(Request $request)
    {
        return view('risk.workflows.designer', [
            'definitionId' => $request->integer('definition') ?: null,
        ]);
    }

    public function editDefinition(WorkflowDefinition $definition)
    {
        abort_unless($definition->organization_id === TenantContext::organizationId(), 403);

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
    public function storeDefinition(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:80|regex:/^[a-z0-9_]+$/',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'entity_type' => 'required|string|max:50',
            'definition' => 'required|array',
            'definition.nodes' => 'required|array|min:1',
            'definition.edges' => 'nullable|array',
            'escalation_rules' => 'nullable|array',
        ]);

        $definition = $this->publisher->saveDraft(null, $validated, $request->user());

        return redirect()
            ->route('risk.workflows.edit-definition', $definition)
            ->with('success', 'Draft saved. Publish it when the process is complete.');
    }

    public function publishDefinition(WorkflowDefinition $definition)
    {
        abort_unless($definition->organization_id === TenantContext::organizationId(), 403);

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
        abort_unless($definition->organization_id === TenantContext::organizationId(), 403);

        $this->publisher->unpublish($definition);

        return back()->with('success', 'Unpublished. No new instances will start on this version.');
    }

    public function startWorkflow(Request $request)
    {
        $validated = $request->validate([
            'definition_id' => 'required|exists:workflow_definitions,id',
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer',
        ]);

        $definition = WorkflowDefinition::findOrFail($validated['definition_id']);

        abort_unless($definition->organization_id === TenantContext::organizationId(), 403);

        $entityType = MorphTypes::normalise($validated['entity_type']);

        abort_if($entityType === null, 422, "Unknown workflow entity type [{$validated['entity_type']}].");

        if ($entityType !== $definition->entity_type) {
            return back()->with('error', "That workflow runs over {$definition->entity_type} records, not {$entityType}.");
        }

        $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($entityType);
        $subject = $class::findOrFail($validated['entity_id']);

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
    public function actOnWorkflow(Request $request, WorkflowInstance $instance)
    {
        abort_unless($instance->organization_id === TenantContext::organizationId(), 403);

        $validated = $request->validate([
            'action' => 'required|in:approve,reject,delegate,escalate,comment,return,cancel',
            'comments' => 'nullable|string|max:3000',
            'delegated_to' => 'nullable|integer|exists:users,id',
            'to_node' => 'nullable|string|max:80',
        ]);

        $user = $request->user();

        if ($validated['action'] === 'cancel') {
            abort_unless($user->can('workflow.manage'), 403);
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
                    \App\Models\User::where('organization_id', $instance->organization_id)
                        ->findOrFail($validated['delegated_to'] ?? 0),
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

    public function showInstance(WorkflowInstance $instance)
    {
        abort_unless($instance->organization_id === TenantContext::organizationId(), 403);

        $instance->load(['definition', 'initiator', 'actions.actor', 'tasks.assignee', 'tasks.delegatedFrom']);

        return view('risk.workflows.show-instance', [
            'instance' => $instance,
            'graph' => $instance->definitionSnapshot(),
            'subject' => $instance->entity()->withoutGlobalScopes()->first(),
            'actionableTasks' => $instance->openTasks()->get()
                ->filter(fn ($task) => $this->engine->canAct($task, auth()->user())),
        ]);
    }

    /**
     * A comment leaves the task where it is — the one action that is meant to
     * change nothing but the record.
     */
    private function comment(\App\Models\WorkflowTask $task, ?string $comments, \App\Models\User $user): void
    {
        \App\Models\WorkflowAction::create([
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

    /**
     * Records a workflow can be started over, per bound subject type.
     *
     * @return array<string, array{label:string, items:\Illuminate\Support\Collection}>
     */
    private function startableSubjects(int $orgId): array
    {
        $options = [];

        $sources = [
            'issue' => [\App\Models\Issue::class, fn ($i) => ($i->issue_reference ?? "ISS-{$i->id}").' — '.($i->title ?? '')],
            'loss_event' => [\App\Models\LossEvent::class, fn ($e) => ($e->event_reference ?? "LE-{$e->id}").' — '.($e->title ?? '')],
            'risk_assessment' => [\App\Models\RiskAssessment::class, fn ($a) => 'ASS-'.str_pad((string) $a->id, 4, '0', STR_PAD_LEFT)],
            'treatment_plan' => [\App\Models\TreatmentPlan::class, fn ($t) => ($t->treatment_code ?? "TP-{$t->id}").' — '.($t->action_title ?? '')],
            'control_test' => [\App\Models\ControlTest::class, fn ($t) => ($t->test_code ?? "CT-{$t->id}")],
            'risk' => [\App\Models\Risk::class, fn ($r) => ($r->risk_code ?? "RSK-{$r->id}").' — '.($r->title ?? '')],
            'risk_appetite' => [\App\Models\RiskAppetite::class, fn ($a) => 'Appetite #'.$a->id.' — '.($a->appetite_level ?? '')],
            'icaap_assessment' => [\App\Models\IcaapAssessment::class, fn ($i) => 'ICAAP '.($i->period ?? '#'.$i->id)],
        ];

        foreach ($sources as $alias => [$class, $label]) {
            $options[$alias] = [
                'class' => $class,
                'items' => $class::where('organization_id', $orgId)
                    ->orderByDesc('id')
                    ->limit(100)
                    ->get()
                    ->map(fn ($model) => ['id' => $model->id, 'label' => trim($label($model))]),
            ];
        }

        return $options;
    }
}

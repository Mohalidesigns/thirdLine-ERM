<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowAction;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    public function dashboard()
    {
        $orgId = auth()->user()->organization_id;

        $activeWorkflows   = WorkflowInstance::whereHas('definition', fn($q) => $q->where('organization_id', $orgId))->where('status', 'active')->count();
        $completedToday    = WorkflowInstance::whereHas('definition', fn($q) => $q->where('organization_id', $orgId))->where('status', 'completed')->whereDate('completed_at', today())->count();
        // Active instances whose current stage approver role matches one of the user's roles.
        $userRoles = auth()->user()->getRoleNames()->all();
        $pendingMyAction = WorkflowInstance::whereHas('definition', fn($q) => $q->where('organization_id', $orgId))
            ->where('status', 'active')
            ->with('definition')
            ->get()
            ->filter(function ($instance) use ($userRoles) {
                $stage = $instance->definition->stages[$instance->current_stage] ?? null;
                $approverRole = $stage['approver_role'] ?? null;

                return $approverRole !== null && in_array($approverRole, $userRoles, true);
            })
            ->count();
        $totalDefinitions  = WorkflowDefinition::where('organization_id', $orgId)->where('is_active', true)->count();

        $recentInstances = WorkflowInstance::whereHas('definition', fn($q) => $q->where('organization_id', $orgId))
            ->with(['definition', 'initiator', 'actions'])
            ->latest()
            ->take(15)
            ->get();

        return view('risk.workflows.dashboard', compact(
            'activeWorkflows', 'completedToday', 'pendingMyAction', 'totalDefinitions', 'recentInstances'
        ));
    }

    public function definitions()
    {
        $orgId = auth()->user()->organization_id;
        $definitions = WorkflowDefinition::where('organization_id', $orgId)->with('creator')->latest()->paginate(20);

        // Selectable entities per definition entity_type, so instances can be started from this screen.
        $entityOptions = [
            'issue' => [
                'class' => \App\Models\Issue::class,
                'items' => \App\Models\Issue::where('organization_id', $orgId)->orderByDesc('id')->limit(100)->get()
                    ->map(fn ($i) => ['id' => $i->id, 'label' => trim(($i->issue_reference ?? "ISS-{$i->id}") . ' — ' . ($i->title ?? $i->issue_title ?? ''))]),
            ],
            'loss_event' => [
                'class' => \App\Models\LossEvent::class,
                'items' => \App\Models\LossEvent::where('organization_id', $orgId)->orderByDesc('id')->limit(100)->get()
                    ->map(fn ($e) => ['id' => $e->id, 'label' => trim(($e->event_reference ?? "LE-{$e->id}") . ' — ' . ($e->title ?? ''))]),
            ],
            'regulatory_circular' => [
                'class' => \App\Models\RegulatoryCircular::class,
                'items' => \App\Models\RegulatoryCircular::where('organization_id', $orgId)->orderByDesc('id')->limit(100)->get()
                    ->map(fn ($c) => ['id' => $c->id, 'label' => trim(($c->circular_ref ?? "CIR-{$c->id}") . ' — ' . ($c->title ?? ''))]),
            ],
            'risk_assessment' => [
                'class' => \App\Models\RiskAssessment::class,
                'items' => \App\Models\RiskAssessment::where('organization_id', $orgId)->with('risk')->orderByDesc('id')->limit(100)->get()
                    ->map(fn ($a) => ['id' => $a->id, 'label' => trim("ASMT-{$a->id} — " . ($a->risk->title ?? 'Risk') . ' (' . optional($a->assessment_date)->format('d M Y') . ')')]),
            ],
            'treatment_plan' => [
                'class' => \App\Models\TreatmentPlan::class,
                'items' => \App\Models\TreatmentPlan::where('organization_id', $orgId)->orderByDesc('id')->limit(100)->get()
                    ->map(fn ($t) => ['id' => $t->id, 'label' => trim("TP-{$t->id} — " . ($t->title ?? $t->treatment_title ?? ''))]),
            ],
        ];

        return view('risk.workflows.definitions', compact('definitions', 'entityOptions'));
    }

    public function createDefinition()
    {
        return view('risk.workflows.create-definition');
    }

    public function storeDefinition(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'entity_type' => 'required|string|max:50',
            'stages'      => 'required|array|min:1',
            'stages.*.name'        => 'required|string',
            'stages.*.approver_role' => 'required|string',
        ]);

        WorkflowDefinition::create([
            'organization_id' => auth()->user()->organization_id,
            'name'            => $request->name,
            'description'     => $request->description,
            'entity_type'     => $request->entity_type,
            'stages'          => $request->stages,
            'escalation_rules' => $request->escalation_rules,
            'created_by'      => auth()->id(),
        ]);

        return redirect()->route('risk.workflows.definitions')->with('success', 'Workflow definition created.');
    }

    public function startWorkflow(Request $request)
    {
        $request->validate([
            'definition_id' => 'required|exists:workflow_definitions,id',
            'entity_type'   => 'required|string',
            'entity_id'     => 'required|integer',
        ]);

        $instance = WorkflowInstance::create([
            'definition_id' => $request->definition_id,
            'entity_type'   => $request->entity_type,
            'entity_id'     => $request->entity_id,
            'current_stage'  => 0,
            'status'         => 'active',
            'started_at'     => now(),
            'initiated_by'   => auth()->id(),
        ]);

        return back()->with('success', 'Workflow started.');
    }

    public function actOnWorkflow(Request $request, WorkflowInstance $instance)
    {
        $request->validate([
            'action'   => 'required|in:approve,reject,delegate,escalate,comment,return',
            'comments' => 'nullable|string',
        ]);

        $definition = $instance->definition;
        $stages     = $definition->stages;

        WorkflowAction::create([
            'instance_id'  => $instance->id,
            'stage'        => $instance->current_stage,
            'stage_name'   => $stages[$instance->current_stage]['name'] ?? 'Stage ' . $instance->current_stage,
            'actor_id'     => auth()->id(),
            'action'       => $request->action,
            'comments'     => $request->comments,
            'delegated_to' => $request->delegated_to,
            'acted_at'     => now(),
        ]);

        if ($request->action === 'approve') {
            $nextStage = $instance->current_stage + 1;
            if ($nextStage >= count($stages)) {
                $instance->update(['status' => 'completed', 'completed_at' => now()]);
            } else {
                $instance->update(['current_stage' => $nextStage]);
            }
        } elseif ($request->action === 'reject') {
            $instance->update(['status' => 'rejected', 'completed_at' => now()]);
        }

        return back()->with('success', 'Workflow action recorded.');
    }

    public function showInstance(WorkflowInstance $instance)
    {
        $instance->load(['definition', 'initiator', 'actions.actor']);
        return view('risk.workflows.show-instance', compact('instance'));
    }
}

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
        $pendingMyAction   = WorkflowInstance::whereHas('definition', fn($q) => $q->where('organization_id', $orgId))
            ->where('status', 'active')
            ->whereHas('definition', function ($q) {
                // Simplified: in reality, check current stage approver
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
        return view('risk.workflows.definitions', compact('definitions'));
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

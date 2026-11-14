<?php

namespace App\Services\Sm;

use App\Models\Issue;
use App\Models\IssueRemediationAction;
use App\Models\Sm\Scenario;
use App\Models\Sm\ScenarioAction;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\ReferenceCodeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service managing scenario actions, gap plans, and enterprise Issue promotion (ADR 0027 Amendment 3 §A3.3-A3.4).
 */
class ScenarioActionService
{
    /**
     * Create a new scenario action.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ScenarioRefused
     */
    public function createAction(Scenario $scenario, array $data, User $actor): ScenarioAction
    {
        if ($scenario->isFrozen()) {
            throw new ScenarioRefused("Scenario {$scenario->reference} is {$scenario->status} and cannot be modified.");
        }

        return DB::transaction(function () use ($scenario, $data) {
            $status = $data['status'] ?? ScenarioAction::STATUS_NOT_STARTED;
            $completedAt = ($status === ScenarioAction::STATUS_COMPLETED) ? now() : null;

            $action = ScenarioAction::create([
                'organization_id' => $scenario->organization_id,
                'sm_scenario_id' => $scenario->id,
                'kind' => $data['kind'] ?? ScenarioAction::KIND_GAP_REMEDIATION,
                'time_horizon' => $data['time_horizon'] ?? null,
                'risk_area' => (string) ($data['risk_area'] ?? 'Operational Risk'),
                'current_position' => $data['current_position'] ?? null,
                'identified_gap' => $data['identified_gap'] ?? null,
                'action_plan' => (string) $data['action_plan'],
                'business_unit_id' => $data['business_unit_id'] ?? null,
                'department' => $data['department'] ?? null,
                'owner_id' => $data['owner_id'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'status' => $status,
                'completed_at' => $completedAt,
                'proof_of_closure' => $data['proof_of_closure'] ?? null,
                'sort_order' => (int) ($data['sort_order'] ?? ($scenario->actions()->max('sort_order') + 1)),
            ]);

            AuditTrailService::record(
                $scenario,
                'scenario_action_created',
                'action',
                null,
                Str::limit($action->action_plan, 60)
            );

            return $action;
        });
    }

    /**
     * Update an existing scenario action.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ScenarioRefused
     */
    public function updateAction(ScenarioAction $action, array $data, User $actor): ScenarioAction
    {
        $scenario = $action->scenario;
        if ($scenario->isFrozen()) {
            throw new ScenarioRefused("Scenario {$scenario->reference} is {$scenario->status} and cannot be modified.");
        }

        return DB::transaction(function () use ($action, $scenario, $data) {
            $oldStatus = $action->status;
            $newStatus = $data['status'] ?? $oldStatus;

            $completedAt = $action->completed_at;
            if ($newStatus === ScenarioAction::STATUS_COMPLETED && $oldStatus !== ScenarioAction::STATUS_COMPLETED) {
                $completedAt = now();
            } elseif ($newStatus !== ScenarioAction::STATUS_COMPLETED && $oldStatus === ScenarioAction::STATUS_COMPLETED) {
                $completedAt = null;
            }

            $action->fill([
                'kind' => $data['kind'] ?? $action->kind,
                'time_horizon' => array_key_exists('time_horizon', $data) ? $data['time_horizon'] : $action->time_horizon,
                'risk_area' => $data['risk_area'] ?? $action->risk_area,
                'current_position' => array_key_exists('current_position', $data) ? $data['current_position'] : $action->current_position,
                'identified_gap' => array_key_exists('identified_gap', $data) ? $data['identified_gap'] : $action->identified_gap,
                'action_plan' => $data['action_plan'] ?? $action->action_plan,
                'business_unit_id' => array_key_exists('business_unit_id', $data) ? $data['business_unit_id'] : $action->business_unit_id,
                'department' => array_key_exists('department', $data) ? $data['department'] : $action->department,
                'owner_id' => array_key_exists('owner_id', $data) ? $data['owner_id'] : $action->owner_id,
                'due_date' => array_key_exists('due_date', $data) ? $data['due_date'] : $action->due_date,
                'status' => $newStatus,
                'completed_at' => $completedAt,
                'proof_of_closure' => array_key_exists('proof_of_closure', $data) ? $data['proof_of_closure'] : $action->proof_of_closure,
                'sort_order' => (int) ($data['sort_order'] ?? $action->sort_order),
            ]);

            $action->save();

            AuditTrailService::record(
                $scenario,
                'scenario_action_updated',
                'action',
                $action->id,
                $action->status
            );

            return $action;
        });
    }

    /**
     * Delete an existing scenario action.
     *
     * @throws ScenarioRefused
     */
    public function deleteAction(ScenarioAction $action, User $actor): void
    {
        $scenario = $action->scenario;
        if ($scenario->isFrozen()) {
            throw new ScenarioRefused("Scenario {$scenario->reference} is {$scenario->status} and cannot be modified.");
        }

        if ($action->issue_id !== null) {
            throw new ScenarioRefused('Cannot delete an action that has been promoted to enterprise Issue tracking.');
        }

        DB::transaction(function () use ($action, $scenario) {
            $actionId = $action->id;
            $action->delete();

            AuditTrailService::record(
                $scenario,
                'scenario_action_deleted',
                'action',
                $actionId,
                null
            );
        });
    }

    /**
     * Promote a scenario action to an enterprise Issue (ADR 0027 Amendment 3 §A3.4).
     *
     * @throws ScenarioRefused
     */
    public function promoteToIssue(ScenarioAction $action, User $actor): Issue
    {
        if ($action->issue_id !== null) {
            throw new ScenarioRefused("This scenario action has already been promoted to enterprise Issue {$action->issue?->issue_reference}.");
        }

        $scenario = $action->scenario;

        return DB::transaction(function () use ($action, $scenario, $actor) {
            $reference = ReferenceCodeService::generate('issues', 'issue_reference', 'ISS', 4, (int) $action->organization_id);

            $title = Str::limit("{$action->risk_area}: {$action->action_plan}", 250);
            $description = "Originated from Scenario Analysis: {$scenario->reference} ({$scenario->title})\n"
                ."Risk Area: {$action->risk_area}\n"
                .($action->current_position ? "Current Position: {$action->current_position}\n" : '')
                .($action->identified_gap ? "Identified Gap: {$action->identified_gap}\n" : '')
                ."Action Plan: {$action->action_plan}";

            $issue = Issue::create([
                'organization_id' => $action->organization_id,
                'uuid' => (string) Str::uuid(),
                'issue_reference' => $reference,
                'title' => $title,
                'description' => $description,
                'issue_source' => 'SCENARIO_ANALYSIS',
                'issue_category' => 'OPERATIONAL_RESILIENCE',
                'priority' => 'HIGH',
                'issue_status' => 'OPEN',
                'business_unit_id' => $action->business_unit_id,
                'department' => $action->department,
                'responsible_owner_id' => $action->owner_id,
                'remediation_due_date' => $action->due_date,
                'action_plan' => $action->action_plan,
                'risk_register_id' => $scenario->risk_id,
                'created_by' => $actor->id,
            ]);

            IssueRemediationAction::create([
                'issue_id' => $issue->id,
                'action_number' => 1,
                'description' => $action->action_plan,
                'owner_id' => $action->owner_id,
                'department' => $action->department,
                'target_date' => $action->due_date ?: now()->addDays(30)->toDateString(),
                'status' => 'NOT_STARTED',
            ]);

            $action->issue_id = $issue->id;
            $action->save();

            AuditTrailService::record(
                $scenario,
                'scenario_action_promoted',
                'issue_id',
                null,
                $issue->id
            );

            return $issue;
        });
    }
}

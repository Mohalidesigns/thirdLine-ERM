<?php

use App\Enums\WorkflowTaskStatus;
use App\Models\ApprovalRequest;
use App\Models\WorkflowDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WP-06 TASK 4 — move the open maker-checker queue onto the engine.
 *
 * Every pending approval_requests row becomes a workflow_instance parked on the
 * first human node of the matching definition, plus the workflow_task that node
 * would have raised. The reviewer stored in payload.reviewer_id keeps the item;
 * where there was none it becomes an offer to the node's roles, which is what
 * the old queue effectively was ("anyone with the approve gate sees it").
 *
 * DECIDED ROWS ARE LEFT ALONE. approved and rejected rows are a historical
 * record, complete in itself, and rewriting settled compliance history into a
 * different table is not a migration — it is a fabrication. The approvals
 * history screen keeps reading them.
 *
 * ROWS WITH NO MATCHING DEFINITION are also left alone and counted in the log.
 * They stay visible and actionable in the old queue rather than vanishing into
 * a table with nothing to drive them.
 */
return new class extends Migration
{
    /** approval_requests.action => the definition code that replaces it. */
    private const ACTION_MAP = [
        'approve_risk_assessment' => 'risk_assessment_approval',
        'approve_treatment_plan' => 'treatment_plan_approval',
        'approve_control_test' => 'control_test_review',
        'approve_loss_event' => 'loss_event_approval',
        'rebaseline_threshold' => 'threshold_rebaselining_approval',
    ];

    public function up(): void
    {
        $migrated = 0;
        $skipped = [];

        $pending = ApprovalRequest::withoutGlobalScopes()
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();

        foreach ($pending as $approval) {
            $code = self::ACTION_MAP[$approval->action] ?? null;

            if ($code === null) {
                $skipped[] = ['id' => $approval->id, 'reason' => 'no definition maps to action '.$approval->action];

                continue;
            }

            $definition = WorkflowDefinition::withoutGlobalScopes()
                ->where('organization_id', $approval->organization_id)
                ->where('code', $code)
                ->where('is_published', true)
                ->orderByDesc('version')
                ->first();

            if ($definition === null) {
                $skipped[] = ['id' => $approval->id, 'reason' => 'organization has no published '.$code];

                continue;
            }

            $node = $this->firstHumanNode($definition);

            if ($node === null) {
                $skipped[] = ['id' => $approval->id, 'reason' => $code.' has no human step'];

                continue;
            }

            $alreadyRunning = DB::table('workflow_instances')
                ->where('entity_type', $approval->entity_type)
                ->where('entity_id', $approval->entity_id)
                ->whereIn('status', ['active', 'escalated'])
                ->exists();

            if ($alreadyRunning) {
                $skipped[] = ['id' => $approval->id, 'reason' => 'an instance is already open for this subject'];

                continue;
            }

            DB::transaction(function () use ($approval, $definition, $node, &$migrated) {
                $instanceId = DB::table('workflow_instances')->insertGetId([
                    'organization_id' => $approval->organization_id,
                    'definition_id' => $definition->id,
                    'definition_version' => $definition->version,
                    'entity_type' => $approval->entity_type,
                    'entity_id' => $approval->entity_id,
                    'current_stage' => 0,
                    'current_nodes' => json_encode([$node['code']]),
                    'context' => json_encode([
                        'subject' => [],
                        '_joins' => [],
                        'migrated_from_approval_request' => $approval->id,
                    ]),
                    'status' => 'active',
                    // The clock keeps running from when the item was actually
                    // raised. Restarting it at deploy time would reset every
                    // ageing report in the platform.
                    'started_at' => $approval->requested_at ?? $approval->created_at,
                    'initiated_by' => $approval->requested_by,
                    'created_at' => $approval->created_at,
                    'updated_at' => now(),
                ]);

                $reviewerId = data_get($approval->payload, 'reviewer_id');
                $roles = data_get($node, 'assignee_config.roles', data_get($node, 'assignee_config.fallback_roles', []));

                $due = isset($node['sla_hours']) && $node['sla_hours'] > 0
                    ? \Illuminate\Support\Carbon::parse($approval->requested_at ?? $approval->created_at)
                        ->addMinutes((int) round(((float) $node['sla_hours']) * 60))
                    : null;

                DB::table('workflow_tasks')->insert([
                    'organization_id' => $approval->organization_id,
                    'instance_id' => $instanceId,
                    'node_code' => $node['code'],
                    'node_name' => $node['name'] ?? $node['code'],
                    'node_type' => $node['type'] ?? 'approval',
                    'assignee_id' => $reviewerId,
                    'assignee_role' => $reviewerId === null ? ($roles[0] ?? null) : null,
                    'candidate_roles' => $reviewerId === null ? json_encode(array_values((array) $roles)) : json_encode([]),
                    'candidate_user_ids' => json_encode([]),
                    'status' => WorkflowTaskStatus::Pending->value,
                    'due_at' => $due,
                    'created_at' => $approval->requested_at ?? $approval->created_at,
                    'updated_at' => now(),
                ]);

                DB::table('workflow_actions')->insert([
                    'instance_id' => $instanceId,
                    'stage' => 0,
                    'node_code' => $node['code'],
                    'stage_name' => $node['name'] ?? $node['code'],
                    'actor_id' => null,
                    'action' => 'start',
                    'comments' => 'Migrated from approval request #'.$approval->id.' by WP-06.',
                    'acted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // The old row is closed as superseded, not deleted: it is the
                // evidence of where this item came from, and the history screen
                // reads it.
                DB::table('approval_requests')->where('id', $approval->id)->update([
                    'status' => 'superseded',
                    'comments' => trim((string) $approval->comments."\nMoved onto workflow instance #{$instanceId} by WP-06."),
                    'updated_at' => now(),
                ]);

                $migrated++;
            });
        }

        logger()->info('WP-06 migrated the open approval queue onto the workflow engine.', [
            'migrated' => $migrated,
            'left_in_place' => count($skipped),
            'reasons' => $skipped,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function firstHumanNode(WorkflowDefinition $definition): ?array
    {
        $graph = $definition->graph();
        $start = $graph->startNode();

        if ($start === null) {
            return null;
        }

        $queue = array_column($graph->edgesFrom($start['code']), 'to');
        $seen = [$start['code']];

        while ($queue !== []) {
            $code = array_shift($queue);

            if (in_array($code, $seen, true)) {
                continue;
            }

            $seen[] = $code;
            $node = $graph->node($code);

            if ($node !== null && in_array($node['type'] ?? '', ['approval', 'task'], true)) {
                return $node;
            }

            foreach ($graph->edgesFrom($code) as $edge) {
                $queue[] = $edge['to'];
            }
        }

        return null;
    }

    public function down(): void
    {
        // Reopen what was superseded and drop the instances created from it.
        $instances = DB::table('workflow_instances')
            ->whereNotNull('context')
            ->pluck('context', 'id')
            ->filter(fn ($context) => str_contains((string) $context, 'migrated_from_approval_request'));

        foreach ($instances as $instanceId => $context) {
            $approvalId = data_get(json_decode((string) $context, true), 'migrated_from_approval_request');

            if ($approvalId !== null) {
                DB::table('approval_requests')->where('id', $approvalId)->update([
                    'status' => 'pending',
                    'updated_at' => now(),
                ]);
            }

            DB::table('workflow_tasks')->where('instance_id', $instanceId)->delete();
            DB::table('workflow_actions')->where('instance_id', $instanceId)->delete();
            DB::table('workflow_instances')->where('id', $instanceId)->delete();
        }
    }
};

<?php

namespace App\Services\Workflow\Subjects;

use App\Models\LossEventApproval;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;

/**
 * Loss event approval — the multi-stage one.
 *
 * loss_event_approvals is the existing stage-by-stage decision history, and it
 * is referenced by the CBN reporting screens, so the engine keeps writing it:
 * one row per node decision, with the node code as the stage. That is the
 * "keep the per-module tables updated for one release" rule of TASK 4, and here
 * it is not merely backward compatibility — a regulator asking who signed off a
 * ₦2bn loss at each level expects to find it in the table the platform has
 * always kept it in.
 */
class LossEventBinding extends BaseSubjectBinding
{
    public function gate(): ?string
    {
        return 'approve-loss-event';
    }

    public function ownerId(Model $subject): ?int
    {
        return $subject->assigned_to_id ?? $subject->reported_by ?? $subject->created_by;
    }

    public function label(Model $subject): string
    {
        return trim(($subject->event_reference ?? 'LE-'.$subject->id).' — '.($subject->title ?? $subject->event_title ?? ''));
    }

    public function context(Model $subject): array
    {
        return [
            'id' => $subject->id,
            'event_reference' => $subject->event_reference,
            'gross_loss_kobo' => $subject->gross_loss_amount_kobo,
            // net_loss_amount_kobo is an accessor over the five-way recovery
            // decomposition (WP-01 crown jewel), not a column — read as an
            // attribute so it stays the one implementation of "net".
            'net_loss_kobo' => $subject->net_loss_amount_kobo,
            'basel_level_1' => $subject->basel_level_1,
            'is_regulatory_reportable' => (bool) ($subject->cbn_reportable ?? false),
            'date_of_loss' => optional($subject->date_of_loss)->toDateString(),
        ];
    }

    public function onStarted(Model $subject, ?WorkflowInstance $instance): void
    {
        $this->write($subject, ['current_status' => 'PENDING_APPROVAL']);
    }

    public function onApproved(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $comments): void
    {
        $this->recordStages($subject, $instance);

        $this->write($subject, [
            'current_status' => 'APPROVED',
            'approved_by' => $actor?->id,
            'approved_at' => now(),
        ]);
    }

    public function onRejected(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        $this->recordStages($subject, $instance);

        $this->write($subject, ['current_status' => 'UNDER_INVESTIGATION']);
    }

    public function onReturned(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        $this->write($subject, ['current_status' => 'UNDER_INVESTIGATION']);
    }

    public function onCancelled(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        if ($subject->current_status === 'PENDING_APPROVAL') {
            $this->write($subject, ['current_status' => 'UNDER_INVESTIGATION']);
        }
    }

    /**
     * Mirror each completed approval node into loss_event_approvals.
     *
     * Idempotent on (loss_event_id, stage): a re-run must not double the
     * history, and the engine can reach a terminal node more than once when an
     * instance is returned for rework and resubmitted.
     */
    private function recordStages(Model $subject, ?WorkflowInstance $instance): void
    {
        if ($instance === null || ! $instance->exists) {
            return;
        }

        $decided = $instance->tasks()
            ->whereNotNull('outcome')
            ->whereNotNull('completed_at')
            ->orderBy('completed_at')
            ->get();

        foreach ($decided as $task) {
            $exists = LossEventApproval::where('loss_event_id', $subject->id)
                ->where('stage', $task->node_code)
                ->where('actioned_at', $task->completed_at)
                ->exists();

            if ($exists) {
                continue;
            }

            LossEventApproval::create([
                'loss_event_id' => $subject->id,
                'stage' => $task->node_code,
                'action' => $task->outcome,
                'decision' => $task->outcome === 'approve' ? 'approved' : $task->outcome,
                'comments' => $task->comments,
                'actioned_by' => $task->completed_by,
                'actioned_at' => $task->completed_at,
                'days_in_stage' => $task->created_at && $task->completed_at
                    ? (int) $task->created_at->diffInDays($task->completed_at)
                    : null,
            ]);
        }
    }
}

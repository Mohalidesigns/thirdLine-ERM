<?php

namespace App\Services\Workflow\Subjects;

use App\Models\IssueProgressUpdate;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;

/**
 * Issue closure approval.
 *
 * The subject's state machine is issue_status, and the workflow governs exactly
 * one transition in it: PENDING_CLOSURE → CLOSED. Everything else an issue does
 * stays where it is.
 */
class IssueClosureBinding extends BaseSubjectBinding
{
    public function gate(): ?string
    {
        return null;
    }

    public function ownerId(Model $subject): ?int
    {
        return $subject->responsible_owner_id ?? $subject->issue_owner_id ?? $subject->created_by;
    }

    public function label(Model $subject): string
    {
        return trim(($subject->issue_reference ?? 'ISS-'.$subject->id).' — '.($subject->title ?? ''));
    }

    public function context(Model $subject): array
    {
        return [
            'id' => $subject->id,
            'issue_reference' => $subject->issue_reference,
            'priority' => $subject->priority,
            'issue_category' => $subject->issue_category,
            'regulatory_reportable' => (bool) $subject->regulatory_reportable,
            'cbn_reportable' => (bool) $subject->cbn_reportable,
            'potential_loss_kobo' => $subject->potential_loss_kobo,
        ];
    }

    public function onStarted(Model $subject, ?WorkflowInstance $instance): void
    {
        $this->write($subject, [
            'issue_status' => 'PENDING_CLOSURE',
            'closure_requested_at' => now(),
            'closure_requested_by' => auth()->id(),
        ]);
    }

    public function onApproved(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $comments): void
    {
        $this->write($subject, [
            'issue_status' => 'CLOSED',
            'actual_close_date' => now()->toDateString(),
            'closed_at' => now(),
            'closed_by' => $actor?->id,
        ]);

        $this->note($subject, 'Issue closure approved.'.($comments ? ' '.$comments : ''), $actor);
    }

    public function onRejected(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        $this->write($subject, [
            'issue_status' => 'IN_PROGRESS',
            'closure_rejection_reason' => $reason,
            'closure_rejected_at' => now(),
            'closure_rejected_by' => $actor?->id,
        ]);

        $this->note($subject, 'Closure rejected: '.($reason ?? 'no reason given.'), $actor);
    }

    public function onReturned(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        $this->onRejected($subject, $instance, $actor, $reason);
    }

    public function onCancelled(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        if ($subject->issue_status === 'PENDING_CLOSURE') {
            $this->write($subject, ['issue_status' => 'IN_PROGRESS']);
        }
    }

    private function note(Model $subject, string $content, ?User $actor): void
    {
        IssueProgressUpdate::create([
            'issue_id' => $subject->getKey(),
            'update_type' => 'milestone',
            'content' => $content,
            'created_by' => $actor?->id,
        ]);
    }
}

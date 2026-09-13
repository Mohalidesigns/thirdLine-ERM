<?php

namespace App\Services\Workflow\Subjects;

use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;

class ControlTestBinding extends BaseSubjectBinding
{
    public function gate(): ?string
    {
        return 'review-control-test';
    }

    public function ownerId(Model $subject): ?int
    {
        return $subject->tester_id ?? $subject->created_by;
    }

    public function delegateId(Model $subject): ?int
    {
        return $subject->reviewer_id;
    }

    public function nodeId(Model $subject): ?int
    {
        return $subject->node_id ?? $subject->control?->node_id;
    }

    public function label(Model $subject): string
    {
        return trim(($subject->test_code ?? 'CT-'.$subject->id).' — '.($subject->control?->control_title ?? $subject->control?->title ?? 'control test'));
    }

    public function context(Model $subject): array
    {
        return [
            'id' => $subject->id,
            'test_code' => $subject->test_code,
            'control_id' => $subject->control_id,
            'result' => $subject->result,
            'score' => $subject->score,
        ];
    }

    public function onStarted(Model $subject, ?WorkflowInstance $instance): void
    {
        $this->write($subject, ['status' => 'pending_review']);
    }

    public function onApproved(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $comments): void
    {
        $this->write($subject, [
            'status' => 'completed',
            'reviewer_notes' => $comments ?? $subject->reviewer_notes,
            'reviewed_at' => now(),
            'reviewer_id' => $actor?->id ?? $subject->reviewer_id,
        ]);

        // The control's rolling effectiveness statistics are derived from its
        // completed tests, so they move when a review closes — not when the
        // tester submits.
        $subject->control?->updateTestStats();
    }

    public function onRejected(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        $this->write($subject, [
            'status' => 'rejected',
            'reviewer_notes' => $reason ?? $subject->reviewer_notes,
            'reviewed_at' => now(),
            'reviewer_id' => $actor?->id ?? $subject->reviewer_id,
        ]);

        $subject->control?->updateTestStats();
    }

    public function onReturned(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        $this->write($subject, [
            'status' => 'in_progress',
            'reviewer_notes' => $reason,
        ]);
    }
}

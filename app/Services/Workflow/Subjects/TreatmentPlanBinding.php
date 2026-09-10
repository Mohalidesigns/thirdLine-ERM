<?php

namespace App\Services\Workflow\Subjects;

use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;

class TreatmentPlanBinding extends BaseSubjectBinding
{
    public function gate(): ?string
    {
        return 'approve-treatment-plan';
    }

    public function label(Model $subject): string
    {
        return trim(($subject->treatment_code ?? 'TP-'.$subject->id).' — '.($subject->action_title ?? ''));
    }

    /**
     * Canonical columns only (WP-01 TASK 1): action_title, strategy,
     * cost_estimate_ngn, target_date. The 200038 duplicates are not read here,
     * so a condition written against this context keeps working after the
     * follow-up release drops them.
     */
    public function context(Model $subject): array
    {
        return [
            'id' => $subject->id,
            'treatment_code' => $subject->treatment_code,
            'risk_id' => $subject->risk_id,
            'strategy' => $subject->strategy,
            'cost_estimate_ngn' => $subject->cost_estimate_ngn,
            'target_date' => optional($subject->target_date)->toDateString(),
        ];
    }

    public function onStarted(Model $subject, ?WorkflowInstance $instance): void
    {
        $this->write($subject, ['status' => 'pending_review']);
    }

    public function onApproved(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $comments): void
    {
        $this->write($subject, [
            'status' => 'approved',
            'approved_by' => $actor?->id,
            'approved_at' => now(),
        ]);
    }

    public function onRejected(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        $this->write($subject, [
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);
    }

    public function onReturned(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        $this->write($subject, [
            'status' => 'draft',
            'rejection_reason' => $reason,
        ]);
    }
}

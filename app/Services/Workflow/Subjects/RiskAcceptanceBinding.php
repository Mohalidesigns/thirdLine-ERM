<?php

namespace App\Services\Workflow\Subjects;

use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;

/**
 * Risk acceptance approval.
 *
 * Accepting a risk is the response that produces no control, no treatment and
 * no evidence — which is precisely why it is the one that needs a named
 * approver, a rationale and an expiry. Without an expiry an acceptance is
 * indistinguishable from an oversight.
 *
 * The expiry defaults to the organization's acceptance term (config
 * workflow.acceptance_months, 12 by default) unless the instance context names
 * one, so an approver can accept "until the year end" rather than forever.
 */
class RiskAcceptanceBinding extends BaseSubjectBinding
{
    public function ownerId(Model $subject): ?int
    {
        return $subject->risk_owner_id ?? $subject->created_by;
    }

    public function nodeId(Model $subject): ?int
    {
        return $subject->node_id;
    }

    public function label(Model $subject): string
    {
        return trim(($subject->risk_code ?? 'RSK-'.$subject->id).' — '.($subject->title ?? ''));
    }

    public function context(Model $subject): array
    {
        return [
            'id' => $subject->id,
            'risk_code' => $subject->risk_code,
            'inherent_score' => $subject->inherent_score,
            'residual_score' => $subject->residual_score,
            'residual_rating' => $subject->residual_rating,
            'risk_category_id' => $subject->risk_category_id,
            'treatment_strategy' => $subject->treatment_strategy,
        ];
    }

    public function onApproved(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $comments): void
    {
        $months = (int) ($instance?->contextValue('acceptance_months')
            ?? config('workflow.acceptance_months', 12));

        $this->write($subject, [
            'treatment_strategy' => 'accept',
            'accepted_by' => $actor?->id,
            'accepted_at' => now(),
            'acceptance_expires_at' => $instance?->contextValue('acceptance_expires_at')
                ?? now()->addMonths(max(1, $months))->toDateString(),
            'acceptance_rationale' => $instance?->contextValue('rationale') ?? $comments,
        ]);
    }

    public function onRejected(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        // A refused acceptance leaves the risk needing a real response. The
        // strategy is cleared rather than set to something the approver did
        // not choose.
        $this->write($subject, [
            'treatment_strategy' => null,
            'accepted_by' => null,
            'accepted_at' => null,
            'acceptance_expires_at' => null,
            'acceptance_rationale' => $reason,
        ]);
    }
}

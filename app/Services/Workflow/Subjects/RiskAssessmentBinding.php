<?php

namespace App\Services\Workflow\Subjects;

use App\Events\AssessmentApproved;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\RiskScoringService;
use Illuminate\Database\Eloquent\Model;

/**
 * Risk assessment approval.
 *
 * The domain work that used to sit in RiskAssessmentController::approve — push
 * the approved scores onto the parent risk and fire AssessmentApproved — moves
 * here rather than staying in the controller, because it must happen however
 * the decision was reached: from the review screen, from My Tasks, from an
 * auto-approve on SLA timeout, or from the API in WP-07.
 */
class RiskAssessmentBinding extends BaseSubjectBinding
{
    public function gate(): ?string
    {
        return 'approve-risk-assessment';
    }

    public function ownerId(Model $subject): ?int
    {
        return $subject->assessor_id ?? $subject->created_by;
    }

    public function delegateId(Model $subject): ?int
    {
        return $subject->reviewer_id;
    }

    public function nodeId(Model $subject): ?int
    {
        return $subject->node_id ?? $subject->risk?->node_id;
    }

    public function label(Model $subject): string
    {
        $reference = 'ASS-'.str_pad((string) $subject->id, 4, '0', STR_PAD_LEFT);

        return trim($reference.' — '.($subject->risk?->title ?? 'risk assessment'));
    }

    public function context(Model $subject): array
    {
        return [
            'id' => $subject->id,
            'risk_id' => $subject->risk_id,
            'risk_code' => $subject->risk?->risk_code,
            'overall_score' => $subject->overall_score,
            'overall_rating' => $subject->overall_rating,
            'residual_score' => $subject->residual_score,
            'assessment_date' => optional($subject->assessment_date)->toDateString(),
        ];
    }

    public function onStarted(Model $subject, ?WorkflowInstance $instance): void
    {
        $this->write($subject, ['status' => 'in_review']);
    }

    public function onApproved(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $comments): void
    {
        /** @var RiskAssessment $subject */
        $this->write($subject, [
            'status' => 'approved',
            'approved_by' => $actor?->id,
            'approved_date' => now()->toDateString(),
        ]);

        $risk = $subject->risk;

        if ($risk === null) {
            return;
        }

        // ONE MAPPING, NOT TWO. This method used to map the approved scores
        // onto the risk itself, and RiskScoringService::updateRiskFromAssessment
        // — reached moments later through AssessmentApproved and
        // App\Listeners\UpdateRiskFromAssessment — mapped them again, its own
        // way, over the top. The two disagreed: this one took `impact_score`
        // and copied the residual verbatim, that one re-aggregated the impact
        // DIMENSION columns (scoring a dimensionless assessment at zero) and
        // nulled any residual without a likelihood/impact pair. Whichever ran
        // last won.
        //
        // The service is now the single authority for these columns and this
        // call delegates to it, so the write inside the approval transaction
        // and the write from the listener are the same write. The listener's
        // pass finds nothing dirty and issues no second UPDATE. The call is
        // kept here rather than left entirely to the listener so that the risk
        // row is already correct when the transaction commits, whatever
        // happens to the event later.
        app(RiskScoringService::class)->updateRiskFromAssessment($risk, $subject);

        // WP-04 listens for this to period-stamp the approved scores into
        // measure_values. Firing it here rather than in the controller is what
        // makes an SLA auto-approval produce the same measure history a manual
        // one does.
        AssessmentApproved::dispatch($subject, $risk);
    }

    public function onRejected(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        $this->write($subject, [
            'status' => 'rejected',
            'review_comments' => $reason,
            'reviewer_id' => $subject->reviewer_id ?: $actor?->id,
            'review_date' => now()->toDateString(),
        ]);
    }

    public function onReturned(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        $this->write($subject, [
            'status' => 'draft',
            'review_comments' => $reason,
        ]);
    }

    public function onCancelled(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        if ($subject->status === 'in_review') {
            $this->write($subject, ['status' => 'draft']);
        }
    }
}

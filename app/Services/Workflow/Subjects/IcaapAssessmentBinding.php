<?php

namespace App\Services\Workflow\Subjects;

use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;

/**
 * ICAAP sign-off.
 *
 * The one process on the platform whose final approver is the board rather
 * than a role in the risk function: CBN expects the Internal Capital Adequacy
 * Assessment Process document to carry a board approval date, and
 * icaap_assessments.approved_by_board / board_approval_date are the columns the
 * CBN submission reads. A multi-node definition (prepare → risk review →
 * board) writes them only at the end, which is the honest reading of a board
 * approval.
 */
class IcaapAssessmentBinding extends BaseSubjectBinding
{
    public function ownerId(Model $subject): ?int
    {
        return $subject->prepared_by;
    }

    public function delegateId(Model $subject): ?int
    {
        return $subject->reviewed_by;
    }

    public function label(Model $subject): string
    {
        return 'ICAAP '.($subject->period ?? '#'.$subject->id);
    }

    public function context(Model $subject): array
    {
        return [
            'id' => $subject->id,
            'period' => $subject->period,
            'car_actual' => $subject->car_actual,
            'cbn_minimum_car' => $subject->cbn_minimum_car,
            'total_qualifying_capital_kobo' => $subject->total_qualifying_capital_kobo,
            'total_rwa_kobo' => $subject->total_rwa_kobo,
            // A CAR below the CBN minimum is what a definition routes on: it
            // is the difference between a routine sign-off and one that needs
            // a capital plan attached.
            'below_minimum' => $subject->car_actual !== null
                && $subject->cbn_minimum_car !== null
                && (float) $subject->car_actual < (float) $subject->cbn_minimum_car,
        ];
    }

    public function onStarted(Model $subject, ?WorkflowInstance $instance): void
    {
        $this->write($subject, ['status' => 'UNDER_REVIEW']);
    }

    public function onApproved(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $comments): void
    {
        $this->write($subject, [
            'status' => 'APPROVED',
            'approved_by_board' => $actor?->id,
            'board_approval_date' => now()->toDateString(),
        ]);
    }

    public function onRejected(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        $this->write($subject, [
            'status' => 'DRAFT',
            'approved_by_board' => null,
            'board_approval_date' => null,
        ]);
    }

    public function onReturned(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        $this->write($subject, ['status' => 'DRAFT']);
    }
}

<?php

namespace App\Services\Workflow\Subjects;

use App\Models\MeasureThreshold;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\ThresholdRebaselineService;
use Illuminate\Database\Eloquent\Model;

/**
 * Threshold re-baselining approval (WP-04 TASK 5, routed through WP-06).
 *
 * The decision is not "set a flag" — approving means closing the band in force
 * and writing an effective-dated replacement, so last quarter's breaches keep
 * reading against last quarter's limit. That logic already exists and is
 * tested; this binding calls it rather than reimplementing it.
 *
 * The proposed bands travel in the instance context, put there by the caller
 * that raised the workflow. They are NOT written to the threshold row at any
 * point before approval, for the same reason they were never fillable: a
 * proposal sitting in the bands column would be a live limit nobody approved.
 */
class MeasureThresholdBinding extends BaseSubjectBinding
{
    public function __construct(private ThresholdRebaselineService $rebaseline) {}

    public function ownerId(Model $subject): ?int
    {
        return $subject->measure?->owner_id;
    }

    public function label(Model $subject): string
    {
        return 'Threshold re-baselining — '.($subject->measure?->code ?? 'measure #'.$subject->measure_id);
    }

    public function context(Model $subject): array
    {
        return [
            'id' => $subject->id,
            'measure_id' => $subject->measure_id,
            'measure_code' => $subject->measure?->code,
            'object_id' => $subject->object_id,
            'bands_in_force' => $subject->bands,
            'effective_from' => optional($subject->effective_from)->toDateString(),
        ];
    }

    public function onApproved(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $comments): void
    {
        /** @var MeasureThreshold $subject */
        $bands = $instance?->contextValue('proposed_bands');

        if (! is_array($bands) || $bands === []) {
            // Not a defensive check that can be relaxed: without proposed bands
            // there is nothing to put in force, and swallowing this would leave
            // the approval recorded and the limit unchanged — the one outcome
            // an effective-dated threshold must never produce.
            throw new \RuntimeException(
                'The re-baselining workflow carries no proposed bands; there is nothing to put in force.'
            );
        }

        $this->rebaseline->applyBands(
            $subject,
            $bands,
            $actor,
            $instance?->contextValue('computed_for_period_end'),
            $comments,
        );
    }
}

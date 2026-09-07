<?php

namespace App\Listeners\Tprm;

use App\Events\Tprm\EngagementScoreInvalidated;
use App\Services\Tprm\Scoring\ResidualScoringService;
use Illuminate\Support\Facades\Log;

/**
 * Recompute a residual score when one of its inputs changes.
 *
 * SYNCHRONOUS, not queued, and the phase acceptance is why: "the residual
 * score updates within the same request cycle for a single engagement". A
 * queued recomputation means a user closes a finding, watches the score not
 * move, and refreshes — and the second thing they learn about the product is
 * that its numbers lag.
 *
 * A FAILED RECOMPUTATION MUST NOT FAIL THE ACTION THAT TRIGGERED IT. Closing a
 * finding is the user's work; recomputing a score is the module's bookkeeping.
 * If the scoring throws — a missing tier policy, a malformed ruleset — the
 * close still stands and the failure is logged loudly. The alternative is a
 * user unable to close a finding because of an unrelated defect in the scoring
 * engine, which is the worse outage by a distance.
 */
class RecomputeResidualScore
{
    public function __construct(private readonly ResidualScoringService $scoring) {}

    public function handle(EngagementScoreInvalidated $event): void
    {
        try {
            $this->scoring->score(
                $event->engagement,
                trigger: $event->reason,
                runType: $event->reason === EngagementScoreInvalidated::SCHEDULED ? 'scheduled' : 'triggered',
            );
        } catch (\Throwable $exception) {
            Log::error('TPRM residual recomputation failed', [
                'engagement_id' => $event->engagement->getKey(),
                'reason' => $event->reason,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

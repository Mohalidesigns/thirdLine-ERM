<?php

namespace App\Listeners;

use App\Events\AssessmentApproved;
use App\Services\RiskMeasureRecorder;

/**
 * Period-stamps the approved scores into the measure engine.
 *
 * Deliberately NOT queued. The values have to be in place by the time the
 * approval redirect renders, or the user sees a register whose "as at this
 * period" view is missing the assessment they just approved.
 */
class RecordAssessmentMeasures
{
    public function __construct(private RiskMeasureRecorder $recorder) {}

    public function handle(AssessmentApproved $event): void
    {
        $this->recorder->recordAssessment($event->assessment, $event->risk);
    }
}

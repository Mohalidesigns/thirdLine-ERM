<?php

namespace App\Listeners;

use App\Events\AssessmentApproved;
use App\Services\RiskScoringService;

class UpdateRiskFromAssessment
{
    public function __construct(
        private RiskScoringService $riskScoringService
    ) {}

    public function handle(AssessmentApproved $event): void
    {
        $this->riskScoringService->updateRiskFromAssessment(
            $event->assessment,
            $event->risk
        );
    }
}

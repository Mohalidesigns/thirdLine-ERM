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
        // Argument order matters: the service signature is (Risk, RiskAssessment)
        // and this listener passed them the other way round, so approving an
        // assessment raised a TypeError inside the approval transaction and
        // rolled the whole approval back.
        $this->riskScoringService->updateRiskFromAssessment(
            $event->risk,
            $event->assessment
        );
    }
}

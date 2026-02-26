<?php

namespace App\Listeners;

use App\Events\TreatmentCompleted;
use App\Services\RiskScoringService;

class TriggerRiskReassessment
{
    public function __construct(
        private RiskScoringService $riskScoringService
    ) {}

    public function handle(TreatmentCompleted $event): void
    {
        $treatment = $event->treatment;
        $risk = $event->risk;

        // Update risk's residual scores based on treatment's expected risk reduction
        if ($treatment->expected_risk_reduction_percentage) {
            $residualScore = $risk->residual_score * (1 - ($treatment->expected_risk_reduction_percentage / 100));

            $risk->update([
                'residual_score' => max(1, $residualScore),
                'residual_likelihood_level' => $this->riskScoringService->determineLevel($residualScore),
                'last_updated_at' => now(),
            ]);
        }
    }
}

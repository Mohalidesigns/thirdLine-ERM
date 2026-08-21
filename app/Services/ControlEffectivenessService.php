<?php

namespace App\Services;

use App\Models\Risk;
use App\Support\RiskCalculationSettings;

class ControlEffectivenessService
{
    /**
     * Calculate aggregate control effectiveness for a risk
     * Uses weighted average: SUM(weight * effectiveness) / SUM(weight)
     */
    public function calculateForRisk(Risk $risk): float
    {
        $controls = $risk->controls()->withPivot(['control_weight', 'is_key_control'])->get();

        if ($controls->isEmpty()) {
            return 0.0;
        }

        // The bands are per organization (config/risk.php, overridable via the
        // organization's settings). Resolved once for the whole risk rather
        // than per control: a bulk recalculation walks thousands of controls.
        $effectivenessMap = RiskCalculationSettings::effectivenessMap($risk->organization_id);

        $totalWeightedEffectiveness = 0;
        $totalWeight = 0;

        foreach ($controls as $control) {
            $weight = $control->pivot->control_weight ?? 1.0;
            $effectiveness = $control->effectiveness_pct ?? ($effectivenessMap[$control->effectiveness_rating] ?? 0);

            $totalWeightedEffectiveness += $weight * $effectiveness;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? round($totalWeightedEffectiveness / $totalWeight, 2) : 0.0;
    }

    /**
     * Recalculate and update a risk's control effectiveness and residual scores.
     *
     * THE THIRD WRITER of risks.residual_*, and the odd one out. The other two
     * are RiskScoringService::updateRiskFromAssessment() — the authority on the
     * approval path — and RiskAssessmentBinding::onApproved(), which delegates
     * to it. Those two write the axis-split pair
     * (residual_likelihood × residual_impact = residual_score) that
     * AssessmentChainService::deriveResidual() produces. This one is on a
     * different trigger entirely: App\Listeners\RecalculateResidualRisk, from
     * the ControlUpdated event.
     *
     * KNOWN GAP, DELIBERATELY LEFT: this method writes residual_score and
     * residual_rating without touching residual_likelihood or residual_impact,
     * so a control update on a risk whose residual came from an approved
     * assessment leaves the risk row internally inconsistent — a residual_score
     * that is no longer the product of the residual pair beside it. Fixing it
     * means giving this method the same preventive/detective axis split
     * AssessmentChainService already computes from control_type, which is a
     * larger change than the approval-path repair it was found during. Pinned
     * as current behaviour by
     * tests/Feature/Assessments/AssessmentApprovalIntegrityTest.php.
     */
    public function recalculateForRisk(Risk $risk): Risk
    {
        $effectiveness = $this->calculateForRisk($risk);

        $scoringService = new RiskScoringService;

        $residualScore = null;
        $residualRating = null;

        if ($risk->inherent_score) {
            $residualScore = $scoringService->calculateResidualScore($risk->inherent_score, $effectiveness);
            $residualRating = $scoringService->calculateRating($residualScore);
        }

        $risk->update([
            'control_effectiveness_pct' => $effectiveness,
            'residual_score' => $residualScore,
            'residual_rating' => $residualRating,
        ]);

        return $risk->fresh();
    }
}

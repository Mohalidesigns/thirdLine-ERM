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
     * Recalculate and update a risk's control effectiveness and residual scores
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

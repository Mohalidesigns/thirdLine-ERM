<?php
namespace App\Services;

use App\Models\Risk;
use App\Models\Control;

class ControlEffectivenessService
{
    private array $effectivenessMap = [
        'effective' => 95,
        'mostly_effective' => 80,
        'partially_effective' => 60,
        'ineffective' => 37,
        'not_operating' => 12,
    ];
    
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
        
        $totalWeightedEffectiveness = 0;
        $totalWeight = 0;
        
        foreach ($controls as $control) {
            $weight = $control->pivot->control_weight ?? 1.0;
            $effectiveness = $control->effectiveness_pct ?? ($this->effectivenessMap[$control->effectiveness_rating] ?? 0);
            
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
        
        $scoringService = new RiskScoringService();
        
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

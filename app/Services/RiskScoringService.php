<?php
namespace App\Services;

use App\Models\Risk;
use App\Models\RiskAssessment;

class RiskScoringService
{
    /**
     * Calculate the inherent risk score from likelihood and impact
     */
    public function calculateScore(int $likelihood, int $impact): int
    {
        return $likelihood * $impact;
    }
    
    /**
     * Determine the risk rating from the score
     * Critical: 20-25, High: 12-19, Medium: 5-11, Low: 1-4
     */
    public function calculateRating(int $score): string
    {
        if ($score >= 20) return 'Critical';
        if ($score >= 12) return 'High';
        if ($score >= 5) return 'Medium';
        return 'Low';
    }
    
    /**
     * Calculate the maximum impact across all dimensions
     */
    public function calculateMaxImpact(?int $financial, ?int $operational, ?int $reputational, ?int $regulatory): int
    {
        return max(
            $financial ?? 0,
            $operational ?? 0,
            $reputational ?? 0,
            $regulatory ?? 0
        );
    }
    
    /**
     * Calculate residual risk based on control effectiveness
     */
    public function calculateResidualScore(int $inherentScore, float $controlEffectivenessPct): int
    {
        $residual = $inherentScore * (1 - ($controlEffectivenessPct / 100));
        return max(1, (int) round($residual));
    }
    
    /**
     * Update a risk record's scores from an assessment
     */
    public function updateRiskFromAssessment(Risk $risk, RiskAssessment $assessment): Risk
    {
        $impactScore = $this->calculateMaxImpact(
            $assessment->impact_financial,
            $assessment->impact_operational,
            $assessment->impact_reputational,
            $assessment->impact_regulatory
        );
        
        $inherentScore = $this->calculateScore($assessment->likelihood_score, $impactScore);
        $inherentRating = $this->calculateRating($inherentScore);
        
        $risk->update([
            'inherent_likelihood' => $assessment->likelihood_score,
            'inherent_impact' => $impactScore,
            'inherent_impact_financial' => $assessment->impact_financial,
            'inherent_impact_operational' => $assessment->impact_operational,
            'inherent_impact_reputational' => $assessment->impact_reputational,
            'inherent_impact_regulatory' => $assessment->impact_regulatory,
            'inherent_score' => $inherentScore,
            'inherent_rating' => $inherentRating,
            'residual_likelihood' => $assessment->residual_likelihood,
            'residual_impact' => $assessment->residual_impact,
            'residual_score' => $assessment->residual_likelihood && $assessment->residual_impact
                ? $this->calculateScore($assessment->residual_likelihood, $assessment->residual_impact) : null,
            'residual_rating' => $assessment->residual_likelihood && $assessment->residual_impact
                ? $this->calculateRating($this->calculateScore($assessment->residual_likelihood, $assessment->residual_impact)) : null,
            'risk_velocity' => $assessment->risk_velocity,
            'last_assessment_date' => $assessment->assessment_date,
        ]);
        
        return $risk->fresh();
    }
    
    /**
     * Get the risk matrix data for the heatmap
     * Returns a 5x5 grid with risk counts
     */
    public function getRiskMatrix(int $organizationId, string $type = 'inherent'): array
    {
        $prefix = $type === 'residual' ? 'residual' : 'inherent';
        
        $risks = Risk::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNotNull("{$prefix}_likelihood")
            ->whereNotNull("{$prefix}_impact")
            ->get();
        
        $matrix = [];
        for ($l = 1; $l <= 5; $l++) {
            for ($i = 1; $i <= 5; $i++) {
                $matrix[$l][$i] = [
                    'count' => 0,
                    'risks' => [],
                    'score' => $l * $i,
                    'rating' => $this->calculateRating($l * $i),
                ];
            }
        }
        
        foreach ($risks as $risk) {
            $l = $risk->{"{$prefix}_likelihood"};
            $i = $risk->{"{$prefix}_impact"};
            if ($l && $i && $l >= 1 && $l <= 5 && $i >= 1 && $i <= 5) {
                $matrix[$l][$i]['count']++;
                $matrix[$l][$i]['risks'][] = [
                    'id' => $risk->id,
                    'title' => $risk->title,
                    'risk_code' => $risk->risk_code,
                ];
            }
        }
        
        return $matrix;
    }
    
    /**
     * Get risk distribution by rating
     */
    public function getRiskDistribution(int $organizationId): array
    {
        return [
            'Critical' => Risk::where('organization_id', $organizationId)->where('status', 'active')->where('inherent_rating', 'Critical')->count(),
            'High' => Risk::where('organization_id', $organizationId)->where('status', 'active')->where('inherent_rating', 'High')->count(),
            'Medium' => Risk::where('organization_id', $organizationId)->where('status', 'active')->where('inherent_rating', 'Medium')->count(),
            'Low' => Risk::where('organization_id', $organizationId)->where('status', 'active')->where('inherent_rating', 'Low')->count(),
        ];
    }
}

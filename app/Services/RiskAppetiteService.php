<?php

namespace App\Services;

use App\Models\Risk;
use App\Models\RiskAppetite;

class RiskAppetiteService
{
    /**
     * Compare actual risk metrics against appetite statements for an organization
     */
    public function compareAgainstActual(int $organizationId): array
    {
        $appetites = RiskAppetite::where('organization_id', $organizationId)
            ->with('riskCategory')
            ->get();

        $results = [];

        foreach ($appetites as $appetite) {
            $categoryRisks = Risk::where('organization_id', $organizationId)
                ->where('category_id', $appetite->risk_category_id)
                ->where('status', 'active')
                ->get();

            $avgInherentScore = $categoryRisks->avg('inherent_score') ?? 0;
            $avgResidualScore = $categoryRisks->avg('residual_score') ?? 0;
            $maxScore = $categoryRisks->max('inherent_score') ?? 0;
            $riskCount = $categoryRisks->count();
            $criticalCount = $categoryRisks->where('inherent_rating', 'Critical')->count();
            $highCount = $categoryRisks->where('inherent_rating', 'High')->count();

            // Every column read below exists on risk_appetite. This method used
            // to read tolerance_upper, tolerance_lower, capacity,
            // appetite_type and notes; the first three resolved by accident
            // through their ?? fallbacks, but `capacity` was always the literal
            // 25 and `appetite_type` always 'Not Specified' — even though
            // appetite_level and appetite_statement held the real answers.
            $maxTolerance = (float) ($appetite->max_tolerance ?? 0);
            $capacity = $appetite->capacity === null ? null : (float) $appetite->capacity;
            $targetMax = $appetite->target_max === null ? null : (float) $appetite->target_max;

            $utilizationPct = $maxTolerance > 0
                ? round(($avgInherentScore / $maxTolerance) * 100, 1)
                : 0;

            // Boundaries that were never recorded are skipped rather than
            // defaulted. A fabricated capacity of 25 reported breaches that
            // nobody had defined, and hid the fact that the figure was missing.
            $status = 'within_appetite';
            if ($capacity !== null && $avgInherentScore > $capacity) {
                $status = 'exceeds_capacity';
            } elseif ($maxTolerance > 0 && $avgInherentScore > $maxTolerance) {
                $status = 'exceeds_tolerance';
            } elseif ($targetMax !== null && $avgInherentScore > $targetMax) {
                $status = 'approaching_tolerance';
            }

            $results[] = [
                'category_id' => $appetite->risk_category_id,
                'category_name' => $appetite->riskCategory->name ?? 'Unknown',
                'appetite_level' => $appetite->appetite_level ?? 'Not Specified',
                'appetite_type' => $appetite->appetite_type ?? 'Not Specified',
                'appetite_statement' => $appetite->appetite_statement ?? '',
                'tolerance_lower' => $appetite->target_min ?? 0,
                'tolerance_upper' => $maxTolerance,
                'capacity' => $capacity,
                'current_position' => round($avgInherentScore, 1),
                'avg_residual_score' => round($avgResidualScore, 1),
                'max_score' => $maxScore,
                'risk_count' => $riskCount,
                'critical_count' => $criticalCount,
                'high_count' => $highCount,
                'utilization_pct' => min($utilizationPct, 150),
                'status' => $status,
            ];
        }

        return $results;
    }

    /**
     * Get summary of appetite breaches
     */
    public function getBreaches(int $organizationId): array
    {
        $all = $this->compareAgainstActual($organizationId);

        return array_filter($all, fn ($item) => in_array($item['status'], ['exceeds_tolerance', 'exceeds_capacity']));
    }

    /**
     * Get appetite dashboard data
     */
    public function getDashboardData(int $organizationId): array
    {
        $results = $this->compareAgainstActual($organizationId);

        return [
            'total_categories' => count($results),
            'within_appetite' => count(array_filter($results, fn ($r) => $r['status'] === 'within_appetite')),
            'approaching' => count(array_filter($results, fn ($r) => $r['status'] === 'approaching_tolerance')),
            'exceeds_tolerance' => count(array_filter($results, fn ($r) => $r['status'] === 'exceeds_tolerance')),
            'exceeds_capacity' => count(array_filter($results, fn ($r) => $r['status'] === 'exceeds_capacity')),
            'categories' => $results,
        ];
    }
}

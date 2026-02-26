<?php

namespace App\Services;

use App\Models\RiskAppetite;
use App\Models\Risk;
use App\Models\RiskCategory;
use Illuminate\Support\Facades\DB;

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

            // Calculate utilization percentage
            $maxTolerance = $appetite->max_tolerance ?? $appetite->tolerance_upper ?? 25;
            $utilizationPct = $maxTolerance > 0 ? round(($avgInherentScore / $maxTolerance) * 100, 1) : 0;

            // Determine breach status
            $status = 'within_appetite';
            if ($avgInherentScore > ($appetite->capacity ?? 25)) {
                $status = 'exceeds_capacity';
            } elseif ($avgInherentScore > $maxTolerance) {
                $status = 'exceeds_tolerance';
            } elseif ($avgInherentScore > ($appetite->target_max ?? $appetite->tolerance_lower ?? 10)) {
                $status = 'approaching_tolerance';
            }

            $results[] = [
                'category_id' => $appetite->risk_category_id,
                'category_name' => $appetite->riskCategory->name ?? 'Unknown',
                'appetite_level' => $appetite->appetite_type ?? 'Not Specified',
                'appetite_statement' => $appetite->notes ?? '',
                'tolerance_lower' => $appetite->tolerance_lower ?? $appetite->target_min ?? 0,
                'tolerance_upper' => $maxTolerance,
                'capacity' => $appetite->capacity ?? 25,
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
        return array_filter($all, fn($item) => in_array($item['status'], ['exceeds_tolerance', 'exceeds_capacity']));
    }

    /**
     * Get appetite dashboard data
     */
    public function getDashboardData(int $organizationId): array
    {
        $results = $this->compareAgainstActual($organizationId);

        return [
            'total_categories' => count($results),
            'within_appetite' => count(array_filter($results, fn($r) => $r['status'] === 'within_appetite')),
            'approaching' => count(array_filter($results, fn($r) => $r['status'] === 'approaching_tolerance')),
            'exceeds_tolerance' => count(array_filter($results, fn($r) => $r['status'] === 'exceeds_tolerance')),
            'exceeds_capacity' => count(array_filter($results, fn($r) => $r['status'] === 'exceeds_capacity')),
            'categories' => $results,
        ];
    }
}

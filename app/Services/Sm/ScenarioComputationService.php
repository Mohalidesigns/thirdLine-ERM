<?php

namespace App\Services\Sm;

use App\Models\Sm\InsuranceCoverage;
use App\Models\Sm\Scenario;
use App\Models\Sm\Scorecard;
use App\Models\Sm\ScorecardComponent;
use Illuminate\Support\Facades\DB;

/**
 * Scenario Modelling & Analysis Phase 2 — Quantitative Computation Engine
 * (ADR 0027 D1, D2, Amendment 2 §A2.6).
 *
 * PURE DOMAIN SERVICE, NO HTTP.
 * Decoded from the BIFF8 stream of `docs/ScenarioModelling/Poor succesion planning.xls`
 * (PHASE0-SPIKE.md). Every formula reproduces the workbook math bit-for-bit.
 */
class ScenarioComputationService
{
    /**
     * Compute COSO Control Scorecard efficiency scores (Spike §1).
     *
     * @param  string  $controlNature  'mainly_manual' | 'mixed' | 'mainly_automated'
     * @param  array<int, array{component_code: string, efficiency_level: int, weighting_factor: int, notes?: string|null}>  $componentsData
     * @return array{
     *     control_nature: string,
     *     components: array<int, array{
     *         component_code: string,
     *         efficiency_level: int,
     *         weighting_factor: int,
     *         score: int,
     *         level_score: float,
     *         adjusted_score: float,
     *         weighting_score: int,
     *         weighted_score: float,
     *         notes: string|null,
     *         sort_order: int
     *     }>,
     *     total_weight: int,
     *     total_weighted_score: float,
     *     global_score: float
     * }
     */
    public function computeScorecard(string $controlNature, array $componentsData): array
    {
        $deduction = Scorecard::CONTROL_NATURES[$controlNature]['deduction'] ?? 0.05;

        $processedComponents = [];
        $totalWeight = 0;
        $totalWeightedScore = 0.0;
        $sortOrder = 1;

        foreach ($componentsData as $item) {
            $code = (string) $item['component_code'];
            $efficiencyLevel = max(0, min(5, (int) ($item['efficiency_level'] ?? 0)));
            $weightingFactor = max(0, min(5, (int) ($item['weighting_factor'] ?? 0)));

            $score = $efficiencyLevel;
            $levelScore = ScorecardComponent::LEVEL_SCORES[$efficiencyLevel] ?? 0.00;

            // Adjusted score = level score - nature deduction (cannot be negative)
            $adjustedScore = $efficiencyLevel > 0 ? max(0.00, $levelScore - $deduction) : 0.00;

            $weightingScore = $weightingFactor;
            $weightedScore = $adjustedScore * $weightingScore;

            $totalWeight += $weightingScore;
            $totalWeightedScore += $weightedScore;

            $processedComponents[] = [
                'component_code' => $code,
                'efficiency_level' => $efficiencyLevel,
                'weighting_factor' => $weightingFactor,
                'score' => $score,
                'level_score' => $levelScore,
                'adjusted_score' => $adjustedScore,
                'weighting_score' => $weightingScore,
                'weighted_score' => $weightedScore,
                'notes' => $item['notes'] ?? null,
                'sort_order' => $sortOrder++,
            ];
        }

        $globalScore = $totalWeight > 0 ? ($totalWeightedScore / $totalWeight) : 0.0;

        return [
            'control_nature' => $controlNature,
            'components' => $processedComponents,
            'total_weight' => $totalWeight,
            'total_weighted_score' => $totalWeightedScore,
            'global_score' => $globalScore,
        ];
    }

    /**
     * Compute Expected Annual Frequency (lambda) and Interval (Spike §2).
     *
     * Decoded RPN: lambda = Count * (1 - ScorecardScore) * Probability.
     * Interval = 1 / lambda (in years).
     *
     * @param  array<int, array{driver_type: string, computation_role: string, value: float|string}>  $driversData
     * @param  float|null  $scorecardScore  Global score from scorecard if linked
     * @return array{
     *     total_frequency: float,
     *     interval_years: float,
     *     interval_display: string
     * }
     */
    public function computeFrequency(array $driversData, ?float $scorecardScore = null): array
    {
        $count = 0.0;
        $controlEfficiency = $scorecardScore ?? 0.0;
        $probability = 1.0;

        foreach ($driversData as $driver) {
            $role = (string) ($driver['computation_role'] ?? '');
            $val = (float) ($driver['value'] ?? 0.0);

            if ($role === 'count') {
                $count = $val;
            } elseif ($role === 'control_score_complement') {
                $isLinked = ! empty($driver['sm_scorecard_id']) || ! empty($driver['use_scorecard']);
                if ($isLinked && $scorecardScore !== null) {
                    $controlEfficiency = (float) $scorecardScore;
                } elseif (isset($driver['value']) && $driver['value'] !== null && $driver['value'] !== '') {
                    $controlEfficiency = (float) $driver['value'];
                } else {
                    $controlEfficiency = $scorecardScore ?? 0.0;
                }
            } elseif ($role === 'probability') {
                $probability = $val;
            }
        }

        $lambda = round($count * (1.0 - $controlEfficiency) * $probability, 6);
        $intervalYears = $lambda > 0.0 ? (1.0 / $lambda) : 0.0;

        $days = round($intervalYears * 365.25);
        $display = $lambda > 0.0
            ? sprintf('1 event in %.3f year(s) (~%d days)', $intervalYears, $days)
            : '0 events / year (no occurrences expected)';

        return [
            'total_frequency' => $lambda,
            'interval_years' => $intervalYears,
            'interval_display' => $display,
        ];
    }

    /**
     * Compute Gross Impact for each severity per impact type and aggregate totals (Spike §3).
     *
     * Template 1 (staff_turnover_interval):
     *   type_total(sev) = count * salary * months(sev) * frequency_interval
     * Template 2 (litigation_probability):
     *   type_total(sev) = (count * compensation + fees) * probability(sev)
     *
     * @param  array<int, array{
     *     name: string,
     *     aggregation_template: string,
     *     eligible_to_transfer: bool,
     *     criteria: array<int, array{computation_role: string, typical_value: float|string, serious_value: float|string, extreme_value: float|string}>
     * }>  $typesData
     * @param  float  $frequencyInterval  From frequency calculation (1 / lambda)
     * @return array{
     *     types: array<int, array{
     *         name: string,
     *         typical_total: float,
     *         serious_total: float,
     *         extreme_total: float,
     *         eligible_to_transfer: bool
     *     }>,
     *     gross_impact: array{typical: float, serious: float, extreme: float},
     *     transferable_amount: array{typical: float, serious: float, extreme: float}
     * }
     */
    public function computeImpact(array $typesData, float $frequencyInterval): array
    {
        $processedTypes = [];
        $grossTotals = ['typical' => 0.0, 'serious' => 0.0, 'extreme' => 0.0];
        $transferableTotals = ['typical' => 0.0, 'serious' => 0.0, 'extreme' => 0.0];

        foreach ($typesData as $type) {
            $template = (string) ($type['aggregation_template'] ?? 'staff_turnover_interval');
            $eligible = (bool) ($type['eligible_to_transfer'] ?? false);
            $criteria = $type['criteria'] ?? [];

            $severities = ['typical', 'serious', 'extreme'];
            $typeTotals = ['typical' => 0.0, 'serious' => 0.0, 'extreme' => 0.0];

            // Every role reads the column being computed (production defect SM-2026-001,
            // Bug 1: count, unit_amount and flat_fee used to read `typical_value` for all three
            // severities). The workbook carries the same count/amount in each column, so its
            // golden figures are unchanged by reading per column.
            $col = fn (string $role, string $sev): float => $this->criterionValue($criteria, $role, $sev);

            if ($template === 'staff_turnover_interval') {
                foreach ($severities as $sev) {
                    $typeTotals[$sev] = $col('count', $sev) * $col('unit_amount', $sev) * $col('duration', $sev) * $frequencyInterval;
                }
            } elseif ($template === 'litigation_probability') {
                foreach ($severities as $sev) {
                    $baseCost = ($col('count', $sev) * $col('unit_amount', $sev)) + $col('flat_fee', $sev);
                    $typeTotals[$sev] = $baseCost * $col('probability', $sev);
                }
            }

            foreach ($severities as $sev) {
                $grossTotals[$sev] += $typeTotals[$sev];
                if ($eligible) {
                    $transferableTotals[$sev] += $typeTotals[$sev];
                }
            }

            $processedTypes[] = [
                'name' => (string) ($type['name'] ?? ''),
                'aggregation_template' => $template,
                'typical_total' => $typeTotals['typical'],
                'serious_total' => $typeTotals['serious'],
                'extreme_total' => $typeTotals['extreme'],
                'eligible_to_transfer' => $eligible,
            ];
        }

        return [
            'types' => $processedTypes,
            'gross_impact' => $grossTotals,
            'transferable_amount' => $transferableTotals,
        ];
    }

    /**
     * The value of the LAST criterion carrying `$role` in the `$severity` column — last-wins
     * matches the loop this replaced, so a category with two rows of one role behaves as before.
     *
     * @param  array<int, array<string, mixed>>  $criteria
     */
    private function criterionValue(array $criteria, string $role, string $severity): float
    {
        $value = 0.0;
        foreach ($criteria as $crit) {
            if (($crit['computation_role'] ?? '') === $role) {
                $value = (float) ($crit["{$severity}_value"] ?? 0.0);
            }
        }

        return $value;
    }

    /**
     * Compute Insurance Risk Transfer Netting (Spike §4).
     *
     * Tiered residual retained amount:
     *   t < dedL        -> t
     *   dedL <= t < limL-> dedL
     *   limL <= t < dedG-> t - limL + dedL
     *   dedG <= t < limG-> dedG - limL + dedL
     *   t >= limG       -> t - limG + dedG - limL + dedL
     *
     * Total haircut = 1 - (recovery_rate * probability_of_cover) + default_haircut
     * Haircut impact = (transferable - residual) * total_haircut
     * Net impact = residual + haircut_impact + gross - transferable
     *
     * @param  array<string, array{
     *     deductible_local?: float|string,
     *     limit_local?: float|string,
     *     deductible_group?: float|string,
     *     limit_group?: float|string,
     *     recovery_rate?: float|string,
     *     probability_of_cover?: float|string,
     *     insurer_default_haircut?: float|string
     * }>  $coveragesData
     * @param  array{typical: float, serious: float, extreme: float}  $grossImpact
     * @param  array{typical: float, serious: float, extreme: float}  $transferableAmounts
     * @return array<string, array{
     *     transferable_amount: float,
     *     deductible_local: float,
     *     limit_local: float,
     *     deductible_group: float,
     *     limit_group: float,
     *     recovery_rate: float,
     *     probability_of_cover: float,
     *     insurer_default_haircut: float,
     *     residual_amount: float,
     *     total_haircut: float,
     *     haircut_impact: float,
     *     gross_impact: float,
     *     net_impact: float
     * }>
     */
    public function computeInsurance(array $coveragesData, array $grossImpact, array $transferableAmounts): array
    {
        $severities = ['typical', 'serious', 'extreme'];
        $results = [];

        foreach ($severities as $sev) {
            $t = (float) ($transferableAmounts[$sev] ?? 0.0);
            $gross = (float) ($grossImpact[$sev] ?? 0.0);

            $cov = $coveragesData[$sev] ?? [];
            $dedL = (float) ($cov['deductible_local'] ?? 0.0);
            $limL = (float) ($cov['limit_local'] ?? 0.0);
            $dedG = (float) ($cov['deductible_group'] ?? 0.0);
            $limG = (float) ($cov['limit_group'] ?? 0.0);

            $recoveryRate = isset($cov['recovery_rate']) ? (float) $cov['recovery_rate'] : 1.0;
            $probCover = isset($cov['probability_of_cover']) ? (float) $cov['probability_of_cover'] : 1.0;
            $defaultHaircut = (float) ($cov['insurer_default_haircut'] ?? 0.0);

            // Tiered residual retained amount per ADR 0027 Amendment 2 §A2.6.4
            if ($t < $dedL) {
                $residual = $t;
            } elseif ($t < $limL) {
                $residual = $dedL;
            } elseif ($t < $dedG) {
                $residual = $t - $limL + $dedL;
            } elseif ($t < $limG) {
                $residual = $dedG - $limL + $dedL;
            } else {
                $residual = $t - $limG + $dedG - $limL + $dedL;
            }

            // Haircut factors per ADR 0027 Amendment 2 §A2.6.4
            $totalHaircut = 1.0 - ($recoveryRate * $probCover) + $defaultHaircut;
            $haircutImpact = ($t - $residual) * $totalHaircut;

            // Net impact
            $netImpact = $residual + $haircutImpact + $gross - $t;

            $results[$sev] = [
                'transferable_amount' => $t,
                'deductible_local' => $dedL,
                'limit_local' => $limL,
                'deductible_group' => $dedG,
                'limit_group' => $limG,
                'recovery_rate' => $recoveryRate,
                'probability_of_cover' => $probCover,
                'insurer_default_haircut' => $defaultHaircut,
                'residual_amount' => $residual,
                'total_haircut' => $totalHaircut,
                'haircut_impact' => $haircutImpact,
                'gross_impact' => $gross,
                'net_impact' => $netImpact,
            ];
        }

        return $results;
    }

    /**
     * Recompute and persist all quantitative engine calculations for a scenario.
     */
    public function recomputeAll(Scenario $scenario): void
    {
        DB::transaction(function () use ($scenario) {
            // 1. Scorecard
            $scorecard = $scenario->scorecard()->with('components')->first();
            $globalScore = null;
            if ($scorecard) {
                $componentsData = $scorecard->components->map(fn ($c) => [
                    'component_code' => $c->component_code,
                    'efficiency_level' => $c->efficiency_level,
                    'weighting_factor' => $c->weighting_factor,
                    'notes' => $c->notes,
                ])->all();

                $scResult = $this->computeScorecard($scorecard->control_nature, $componentsData);

                $scorecard->update([
                    'total_weight' => $scResult['total_weight'],
                    'total_weighted_score' => $scResult['total_weighted_score'],
                    'global_score' => $scResult['global_score'],
                ]);

                foreach ($scResult['components'] as $c) {
                    $scorecard->components()
                        ->where('component_code', $c['component_code'])
                        ->update([
                            'score' => $c['score'],
                            'level_score' => $c['level_score'],
                            'adjusted_score' => $c['adjusted_score'],
                            'weighting_score' => $c['weighting_score'],
                            'weighted_score' => $c['weighted_score'],
                        ]);
                }

                $globalScore = $scResult['global_score'];
            }

            // 2. Frequency
            $drivers = $scenario->frequencyDrivers()->get();
            $driversData = $drivers->map(fn ($d) => [
                'driver_type' => $d->driver_type,
                'computation_role' => $d->computation_role,
                'value' => (float) $d->value,
                'sm_scorecard_id' => $d->sm_scorecard_id,
                'use_scorecard' => $d->isLinkedToScorecard(),
            ])->all();

            $freqResult = $this->computeFrequency($driversData, $globalScore);

            // 3. Impact
            $impactTypes = $scenario->impactTypes()->with('criteria')->get();
            $typesData = $impactTypes->map(fn ($t) => [
                'name' => $t->name,
                'aggregation_template' => $t->aggregation_template,
                'eligible_to_transfer' => $t->eligible_to_transfer,
                'criteria' => $t->criteria->map(fn ($c) => [
                    'computation_role' => $c->computation_role,
                    'typical_value' => (float) $c->typical_value,
                    'serious_value' => (float) $c->serious_value,
                    'extreme_value' => (float) $c->extreme_value,
                ])->all(),
            ])->all();

            $impactResult = $this->computeImpact($typesData, $freqResult['interval_years']);

            foreach ($impactResult['types'] as $idx => $tRes) {
                if (isset($impactTypes[$idx])) {
                    $impactTypes[$idx]->update([
                        'typical_total' => $tRes['typical_total'],
                        'serious_total' => $tRes['serious_total'],
                        'extreme_total' => $tRes['extreme_total'],
                    ]);
                }
            }

            // 4. Insurance
            $coverages = $scenario->insuranceCoverages()->get()->keyBy('severity');
            $covData = [];
            foreach (['typical', 'serious', 'extreme'] as $sev) {
                $cov = $coverages->get($sev);
                $covData[$sev] = [
                    'deductible_local' => $cov?->deductible_local ?? 0.0,
                    'limit_local' => $cov?->limit_local ?? 0.0,
                    'deductible_group' => $cov?->deductible_group ?? 0.0,
                    'limit_group' => $cov?->limit_group ?? 0.0,
                    'recovery_rate' => $cov?->recovery_rate ?? 1.0,
                    'probability_of_cover' => $cov?->probability_of_cover ?? 1.0,
                    'insurer_default_haircut' => $cov?->insurer_default_haircut ?? 0.0,
                ];
            }

            $insResult = $this->computeInsurance($covData, $impactResult['gross_impact'], $impactResult['transferable_amount']);

            foreach (['typical', 'serious', 'extreme'] as $sev) {
                $res = $insResult[$sev];
                InsuranceCoverage::updateOrCreate(
                    [
                        'organization_id' => $scenario->organization_id,
                        'sm_scenario_id' => $scenario->id,
                        'severity' => $sev,
                    ],
                    $res
                );
            }
        });
    }
}

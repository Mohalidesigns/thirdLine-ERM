<?php

namespace App\Services;

use App\Models\QuantificationScenario;
use App\Models\SimulationRun;
use App\Models\SimulationResult;

class MonteCarloService
{
    /**
     * Generate Poisson random variate for frequency
     */
    private function poissonRandom(float $lambda): int
    {
        $L = exp(-$lambda);
        $k = 0;
        $p = 1.0;

        do {
            $k++;
            $p *= mt_rand() / mt_getrandmax();
        } while ($p > $L);

        return $k - 1;
    }

    /**
     * Generate Lognormal random variate for severity
     */
    private function lognormalRandom(float $mu, float $sigma): float
    {
        // Box-Muller transform
        $u1 = mt_rand() / mt_getrandmax();
        $u2 = mt_rand() / mt_getrandmax();
        $z = sqrt(-2 * log(max($u1, 0.0001))) * cos(2 * M_PI * $u2);

        return exp($mu + $sigma * $z);
    }

    /**
     * Run Monte Carlo simulation for a set of scenarios
     */
    public function runSimulation(SimulationRun $run, array $scenarioIds): SimulationRun
    {
        $run->update(['status' => 'running', 'started_at' => now()]);

        $iterations = $run->iterations ?? 10000;
        $scenarios = QuantificationScenario::whereIn('id', $scenarioIds)->get();

        $totalLosses = array_fill(0, $iterations, 0);
        $scenarioResults = [];

        foreach ($scenarios as $scenario) {
            $scenarioLosses = [];

            // Get frequency parameters from DB columns
            $freqMean = (float) ($scenario->frequency_lambda ?? $scenario->expected_annual_frequency ?? 2);

            // Get severity parameters from DB columns
            $sevMu = (float) ($scenario->severity_mu ?? log(max($scenario->expected_loss_per_event_kobo ?? 100000000, 1)));
            $sevSigma = (float) ($scenario->severity_sigma ?? 1.5);
            $minLoss = (float) ($scenario->severity_min_kobo ?? 0);
            $maxLoss = (float) ($scenario->severity_max_kobo ?? PHP_INT_MAX);

            for ($i = 0; $i < $iterations; $i++) {
                $eventCount = $this->poissonRandom($freqMean);
                $annualLoss = 0;

                for ($j = 0; $j < $eventCount; $j++) {
                    $loss = $this->lognormalRandom($sevMu, $sevSigma);
                    $loss = max($minLoss, min($maxLoss, $loss));
                    $annualLoss += $loss;
                }

                $scenarioLosses[] = $annualLoss;
                $totalLosses[$i] += $annualLoss;
            }

            sort($scenarioLosses);

            $expectedLoss = array_sum($scenarioLosses) / $iterations;
            $var90 = $scenarioLosses[(int)($iterations * 0.90)] ?? 0;
            $var95 = $scenarioLosses[(int)($iterations * 0.95)] ?? 0;
            $var99 = $scenarioLosses[(int)($iterations * 0.99)] ?? 0;
            $var999 = $scenarioLosses[min((int)($iterations * 0.999), $iterations - 1)] ?? 0;

            $mean = $expectedLoss;
            $variance = 0;
            foreach ($scenarioLosses as $l) {
                $variance += ($l - $mean) ** 2;
            }
            $stdDev = sqrt($variance / $iterations);

            // Generate percentile distribution
            $percentiles = [];
            foreach ([5, 10, 25, 50, 75, 90, 95, 99, 99.5, 99.9] as $p) {
                $idx = (int)(($iterations - 1) * $p / 100);
                $idx = min($idx, count($scenarioLosses) - 1);
                $percentiles["p{$p}"] = round($scenarioLosses[$idx] ?? 0);
            }

            SimulationResult::create([
                'simulation_run_id' => $run->id,
                'scenario_id' => $scenario->id,
                'result_type' => 'scenario',
                'expected_annual_loss_kobo' => round($expectedLoss),
                'var_90_kobo' => round($var90),
                'var_95_kobo' => round($var95),
                'var_99_kobo' => round($var99),
                'var_99_9_kobo' => round($var999),
                'std_deviation_kobo' => round($stdDev),
                'percentile_distribution' => $percentiles,
                'risk_contributions' => [
                    'scenario_name' => $scenario->name,
                    'frequency_mean' => $freqMean,
                    'expected_loss_pct' => 0,
                ],
            ]);

            $scenarioResults[$scenario->id] = $expectedLoss;
        }

        // Aggregate results
        sort($totalLosses);
        $totalExpected = array_sum($totalLosses) / $iterations;

        $totalPercentiles = [];
        foreach ([5, 10, 25, 50, 75, 90, 95, 99, 99.5, 99.9] as $p) {
            $idx = (int)(($iterations - 1) * $p / 100);
            $idx = min($idx, count($totalLosses) - 1);
            $totalPercentiles["p{$p}"] = round($totalLosses[$idx] ?? 0);
        }

        $totalVariance = 0;
        foreach ($totalLosses as $l) {
            $totalVariance += ($l - $totalExpected) ** 2;
        }

        // Risk contributions
        $contributions = [];
        foreach ($scenarioResults as $sid => $el) {
            $contributions[$sid] = $totalExpected > 0 ? round(($el / $totalExpected) * 100, 1) : 0;
        }

        SimulationResult::create([
            'simulation_run_id' => $run->id,
            'scenario_id' => null,
            'result_type' => 'aggregate',
            'expected_annual_loss_kobo' => round($totalExpected),
            'var_90_kobo' => round($totalLosses[(int)($iterations * 0.90)] ?? 0),
            'var_95_kobo' => round($totalLosses[(int)($iterations * 0.95)] ?? 0),
            'var_99_kobo' => round($totalLosses[(int)($iterations * 0.99)] ?? 0),
            'var_99_9_kobo' => round($totalLosses[min((int)($iterations * 0.999), $iterations - 1)] ?? 0),
            'std_deviation_kobo' => round(sqrt($totalVariance / $iterations)),
            'percentile_distribution' => $totalPercentiles,
            'risk_contributions' => $contributions,
        ]);

        $startedAt = $run->started_at;
        $runtimeSeconds = $startedAt ? now()->diffInSeconds($startedAt) : 0;

        $run->update([
            'status' => 'completed',
            'completed_at' => now(),
            'runtime_seconds' => $runtimeSeconds,
        ]);

        return $run->fresh();
    }
}

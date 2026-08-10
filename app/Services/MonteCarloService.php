<?php

namespace App\Services;

use App\Models\QuantificationScenario;
use App\Models\SimulationResult;
use App\Models\SimulationRun;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

class MonteCarloService
{
    /**
     * Upper bound of the uniform integer draw. Matches mt_getrandmax() so the
     * variate granularity is identical to the pre-seeding implementation.
     */
    private const RANDOM_MAX = 2147483647;

    private readonly Randomizer $randomizer;

    /**
     * The seed actually in force, so the run can record it.
     */
    private readonly int $seed;

    /**
     * Simulation engines are the one place an RNG is legitimate (see the
     * engineering rules), but the generator has to be *seedable*: a capital
     * number a regulator can ask about is worthless if nobody can reproduce
     * the run that produced it, and a VaR figure cannot be unit tested against
     * a global mt_rand() sequence that every other call in the process
     * perturbs. An explicit seed gives both.
     *
     * Passing null draws a fresh seed — production behaviour is unchanged
     * apart from the seed now being recorded on the run.
     */
    public function __construct(?int $seed = null)
    {
        $this->seed = $seed ?? random_int(0, self::RANDOM_MAX);
        $this->randomizer = new Randomizer(new Mt19937($this->seed));
    }

    public function seed(): int
    {
        return $this->seed;
    }

    /**
     * Draw a seed without constructing an engine.
     *
     * WP-07 dispatches the simulation to a worker, and the seed has to be
     * decided and recorded when the run is QUEUED — otherwise the figure is not
     * re-derivable until the job starts, and a replayed job would draw a
     * different one and produce a different capital number from the same
     * request.
     *
     * It lives here rather than in the controller on purpose: this file is the
     * single allowlisted home for random number generation (see
     * NoFabricatedNumbersTest and scripts/check-no-rng.sh), and the whole point
     * of that allowlist is that a controller never contains an RNG call. WP-02
     * removed twelve of them from exactly that layer.
     */
    public static function drawSeed(): int
    {
        return random_int(0, self::RANDOM_MAX);
    }

    /**
     * Uniform variate on [0, 1].
     */
    private function uniform(): float
    {
        return $this->randomizer->getInt(0, self::RANDOM_MAX) / self::RANDOM_MAX;
    }

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
            $p *= $this->uniform();
        } while ($p > $L);

        return $k - 1;
    }

    /**
     * Generate Lognormal random variate for severity
     */
    private function lognormalRandom(float $mu, float $sigma): float
    {
        // Box-Muller transform
        $u1 = $this->uniform();
        $u2 = $this->uniform();
        $z = sqrt(-2 * log(max($u1, 0.0001))) * cos(2 * M_PI * $u2);

        return exp($mu + $sigma * $z);
    }

    /**
     * Run Monte Carlo simulation for a set of scenarios.
     *
     * WP-07: two optional hooks, both of which leave the arithmetic untouched.
     *
     *   $onProgress(int $completed, int $total)  called at a throttled interval
     *   $shouldCancel(): bool                    checked at safe points only
     *
     * Neither draws from the randomizer, so a run with hooks and a run without
     * them produce identical figures from the same seed — which is the property
     * the WP-01 characterisation tests exist to hold, and the reason a
     * regulator can re-derive an ICAAP number months later.
     *
     * A cancelled run writes NO results. A partial loss distribution is not a
     * smaller answer, it is a wrong one, and leaving half a run in the results
     * table is how a capital figure ends up computed from four scenarios out of
     * seven with nothing on screen to say so.
     */
    public function runSimulation(
        SimulationRun $run,
        array $scenarioIds,
        ?callable $onProgress = null,
        ?callable $shouldCancel = null,
    ): SimulationRun {
        // The seed is part of the result, not a private detail: it is what
        // makes the figure re-derivable months later during an ICAAP review.
        $run->update(['status' => 'running', 'started_at' => now(), 'random_seed' => $this->seed]);

        $iterations = $run->iterations ?? 10000;

        // Scenarios are pinned to the run's own organization rather than left
        // to the ambient tenant. Previously this was an unfiltered whereIn, so
        // posting another tenant's scenario ids simulated — and returned — their
        // loss distribution. Anchoring on $run->organization_id also keeps the
        // guarantee when this service is invoked from a queue worker, where no
        // tenant is bound and the global scope is inert.
        $scenarios = QuantificationScenario::query()
            ->where('organization_id', $run->organization_id)
            ->whereIn('id', $scenarioIds)
            // Deterministic order: the scenarios are drawn from one RNG stream,
            // so an unordered result set would make the same seed produce a
            // different answer depending on how the database returned rows.
            ->orderBy('id')
            ->get();

        // Fail loudly rather than quietly simulating a subset: a capital number
        // computed from fewer scenarios than the user selected is wrong in a way
        // nobody downstream can detect.
        if ($scenarios->count() !== count(array_unique($scenarioIds))) {
            $missing = array_values(array_diff(
                array_unique($scenarioIds),
                $scenarios->pluck('id')->all()
            ));

            $run->update(['status' => 'failed', 'completed_at' => now()]);

            throw new InvalidArgumentException(
                'Simulation scenarios do not belong to organization '
                .$run->organization_id.': ['.implode(', ', $missing).']'
            );
        }

        $totalLosses = array_fill(0, $iterations, 0);
        $scenarioResults = [];

        // Total units of work, so progress is a percentage of the whole run
        // rather than of whichever scenario happens to be in flight.
        $totalWork = max(1, $iterations * $scenarios->count());
        $completedWork = 0;
        $checkEvery = max(100, (int) ($iterations / 50));

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

                // Checked on a stride rather than every iteration: at 10,000
                // iterations a per-iteration database read would cost more than
                // the simulation it is watching.
                if (++$completedWork % $checkEvery === 0) {
                    if ($onProgress !== null) {
                        $onProgress($completedWork, $totalWork);
                    }

                    if ($shouldCancel !== null && $shouldCancel()) {
                        $run->update([
                            'status' => 'cancelled',
                            'completed_at' => now(),
                            'completed_iterations' => $completedWork,
                            'runtime_seconds' => $run->started_at ? now()->diffInSeconds($run->started_at) : 0,
                        ]);

                        return $run->fresh();
                    }
                }
            }

            sort($scenarioLosses);

            $expectedLoss = array_sum($scenarioLosses) / $iterations;
            $var90 = $scenarioLosses[(int) ($iterations * 0.90)] ?? 0;
            $var95 = $scenarioLosses[(int) ($iterations * 0.95)] ?? 0;
            $var99 = $scenarioLosses[(int) ($iterations * 0.99)] ?? 0;
            $var999 = $scenarioLosses[min((int) ($iterations * 0.999), $iterations - 1)] ?? 0;

            $mean = $expectedLoss;
            $variance = 0;
            foreach ($scenarioLosses as $l) {
                $variance += ($l - $mean) ** 2;
            }
            $stdDev = sqrt($variance / $iterations);

            // Generate percentile distribution
            $percentiles = [];
            foreach ([5, 10, 25, 50, 75, 90, 95, 99, 99.5, 99.9] as $p) {
                $idx = (int) (($iterations - 1) * $p / 100);
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
            $idx = (int) (($iterations - 1) * $p / 100);
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
            'var_90_kobo' => round($totalLosses[(int) ($iterations * 0.90)] ?? 0),
            'var_95_kobo' => round($totalLosses[(int) ($iterations * 0.95)] ?? 0),
            'var_99_kobo' => round($totalLosses[(int) ($iterations * 0.99)] ?? 0),
            'var_99_9_kobo' => round($totalLosses[min((int) ($iterations * 0.999), $iterations - 1)] ?? 0),
            'std_deviation_kobo' => round(sqrt($totalVariance / $iterations)),
            'percentile_distribution' => $totalPercentiles,
            'risk_contributions' => $contributions,
        ]);

        $startedAt = $run->started_at;
        $runtimeSeconds = $startedAt ? now()->diffInSeconds($startedAt) : 0;

        $run->update([
            'status' => 'completed',
            'progress' => 100,
            'completed_iterations' => $completedWork,
            'completed_at' => now(),
            'runtime_seconds' => $runtimeSeconds,
        ]);

        return $run->fresh();
    }
}

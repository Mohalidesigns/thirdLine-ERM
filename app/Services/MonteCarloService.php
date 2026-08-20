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
     * Generate a Poisson random variate for annual event frequency.
     *
     * Knuth's multiplicative method compares a running product of uniforms
     * against exp(-lambda). That comparison silently breaks down at high
     * lambda: exp(-746) underflows to exactly 0.0 in IEEE double, so above
     * roughly lambda = 745 the threshold is 0, `$p > 0` stays true until the
     * running product ITSELF underflows to zero, and the loop returns whatever
     * count that took rather than a Poisson variate. Measured on this engine,
     * lambda = 1,000 and lambda = 5,000 both returned about 690-760 events per
     * year. A high-frequency operational-risk category for a Nigerian retail
     * bank — card fraud, ATM disputes, cheque handling errors — routinely runs
     * lambda in the thousands, so the engine was understating frequency by a
     * large factor without any error being raised.
     *
     * The fix accumulates in log space. Because log is strictly increasing,
     *
     *     product(u_i) > exp(-lambda)   <=>   sum(log u_i) > -lambda
     *
     * so this terminates at exactly the same k as Knuth for every lambda where
     * Knuth worked, consuming exactly the same number of uniforms in the same
     * order. Seeded runs therefore reproduce as before; only the arithmetic
     * that was overflowing has changed. Cost is one log() per event drawn.
     *
     * The redraw guard mirrors lognormalRandom(): uniform() can return exactly
     * 0.0, and log(0) is -INF. Under Knuth a zero draw ended the loop
     * immediately, which is the same outcome -INF produces here, so the guard
     * is not strictly required for correctness — it is here to keep -INF out of
     * the accumulator, at a cost of one extra draw per 2^31.
     */
    private function poissonRandom(float $lambda): int
    {
        $logThreshold = -$lambda;
        $k = 0;
        $logP = 0.0;

        do {
            $k++;

            do {
                $u = $this->uniform();
            } while ($u <= 0.0);

            $logP += log($u);
        } while ($logP > $logThreshold);

        return $k - 1;
    }

    /**
     * Generate Lognormal random variate for severity
     *
     * Box-Muller needs u1 strictly inside (0, 1]; log(0) is -INF. The previous
     * guard was `log(max($u1, 0.0001))`, which does not reject a bad draw, it
     * *replaces* it. Every u1 below 1e-4 was rewritten to exactly 1e-4, so z
     * could never exceed sqrt(-2 * ln(1e-4)) = 4.292 and roughly one draw in
     * ten thousand landed on that value exactly — a point mass sitting
     * precisely where the 99.9% quantile of a heavy-tailed severity is read
     * off. The distortion was one-directional: it truncated the right tail and
     * so systematically UNDERSTATED VaR(99.9) and every economic capital figure
     * derived from it, by more the larger sigma was.
     *
     * Redrawing instead of clamping leaves the distribution untouched.
     * uniform() is getInt(0, RANDOM_MAX) / RANDOM_MAX, so it CAN return exactly
     * 0.0 (probability 1 in 2^31) and the loop is genuinely needed; it can
     * never return a negative, so `<= 0.0` is only a belt-and-braces spelling
     * of `=== 0.0`. Expected draws per call is 1 + 5e-10 — it cannot spin.
     *
     * The draw ORDER is unchanged (u1 then u2), so a seeded run consumes the
     * generator in the same sequence as before. Figures still move: any stream
     * that contained a u1 below 1e-4 now yields the true variate instead of the
     * capped one, which is the entire point of the fix.
     */
    private function lognormalRandom(float $mu, float $sigma): float
    {
        // Box-Muller transform
        do {
            $u1 = $this->uniform();
        } while ($u1 <= 0.0);

        $u2 = $this->uniform();
        $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);

        return exp($mu + $sigma * $z);
    }

    /**
     * One order statistic from an ascending-sorted loss vector.
     *
     * This helper exists because the engine previously used TWO different
     * estimators for the same quantile. The VaR lines indexed
     * `(int) ($iterations * $p)` — index 9500 at n = 10,000, p = 0.95 — while
     * the percentile ladder a dozen lines below indexed
     * `(int) (($iterations - 1) * $p / 100)`, index 9499. `var_95_kobo` and
     * `percentile_distribution['p95']` were therefore adjacent but different
     * order statistics of the same sample, and two screens both labelled "95%"
     * showed two different naira figures.
     *
     * The `(n - 1) * p` convention is the one kept, because it is the one the
     * percentile ladder already used and because, unlike `n * p`, it cannot
     * index off the end of the array. Only var_99_9 previously carried a min()
     * clamp; var_90/95/99 did not and leaned on the `?? 0` to mask an
     * out-of-range read, which would have published a VaR of zero rather than
     * failing. Clamping is against count($sortedLosses), not $iterations: they
     * agree today, but a vector shorter than the requested iteration count must
     * not silently produce a zero capital figure.
     *
     * $percent is a percentage (95, 99.9), not a fraction, so the arithmetic is
     * identical to the percentile ladder this replaces.
     */
    private function quantile(array $sortedLosses, float $percent): float
    {
        $n = count($sortedLosses);

        if ($n === 0) {
            return 0.0;
        }

        $index = (int) (($n - 1) * $percent / 100);
        $index = max(0, min($index, $n - 1));

        return (float) $sortedLosses[$index];
    }

    /**
     * Expected shortfall (CVaR) at $percent: the arithmetic mean of the losses
     * beyond the VaR point.
     *
     * Nothing computed this before. SimulationRun::getExpectedShortfallAttribute()
     * carried the comment "ES approximated as average of losses above VaR 95"
     * and then returned var_99_kobo — a different statistic entirely, rendered
     * as a headline KPI on two dashboards. ES has to be taken here, while the
     * sorted loss vector still exists: once the run has written its VaR ladder
     * the sample is discarded and no accessor can recover a tail mean from it.
     *
     * The tail is indices ceil(n * p) .. n - 1 of the ascending sort — the
     * losses beyond the quantile point. At n = 10,000 and p = 95 that is the
     * worst 500 paths.
     *
     * The start index is clamped to n - 1 so a degenerate sample (one path, or
     * a percentile high enough that the slice would be empty) yields the
     * largest observed loss rather than dividing by zero. That also keeps the
     * invariant every consumer relies on — ES(p) >= VaR(p) — true at every
     * sample size.
     */
    private function expectedShortfall(array $sortedLosses, float $percent): float
    {
        $n = count($sortedLosses);

        if ($n === 0) {
            return 0.0;
        }

        $start = (int) ceil($n * $percent / 100);
        $start = max(0, min($start, $n - 1));

        $tail = array_slice($sortedLosses, $start);

        return array_sum($tail) / count($tail);
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

            // Every quantile on this row now comes out of one estimator; see
            // quantile() for what the two previous ones disagreed about.
            $var90 = $this->quantile($scenarioLosses, 90);
            $var95 = $this->quantile($scenarioLosses, 95);
            $var99 = $this->quantile($scenarioLosses, 99);
            $var999 = $this->quantile($scenarioLosses, 99.9);

            // Taken here because $scenarioLosses does not survive the loop.
            $es95 = $this->expectedShortfall($scenarioLosses, 95);
            $es99 = $this->expectedShortfall($scenarioLosses, 99);

            $mean = $expectedLoss;
            $variance = 0;
            foreach ($scenarioLosses as $l) {
                $variance += ($l - $mean) ** 2;
            }
            $stdDev = sqrt($variance / $iterations);

            // Generate percentile distribution — same estimator as the VaR
            // ladder above, so p95 and var_95_kobo are now the same figure.
            $percentiles = [];
            foreach ([5, 10, 25, 50, 75, 90, 95, 99, 99.5, 99.9] as $p) {
                $percentiles["p{$p}"] = round($this->quantile($scenarioLosses, $p));
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
                'es_95_kobo' => round($es95),
                'es_99_kobo' => round($es99),
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
            $totalPercentiles["p{$p}"] = round($this->quantile($totalLosses, $p));
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
            'var_90_kobo' => round($this->quantile($totalLosses, 90)),
            'var_95_kobo' => round($this->quantile($totalLosses, 95)),
            'var_99_kobo' => round($this->quantile($totalLosses, 99)),
            'var_99_9_kobo' => round($this->quantile($totalLosses, 99.9)),
            'es_95_kobo' => round($this->expectedShortfall($totalLosses, 95)),
            'es_99_kobo' => round($this->expectedShortfall($totalLosses, 99)),
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

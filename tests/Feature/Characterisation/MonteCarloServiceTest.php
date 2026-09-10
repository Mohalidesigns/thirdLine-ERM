<?php

namespace Tests\Feature\Characterisation;

use App\Models\QuantificationScenario;
use App\Models\SimulationResult;
use App\Models\SimulationRun;
use App\Services\MonteCarloService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * CHARACTERISATION — crown jewel.
 *
 * Pins the quantification engine now that its generator is seedable.
 *
 * A Monte Carlo result cannot be asserted exactly against a hand-computed
 * figure — it is a sample. What it *can* be held to, and what these tests
 * hold it to, is: the same seed reproduces the run byte for byte; the VaR
 * ladder and the percentile curve are monotone AND agree with each other; the
 * sample mean converges on the analytic expected annual loss (λ · exp(μ + σ²/2));
 * expected shortfall is never below the quantile it sits beyond; the severity
 * draw is not truncated; scenario floors and caps bind; and the tenancy guard
 * refuses to simulate another organisation's scenarios.
 *
 * Until WP-08 every test in here ran at σ ≤ 0.5 and λ ≤ 3, which is why three
 * tail defects survived it: a Box-Muller clamp that capped every severity at
 * 4.292σ, a VaR ladder and a percentile ladder using two different order
 * statistics for the same confidence level, and an "expected shortfall" that
 * was VaR(99) wearing a different label. The high-σ cases below exist so that
 * class of bug cannot hide here again.
 */
class MonteCarloServiceTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    /** A seed chosen once and never changed — it is part of the fixture. */
    private const SEED = 20260615;

    private const ITERATIONS = 5000;

    /**
     * Draw count for the truncation test.
     *
     * The old clamp bit on roughly one draw in 113,000 (the normal tail beyond
     * 4.292σ), so a few thousand draws cannot see it and a test that used them
     * would pass against the broken generator. One million draws puts the
     * expected number of qualifying draws near nine and takes well under a
     * second — the seed is fixed, so the count below is a measured fact about
     * this stream, not a probability.
     */
    private const TRUNCATION_DRAWS = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Reproducibility */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_same_seed_reproduces_the_run_exactly(): void
    {
        $first = $this->simulate(self::SEED);
        $second = $this->simulate(self::SEED);

        $this->assertSame(
            $this->fingerprint($first),
            $this->fingerprint($second),
            'A seeded run must be byte-identical — this is what makes an ICAAP figure defensible.'
        );
    }

    #[Test]
    public function the_seed_is_recorded_on_the_run(): void
    {
        $run = $this->makeRun();
        (new MonteCarloService(self::SEED))->runSimulation($run, [$this->makeScenario()->id]);

        $this->assertSame(self::SEED, (int) $run->fresh()->random_seed);
    }

    #[Test]
    public function an_unseeded_service_still_records_the_seed_it_drew(): void
    {
        $service = new MonteCarloService;
        $run = $this->makeRun();

        $service->runSimulation($run, [$this->makeScenario()->id]);

        $this->assertSame($service->seed(), (int) $run->fresh()->random_seed);
    }

    /* ------------------------------------------------------------------ */
    /*  The VaR ladder and the percentile curve */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_var_ladder_is_monotone_across_confidence_levels(): void
    {
        $aggregate = $this->simulate(self::SEED)->firstWhere('result_type', 'aggregate');

        $this->assertLessThanOrEqual($aggregate->var_90_kobo, $aggregate->expected_annual_loss_kobo);
        $this->assertLessThanOrEqual($aggregate->var_95_kobo, $aggregate->var_90_kobo);
        $this->assertLessThanOrEqual($aggregate->var_99_kobo, $aggregate->var_95_kobo);
        $this->assertLessThanOrEqual($aggregate->var_99_9_kobo, $aggregate->var_99_kobo);
    }

    #[Test]
    public function the_percentile_curve_covers_every_published_level_and_never_decreases(): void
    {
        $aggregate = $this->simulate(self::SEED)->firstWhere('result_type', 'aggregate');
        $curve = $aggregate->percentile_distribution;

        $this->assertSame(
            ['p5', 'p10', 'p25', 'p50', 'p75', 'p90', 'p95', 'p99', 'p99.5', 'p99.9'],
            array_keys($curve)
        );

        $previous = null;
        foreach ($curve as $label => $value) {
            if ($previous !== null) {
                $this->assertGreaterThanOrEqual($previous, $value, "percentile {$label} went backwards");
            }
            $previous = $value;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Convergence on the analytic mean */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_sample_mean_converges_on_the_analytic_expected_annual_loss(): void
    {
        // Compound Poisson–lognormal: E[S] = λ · exp(μ + σ²/2).
        $lambda = 2.0;
        $mu = 10.0;
        $sigma = 0.5;
        $analytic = $lambda * exp($mu + ($sigma ** 2) / 2);

        $run = $this->makeRun();
        $scenario = $this->makeScenario([
            'frequency_lambda' => $lambda,
            'severity_mu' => $mu,
            'severity_sigma' => $sigma,
        ]);

        (new MonteCarloService(self::SEED))->runSimulation($run, [$scenario->id]);

        $expected = (float) SimulationResult::where('simulation_run_id', $run->id)
            ->where('result_type', 'scenario')
            ->value('expected_annual_loss_kobo');

        // 5,000 paths of a heavy-tailed compound distribution: a 20% band is
        // tight enough to catch a broken distribution and loose enough not to
        // fail on sampling noise.
        $this->assertEqualsWithDelta($analytic, $expected, $analytic * 0.20);
    }

    #[Test]
    public function frequency_falls_back_to_expected_annual_frequency_when_lambda_is_absent(): void
    {
        $run = $this->makeRun();
        $scenario = $this->makeScenario([
            'frequency_lambda' => null,
            'expected_annual_frequency' => 3.0,
            'severity_mu' => 10.0,
            'severity_sigma' => 0.5,
        ]);

        (new MonteCarloService(self::SEED))->runSimulation($run, [$scenario->id]);

        $contributions = SimulationResult::where('simulation_run_id', $run->id)
            ->where('result_type', 'scenario')
            ->value('risk_contributions');

        $this->assertSame(3.0, (float) $contributions['frequency_mean']);
    }

    /* ------------------------------------------------------------------ */
    /*  Severity floor and cap */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_severity_cap_binds_every_draw(): void
    {
        // Cap far below the distribution's mass: every non-zero year must land
        // on an exact multiple of the cap.
        $cap = 1_000;

        $run = $this->makeRun();
        $scenario = $this->makeScenario([
            'frequency_lambda' => 1.0,
            'severity_mu' => 15.0,
            'severity_sigma' => 0.5,
            'severity_max_kobo' => $cap,
        ]);

        (new MonteCarloService(self::SEED))->runSimulation($run, [$scenario->id]);

        $result = SimulationResult::where('simulation_run_id', $run->id)
            ->where('result_type', 'scenario')->first();

        $this->assertLessThanOrEqual($cap * 10, $result->var_99_9_kobo);
        $this->assertSame(0, $result->var_99_9_kobo % $cap, 'capped losses are whole multiples of the cap');
    }

    /* ------------------------------------------------------------------ */
    /*  Aggregation across scenarios */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_aggregate_row_sums_the_scenarios_and_attributes_contributions(): void
    {
        $run = $this->makeRun();
        $a = $this->makeScenario(['frequency_lambda' => 2.0, 'severity_mu' => 10.0, 'severity_sigma' => 0.5]);
        $b = $this->makeScenario(['frequency_lambda' => 1.0, 'severity_mu' => 9.0, 'severity_sigma' => 0.5]);

        (new MonteCarloService(self::SEED))->runSimulation($run, [$a->id, $b->id]);

        $results = SimulationResult::where('simulation_run_id', $run->id)->get();
        $this->assertCount(3, $results, 'one row per scenario plus one aggregate');

        $aggregate = $results->firstWhere('result_type', 'aggregate');
        $scenarioTotal = $results->where('result_type', 'scenario')->sum('expected_annual_loss_kobo');

        // Expectation is additive even though the tails are not.
        $this->assertEqualsWithDelta($scenarioTotal, $aggregate->expected_annual_loss_kobo, 2);
        $this->assertNull($aggregate->scenario_id);

        $contributions = $aggregate->risk_contributions;
        $this->assertEqualsWithDelta(100.0, array_sum($contributions), 0.5);
        $this->assertArrayHasKey((string) $a->id, $contributions);
        $this->assertArrayHasKey((string) $b->id, $contributions);
    }

    #[Test]
    public function a_completed_run_is_marked_completed(): void
    {
        $run = $this->makeRun();
        (new MonteCarloService(self::SEED))->runSimulation($run, [$this->makeScenario()->id]);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->completed_at);
    }

    /* ------------------------------------------------------------------ */
    /*  Tenancy guard */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function simulating_a_scenario_from_another_organisation_fails_loudly(): void
    {
        $run = $this->makeRun();
        $mine = $this->makeScenario();

        $foreignId = TenantContext::actingAs(null, function () {
            $organizationId = \App\Models\Organization::create([
                'name' => 'Other Bank PLC',
                'short_name' => 'OTHR',
                'institution_type' => 'commercial_bank',
                'sector' => 'banking',
                'is_active' => true,
            ])->id;

            return QuantificationScenario::create([
                'organization_id' => $organizationId,
                'scenario_reference' => 'QS-OTHER-0001',
                'scenario_type' => 'OPERATIONAL',
                'name' => 'Foreign scenario',
                'frequency_lambda' => 1.0,
                'severity_mu' => 10.0,
                'severity_sigma' => 0.5,
                'created_by' => $this->actor->id,
            ])->id;
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('do not belong to organization');

        try {
            (new MonteCarloService(self::SEED))->runSimulation($run, [$mine->id, $foreignId]);
        } finally {
            // The run must not be left sitting in "running" forever.
            $this->assertSame('failed', $run->fresh()->status);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Tail truncation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_severity_draw_is_no_longer_capped_at_the_old_four_point_two_nine_sigma_ceiling(): void
    {
        $mu = 10.0;
        $sigma = 2.0;

        // The old Box-Muller guard was `log(max($u1, 0.0001))`, which replaced
        // every u1 below 1e-4 with exactly 1e-4 instead of redrawing it. That
        // put a hard arithmetic ceiling of sqrt(-2 · ln(1e-4)) = 4.291932 on z,
        // and so a hard ceiling of exp(μ + 4.291932σ) on any single event — no
        // seed, and no number of iterations, could ever produce a severity
        // above this line.
        $ceiling = exp($mu + sqrt(-2 * log(0.0001)) * $sigma);

        $service = new MonteCarloService(self::SEED);

        // The draw itself is what was broken, so the draw itself is what is
        // asserted on. Going through runSimulation() cannot see this: the
        // published p99.9 of an ANNUAL loss sits around 3.1σ of the severity
        // distribution, comfortably below the 4.292σ ceiling, so a whole-run
        // assertion against the ceiling would pass against the broken engine.
        $draw = fn (): float => $this->lognormalRandom($mu, $sigma);

        $maximum = 0.0;
        $aboveCeiling = 0;

        for ($i = 0; $i < self::TRUNCATION_DRAWS; $i++) {
            $loss = $draw->call($service);

            $maximum = max($maximum, $loss);

            if ($loss > $ceiling) {
                $aboveCeiling++;
            }
        }

        $this->assertGreaterThan(
            0,
            $aboveCeiling,
            'The severity distribution is truncated: no draw exceeded exp(mu + 4.292 sigma), '
            .'which is exactly what the old clamp guaranteed.'
        );

        $this->assertGreaterThan(
            $ceiling,
            $maximum,
            'The largest severity drawn is still bounded by the old clamp ceiling.'
        );
    }

    #[Test]
    public function a_high_sigma_scenario_keeps_a_heavy_tail_and_still_converges(): void
    {
        // σ = 2.0. Every other test in this file runs at σ ≤ 0.5, where the
        // 99.9% quantile is under twice the 95% one and a truncated tail is
        // invisible.
        $lambda = 2.0;
        $mu = 10.0;
        $sigma = 2.0;

        $run = $this->makeRun();
        $scenario = $this->makeScenario([
            'frequency_lambda' => $lambda,
            'severity_mu' => $mu,
            'severity_sigma' => $sigma,
        ]);

        (new MonteCarloService(self::SEED))->runSimulation($run, [$scenario->id]);

        $result = SimulationResult::where('simulation_run_id', $run->id)
            ->where('result_type', 'scenario')->first();

        // Strictly increasing, not merely non-decreasing: at this σ the loss
        // distribution is continuous enough that two adjacent confidence levels
        // landing on the same naira figure would mean the ladder had collapsed.
        $this->assertGreaterThan($result->var_90_kobo, $result->var_95_kobo);
        $this->assertGreaterThan($result->var_95_kobo, $result->var_99_kobo);
        $this->assertGreaterThan($result->var_99_kobo, $result->var_99_9_kobo);

        // At σ = 0.5 this ratio is about 1.8; at σ = 2.0 it is about 14. The
        // floor is set well below the observed value — the assertion is that
        // the heavy-tailed path is genuinely exercised, not that the sample
        // reproduces a particular figure.
        $this->assertGreaterThan(
            3 * $result->var_95_kobo,
            $result->var_99_9_kobo,
            'A σ = 2.0 run produced a flat tail, which means the severity draw is being bounded somewhere.'
        );

        // E[S] = λ · exp(μ + σ²/2). A single draw at σ = 2 has a coefficient of
        // variation of 7.3, so the standard error of the mean over ~10,000
        // draws is about 7% — a 30% band is the honest width here, against the
        // 20% the σ = 0.5 test can afford.
        $analytic = $lambda * exp($mu + ($sigma ** 2) / 2);

        $this->assertEqualsWithDelta(
            $analytic,
            (float) $result->expected_annual_loss_kobo,
            $analytic * 0.30
        );
    }

    /* ------------------------------------------------------------------ */
    /*  One estimator, not two */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_var_ladder_and_the_percentile_curve_are_the_same_order_statistic(): void
    {
        // The VaR lines used `(int) ($iterations * $p)` and the percentile
        // ladder `(int) (($iterations - 1) * $p / 100)` — index 9500 against
        // index 9499 at 10,000 iterations. Two screens, both labelled "95%",
        // showed two different naira figures off one sample.
        $results = $this->simulate(self::SEED);

        $pairs = [
            'var_90_kobo' => 'p90',
            'var_95_kobo' => 'p95',
            'var_99_kobo' => 'p99',
            'var_99_9_kobo' => 'p99.9',
        ];

        foreach (['scenario', 'aggregate'] as $resultType) {
            $row = $results->firstWhere('result_type', $resultType);
            $this->assertNotNull($row, "no {$resultType} row was written");

            foreach ($pairs as $column => $key) {
                $this->assertSame(
                    (int) $row->{$column},
                    (int) $row->percentile_distribution[$key],
                    "{$resultType}: {$column} and percentile {$key} disagree — two estimators again"
                );
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Expected shortfall */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function expected_shortfall_is_never_below_the_quantile_it_sits_beyond(): void
    {
        // ES(p) is the mean of the losses beyond VaR(p) on an ascending sort,
        // so it cannot be smaller than VaR(p) at any sample size. This is the
        // invariant the old accessor could not have satisfied except by luck:
        // it returned VaR(99) under the label "expected shortfall at 95%".
        $results = $this->simulate(self::SEED);

        foreach (['scenario', 'aggregate'] as $resultType) {
            $row = $results->firstWhere('result_type', $resultType);

            $this->assertNotNull($row->es_95_kobo, "{$resultType}: no ES(95) was stored");
            $this->assertNotNull($row->es_99_kobo, "{$resultType}: no ES(99) was stored");

            $this->assertGreaterThanOrEqual($row->var_95_kobo, $row->es_95_kobo, "{$resultType}: ES(95) below VaR(95)");
            $this->assertGreaterThanOrEqual($row->var_99_kobo, $row->es_99_kobo, "{$resultType}: ES(99) below VaR(99)");

            // The 99% tail is a subset of the 95% tail and lies entirely above
            // it, so its mean cannot be lower.
            $this->assertGreaterThanOrEqual($row->es_95_kobo, $row->es_99_kobo, "{$resultType}: ES(99) below ES(95)");
        }
    }

    #[Test]
    public function the_expected_shortfall_accessor_reports_the_stored_tail_mean(): void
    {
        $run = $this->makeRun();
        (new MonteCarloService(self::SEED))->runSimulation($run, [$this->makeScenario()->id]);

        $aggregate = $run->fresh()->aggregate_result;

        $this->assertSame(
            round($aggregate->es_95_kobo / 100, 2),
            $run->fresh()->expected_shortfall,
            'The KPI must be the stored ES(95) in naira, not a VaR wearing its label.'
        );
    }

    #[Test]
    public function the_expected_shortfall_accessor_returns_null_when_no_tail_mean_was_stored(): void
    {
        // Every run completed before the es_*_kobo columns existed has no tail
        // mean and cannot acquire one — the loss vector it was computed from is
        // gone. The accessor used to answer 0 for those, which a dashboard
        // renders as ₦0: a bank being told it has no tail loss. Null lets the
        // view say the figure was never computed.
        $run = $this->makeRun();

        SimulationResult::create([
            'simulation_run_id' => $run->id,
            'scenario_id' => null,
            'result_type' => 'aggregate',
            'expected_annual_loss_kobo' => 1_000_000,
            'var_95_kobo' => 2_000_000,
            'var_99_kobo' => 3_000_000,
            'es_95_kobo' => null,
            'es_99_kobo' => null,
        ]);

        $this->assertNull($run->fresh()->expected_shortfall);
    }

    /* ------------------------------------------------------------------ */
    /*  Fixtures */
    /* ------------------------------------------------------------------ */

    private function simulate(int $seed): \Illuminate\Support\Collection
    {
        $run = $this->makeRun();
        $scenario = $this->makeScenario([
            'frequency_lambda' => 2.0,
            'severity_mu' => 10.0,
            'severity_sigma' => 0.5,
        ]);

        (new MonteCarloService($seed))->runSimulation($run, [$scenario->id]);

        return SimulationResult::where('simulation_run_id', $run->id)->orderBy('id')->get();
    }

    private function fingerprint(\Illuminate\Support\Collection $results): array
    {
        return $results->map(fn (SimulationResult $r) => [
            $r->result_type,
            $r->expected_annual_loss_kobo,
            $r->var_90_kobo,
            $r->var_95_kobo,
            $r->var_99_kobo,
            $r->var_99_9_kobo,
            $r->es_95_kobo,
            $r->es_99_kobo,
            $r->std_deviation_kobo,
            $r->percentile_distribution,
        ])->all();
    }

    private function makeRun(): SimulationRun
    {
        static $n = 0;
        $n++;

        return SimulationRun::create([
            'organization_id' => $this->organization->id,
            'simulation_reference' => 'SIM-TEST-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'status' => 'PENDING',
            'iterations' => self::ITERATIONS,
            'initiated_by' => $this->actor->id,
        ]);
    }

    private function makeScenario(array $attributes = []): QuantificationScenario
    {
        static $n = 0;
        $n++;

        return QuantificationScenario::create(array_merge([
            'organization_id' => $this->organization->id,
            'scenario_reference' => 'QS-TEST-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'scenario_type' => 'OPERATIONAL',
            'name' => 'Scenario '.$n,
            'frequency_lambda' => 2.0,
            'severity_mu' => 10.0,
            'severity_sigma' => 0.5,
            'created_by' => $this->actor->id,
        ], $attributes));
    }
}

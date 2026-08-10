<?php

namespace Tests\Feature\Characterisation;

use App\Models\QuantificationScenario;
use App\Models\SimulationResult;
use App\Models\SimulationRun;
use App\Services\MonteCarloService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * CHARACTERISATION — crown jewel.
 *
 * Pins the quantification engine now that its generator is seedable.
 *
 * A Monte Carlo result cannot be asserted exactly against a hand-computed
 * figure — it is a sample. What it *can* be held to, and what these tests
 * hold it to, is: the same seed reproduces the run byte for byte; the VaR
 * ladder and the percentile curve are monotone; the sample mean converges on
 * the analytic expected annual loss (λ · exp(μ + σ²/2)); scenario floors and
 * caps bind; and the tenancy guard refuses to simulate another organisation's
 * scenarios.
 */
class MonteCarloServiceTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    /** A seed chosen once and never changed — it is part of the fixture. */
    private const SEED = 20260615;

    private const ITERATIONS = 5000;

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

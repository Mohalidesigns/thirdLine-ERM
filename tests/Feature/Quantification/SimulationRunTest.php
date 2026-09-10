<?php

namespace Tests\Feature\Quantification;

use App\Jobs\RunSimulationJob;
use App\Models\JobRun;
use App\Models\Organization;
use App\Models\QuantificationScenario;
use App\Models\SimulationResult;
use App\Models\SimulationRun;
use App\Services\Quantification\SimulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Launching, cancelling and charting a Monte Carlo run (migration Phase 5.2).
 *
 * Written alongside the SimulationService extraction, because until it there
 * was no test of this path at all — and it is the path that produces the
 * aggregate VaR that becomes a Pillar 2B stress buffer in an ICAAP
 * submission.
 *
 * What is asserted is the orchestration, not the arithmetic:
 * Characterisation/MonteCarloServiceTest owns the draw.
 */
class SimulationRunTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['quantification.view', 'quantification.create', 'quantification.run_simulation'] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }
    }

    /**
     * The seed is decided when the run is QUEUED, not inside the worker, so a
     * replayed job cannot produce a different capital number from the same
     * request (WP-07).
     */
    #[Test]
    public function launching_a_run_records_its_seed_before_the_job_is_dispatched(): void
    {
        Queue::fake();

        $scenario = $this->scenario();

        $this->actingAs($this->actor)
            ->post(route('risk.quantification.run-simulation'), [
                'name' => 'Q3 operational risk',
                'scenario_ids' => [$scenario->id],
                'iterations' => 10_000,
                'time_horizon' => 1,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $run = SimulationRun::firstOrFail();

        $this->assertSame('queued', $run->status);
        $this->assertNotNull($run->random_seed, 'The seed is recorded from the moment the user presses the button.');
        $this->assertSame([$scenario->id], $run->scenario_ids);
        $this->assertSame('SIM-'.now()->year.'-001', $run->simulation_reference);

        $jobRun = JobRun::withoutGlobalScopes()->findOrFail($run->job_run_id);
        $this->assertSame(10_000, $jobRun->total, 'Iterations x scenarios.');

        Queue::assertPushed(RunSimulationJob::class, fn ($job) => true);
    }

    /** The reference sequence continues rather than restarting. */
    #[Test]
    public function references_continue_the_organisations_sequence(): void
    {
        SimulationRun::create([
            'organization_id' => $this->organization->id,
            'simulation_reference' => 'SIM-'.now()->year.'-007',
            'status' => 'completed',
            'iterations' => 1_000,
            'initiated_by' => $this->actor->id,
        ]);

        $this->assertSame(
            'SIM-'.now()->year.'-008',
            app(SimulationService::class)->nextReference($this->organization->id),
        );
    }

    /**
     * `exists:quantification_scenarios,id` says the row exists. It does not
     * say whose it is.
     */
    #[Test]
    public function a_run_cannot_be_launched_over_another_tenants_scenario(): void
    {
        Queue::fake();

        $otherBank = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $foreign = $this->scenario(['organization_id' => $otherBank->id, 'scenario_reference' => 'SCN-FOREIGN']);

        $this->actingAs($this->actor)
            ->post(route('risk.quantification.run-simulation'), [
                'name' => 'Borrowed scenario',
                'scenario_ids' => [$foreign->id],
                'iterations' => 10_000,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, SimulationRun::count());
        Queue::assertNothingPushed();
    }

    /**
     * Cancellation is a request, not an interrupt: it is recorded on both the
     * run and its job so the worker sees it at its next checkpoint.
     */
    #[Test]
    public function cancelling_a_running_simulation_records_the_request_on_both_records(): void
    {
        $run = $this->queuedRun();

        $this->actingAs($this->actor)
            ->post(route('risk.quantification.cancel-simulation', $run))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($run->fresh()->cancel_requested_at);

        $jobRun = JobRun::withoutGlobalScopes()->findOrFail($run->job_run_id);
        $this->assertNotNull($jobRun->cancel_requested_at);
        $this->assertSame($this->actor->id, $jobRun->cancel_requested_by);
    }

    /** A finished run has nothing to cancel, and says so rather than pretending. */
    #[Test]
    public function a_finished_simulation_cannot_be_cancelled(): void
    {
        $run = $this->queuedRun(['status' => 'completed']);

        $this->actingAs($this->actor)
            ->post(route('risk.quantification.cancel-simulation', $run))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($run->fresh()->cancel_requested_at);
    }

    /** Another tenant's run is a 403, on both the results page and the cancel. */
    #[Test]
    public function another_tenants_run_is_out_of_reach(): void
    {
        $otherBank = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB2',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $foreign = SimulationRun::create([
            'organization_id' => $otherBank->id,
            'simulation_reference' => 'SIM-FOREIGN',
            'status' => 'completed',
            'iterations' => 1_000,
            'initiated_by' => $this->actor->id,
        ]);

        // The OrganizationScope on SimulationRun means route-model binding
        // never resolves it in the first place, so this is a 404 rather than
        // the controller's 403 — the record does not exist for this tenant.
        $this->actingAs($this->actor)->get(route('risk.quantification.show-results', $foreign))->assertNotFound();
        $this->actingAs($this->actor)->post(route('risk.quantification.cancel-simulation', $foreign))->assertNotFound();
    }

    /**
     * The results charts read the percentiles the run STORED. A level the
     * engine did not compute produces no point, not an interpolated one.
     */
    #[Test]
    public function the_result_charts_plot_only_the_percentiles_the_run_stored(): void
    {
        $scenario = $this->scenario(['name' => 'Internal Fraud']);

        $run = SimulationRun::create([
            'organization_id' => $this->organization->id,
            'simulation_reference' => 'SIM-'.now()->year.'-001',
            'status' => 'completed',
            'iterations' => 10_000,
            'scenario_ids' => [$scenario->id],
            'initiated_by' => $this->actor->id,
            'completed_at' => now(),
        ]);

        SimulationResult::create([
            'simulation_run_id' => $run->id,
            'result_type' => 'aggregate',
            'expected_annual_loss_kobo' => 1_000_000_000_000,
            'percentile_distribution' => ['p50' => 500_000_000_000, 'p95' => 2_000_000_000_000],
        ]);

        SimulationResult::create([
            'simulation_run_id' => $run->id,
            'scenario_id' => $scenario->id,
            'result_type' => 'scenario',
            'expected_annual_loss_kobo' => 1_000_000_000_000,
        ]);

        $data = $this->actingAs($this->actor)
            ->get(route('risk.quantification.show-results', $run))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Quantification/Results/Show'))
            ->inertiaProps();

        $this->assertSame(['50%', '95%'], $data['histogramData']['labels'], 'Only the two levels on file.');
        $this->assertEqualsWithDelta([5_000_000_000.0, 20_000_000_000.0], $data['histogramData']['values'], 0.001, 'Kobo in, naira out.');

        $this->assertEqualsWithDelta([0.5, 0.95], $data['cdfData']['values'], 0.001);
        $this->assertSame('₦5,000,000,000', $data['cdfData']['labels'][0]);

        $this->assertSame(['Internal Fraud'], $data['contribChartData']['labels']);
        $this->assertEqualsWithDelta([100.0], $data['contribChartData']['values'], 0.001);
    }

    /**
     * The observability columns WP-06 added are writable.
     *
     * They were in the migration and read by the job, but never in $fillable,
     * so MonteCarloService's progress writes were dropped on the floor and the
     * results page's bar sat at zero for the whole run.
     */
    #[Test]
    public function a_runs_progress_is_actually_stored(): void
    {
        $run = $this->queuedRun();

        $run->update(['status' => 'running', 'progress' => 42, 'completed_iterations' => 4_200]);

        $fresh = $run->fresh();

        $this->assertSame(42, $fresh->progress);
        $this->assertSame(4_200, (int) $fresh->completed_iterations);
    }

    /* ------------------------------------------------------------------ */

    private function scenario(array $attributes = []): QuantificationScenario
    {
        return QuantificationScenario::create(array_merge([
            'organization_id' => $this->organization->id,
            'scenario_reference' => 'SCN-'.now()->year.'-'.str_pad((string) (QuantificationScenario::count() + 1), 3, '0', STR_PAD_LEFT),
            'scenario_type' => QuantificationScenario::DEFAULT_TYPE,
            'name' => 'Scenario',
            'status' => 'active',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function queuedRun(array $attributes = []): SimulationRun
    {
        $jobRun = JobRun::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'job_class' => RunSimulationJob::class,
            'label' => 'Simulation',
            'status' => JobRun::STATUS_QUEUED,
            'created_by' => $this->actor->id,
        ]);

        return SimulationRun::create(array_merge([
            'organization_id' => $this->organization->id,
            'simulation_reference' => 'SIM-'.now()->year.'-001',
            'status' => 'queued',
            'iterations' => 10_000,
            'initiated_by' => $this->actor->id,
            'job_run_id' => $jobRun->id,
        ], $attributes));
    }
}

<?php

namespace App\Services\Quantification;

use App\Jobs\RunSimulationJob;
use App\Models\JobRun;
use App\Models\QuantificationScenario;
use App\Models\SimulationRun;
use App\Models\User;
use App\Services\MonteCarloService;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Launching, cancelling and charting a Monte Carlo run (migration Phase 5.2).
 *
 * Orchestration only: the arithmetic is MonteCarloService's — the one file in
 * this codebase allowed to hold an RNG — and the work itself happens in
 * RunSimulationJob. What lives here is the reference sequence, the tenant
 * check on the selected scenarios, the seed decision, the job hand-off, the
 * cancellation request, and the three chart series the results page draws.
 */
class SimulationService
{
    /**
     * The confidence levels the simulate form offers when the run does not
     * name its own. The engine stores 90 / 95 / 99 / 99.9 columns; a request
     * for 99.5 is answered from the 99.9 figure, which is why IcaapService
     * reports off the columns rather than off this list.
     *
     * @var list<float|int>
     */
    private const DEFAULT_CONFIDENCE_LEVELS = [95, 99, 99.5];

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'scenario_ids' => 'required|array|min:1',
            'scenario_ids.*' => 'exists:quantification_scenarios,id',
            'iterations' => 'required|integer|min:1000|max:1000000',
            'time_horizon' => 'nullable|integer|min:1|max:10',
            'confidence_levels' => 'nullable|array',
            'confidence_levels.*' => 'numeric',
        ];
    }

    /**
     * Every selected scenario belongs to this organisation.
     *
     * `exists:quantification_scenarios,id` on the request says the row exists;
     * it does not say whose it is.
     *
     * @param  list<int|string>  $scenarioIds
     */
    public function scenariosBelongToOrganization(array $scenarioIds, ?int $organizationId = null): bool
    {
        $orgId = $organizationId ?? TenantContext::organizationId();

        return QuantificationScenario::where('organization_id', $orgId)
            ->whereIn('id', $scenarioIds)
            ->count() === count($scenarioIds);
    }

    /**
     * The next reference in this organisation's SIM-YYYY-NNN sequence.
     */
    public function nextReference(?int $organizationId = null): string
    {
        $orgId = $organizationId ?? TenantContext::organizationId();
        $year = now()->year;

        $last = SimulationRun::where('organization_id', $orgId)
            ->where('simulation_reference', 'like', "SIM-{$year}-%")
            ->orderByDesc('simulation_reference')
            ->first();

        $nextNumber = $last ? ((int) substr($last->simulation_reference, -3)) + 1 : 1;

        return sprintf('SIM-%d-%03d', $year, $nextNumber);
    }

    /**
     * Queue a run and hand it to the worker.
     *
     * WP-07. This used to run 10,000 iterations x N scenarios inside the
     * request. On a real scenario set the web server killed it partway,
     * leaving status stuck on 'running' with no results and nothing on
     * screen to say why.
     *
     * The seed is decided when the run is QUEUED rather than inside the
     * worker, so the figure is recorded as re-derivable from the moment the
     * user presses the button, and a replayed job cannot produce a
     * different capital number from the same request. The draw itself lives
     * in MonteCarloService — the one file allowed to hold an RNG.
     *
     * @param  array<string, mixed>  $validated
     */
    public function launch(array $validated, ?User $user = null, ?int $organizationId = null): SimulationRun
    {
        $orgId = $organizationId ?? TenantContext::organizationId();
        $reference = $this->nextReference($orgId);

        $simulation = SimulationRun::create([
            'organization_id' => $orgId,
            'simulation_reference' => $reference,
            'scenario_ids' => $validated['scenario_ids'],
            'iterations' => $validated['iterations'],
            'horizon_years' => $validated['time_horizon'] ?? 1,
            'confidence_levels' => $validated['confidence_levels'] ?? self::DEFAULT_CONFIDENCE_LEVELS,
            'correlation_method' => 'independent',
            'status' => 'queued',
            'initiated_by' => $user !== null ? $user->id : auth()->id(),
        ]);

        $seed = MonteCarloService::drawSeed();
        $simulation->update(['random_seed' => $seed]);

        $jobRun = RunSimulationJob::track(
            label: "Simulation {$reference}",
            subject: $simulation,
            organizationId: $orgId,
            creator: $user,
            total: $validated['iterations'] * count($validated['scenario_ids']),
        );

        $simulation->update(['job_run_id' => $jobRun->id]);

        RunSimulationJob::dispatch(
            $simulation->id,
            $validated['scenario_ids'],
            $seed,
            $jobRun->id,
        );

        return $simulation;
    }

    /**
     * A run as the results list shows it.
     *
     * TWO THINGS THE BLADE TABLE GOT WRONG.
     *
     * 1. IT HEADED A COLUMN "VaR (99.5%)". `SimulationRun::getVar995Attribute()`
     *    reads `var_99_9_kobo`, because the engine stores no 99.5 column —
     *    MonteCarloService writes 90 / 95 / 99 / 99.9. So the figure under that
     *    heading was the 99.9 loss, which is LARGER than the 99.5 loss it
     *    claimed to be, on a page read as the output of a capital model.
     *    IcaapService already refuses this — it reports stress impact off the
     *    columns that exist rather than off the levels a run requested — and
     *    this row now states 99.9 for the same reason.
     *
     * 2. IT COST THREE QUERIES A ROW. `var_95`, `var_995` and `expected_loss`
     *    each call `$this->aggregate_result`, which runs its own query; at 25
     *    rows a page that is 75. The aggregate is read once here.
     *
     * A run with no aggregate result yet — queued, running, failed or cancelled
     * — reports nulls rather than zeroes. A zero VaR is a claim about the
     * portfolio; "not computed" is the truth.
     *
     * @return array<string, mixed>
     */
    public function toListRow(SimulationRun $run): array
    {
        $aggregate = $run->aggregate_result;

        $naira = fn (int|float|null $kobo) => $kobo === null ? null : round((float) $kobo / 100, 2);

        return [
            'id' => $run->id,
            'simulation_reference' => $run->simulation_reference,
            'status' => $run->status,
            'progress' => (int) $run->progress,
            'iterations' => $run->iterations,
            'scenario_count' => is_array($run->scenario_ids) ? count($run->scenario_ids) : 0,
            'random_seed' => $run->random_seed,
            'job_run_id' => $run->job_run_id,
            'cancel_requested_at' => $run->cancel_requested_at,
            'error_message' => $run->error_message,
            'created_at' => $run->created_at,
            'completed_at' => $run->completed_at,
            'expected_loss' => $naira($aggregate?->expected_annual_loss_kobo),
            'var_95' => $naira($aggregate?->var_95_kobo),
            'var_99' => $naira($aggregate?->var_99_kobo),
            'var_99_9' => $naira($aggregate?->var_99_9_kobo),
        ];
    }

    /**
     * Ask a running simulation to stop.
     *
     * A request, not an interrupt: a worker cannot be killed from here, only
     * told. The job checks between iterations and stops at a point where
     * nothing is half-written — a cancelled run has NO results, because a
     * partial loss distribution is not a smaller answer, it is a wrong one.
     *
     * Returns false when the run has already finished and there is nothing to
     * cancel.
     */
    public function requestCancellation(SimulationRun $simulation, User $user): bool
    {
        if (! in_array($simulation->status, ['queued', 'running'], true)) {
            return false;
        }

        $simulation->update(['cancel_requested_at' => now()]);

        JobRun::withoutGlobalScopes()
            ->whereKey($simulation->job_run_id)
            ->update([
                'cancel_requested_at' => now(),
                'cancel_requested_by' => $user->id,
            ]);

        return true;
    }

    /**
     * The three series the results page draws: the loss histogram, each
     * scenario's share, and the cumulative distribution.
     *
     * Every one of them reads the percentiles the run STORED — a level the
     * engine did not compute produces no point rather than an interpolated
     * one.
     *
     * @return array{histogramData: array{labels: list<string>, values: list<float>}, contribChartData: array{labels: list<string>, values: list<float>}, cdfData: array{labels: list<string>, values: list<float>}}
     */
    public function resultCharts(SimulationRun $simulation): array
    {
        $aggregate = $simulation->aggregate_result;
        $distribution = ($aggregate && is_array($aggregate->percentile_distribution))
            ? $aggregate->percentile_distribution
            : [];

        return [
            'histogramData' => $this->histogram($distribution),
            'contribChartData' => $this->contributionSeries($simulation),
            'cdfData' => $this->cumulativeDistribution($distribution),
        ];
    }

    /**
     * @param  array<string, mixed>  $distribution
     * @return array{labels: list<string>, values: list<float>}
     */
    private function histogram(array $distribution): array
    {
        $labels = ['p5' => '5%', 'p10' => '10%', 'p25' => '25%', 'p50' => '50%', 'p75' => '75%', 'p90' => '90%', 'p95' => '95%', 'p99' => '99%'];

        $series = ['labels' => [], 'values' => []];

        foreach ($labels as $key => $label) {
            if (isset($distribution[$key])) {
                $series['labels'][] = $label;
                $series['values'][] = round($distribution[$key] / 100, 2);
            }
        }

        return $series;
    }

    /**
     * @return array{labels: list<string>, values: list<float>}
     */
    private function contributionSeries(SimulationRun $simulation): array
    {
        $series = ['labels' => [], 'values' => []];

        $contributions = $simulation->scenario_contributions;

        if (! $contributions || $contributions->isEmpty()) {
            return $series;
        }

        foreach ($contributions as $contribution) {
            $series['labels'][] = $contribution->scenario_name;
            $series['values'][] = $contribution->contribution_pct;
        }

        return $series;
    }

    /**
     * @param  array<string, mixed>  $distribution
     * @return array{labels: list<string>, values: list<float>}
     */
    private function cumulativeDistribution(array $distribution): array
    {
        $probabilities = ['p5' => 0.05, 'p10' => 0.10, 'p25' => 0.25, 'p50' => 0.50, 'p75' => 0.75, 'p90' => 0.90, 'p95' => 0.95, 'p99' => 0.99, 'p99.5' => 0.995, 'p99.9' => 0.999];

        $series = ['labels' => [], 'values' => []];

        foreach ($probabilities as $key => $probability) {
            if (isset($distribution[$key])) {
                $series['labels'][] = '₦'.number_format(round($distribution[$key] / 100, 2));
                $series['values'][] = $probability;
            }
        }

        return $series;
    }
}

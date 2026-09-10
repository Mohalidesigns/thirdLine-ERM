<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksJobProgress;
use App\Models\SimulationRun;
use App\Services\MonteCarloService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * WP-07 TASK 1 — the one the work package calls out.
 *
 * MonteCarloService ran 10,000 iterations x N scenarios inside the HTTP
 * request. On a real scenario set that is a request the web server kills
 * halfway, leaving simulation_runs.status stuck on 'running' forever with no
 * results and nothing to say why — and the user, reasonably, presses the button
 * again, which starts a second one.
 *
 * NOT RETRIED. tries = 1 on purpose. A Monte Carlo run is expensive and
 * seeded: an automatic retry would either burn another few minutes of CPU to
 * reproduce the same failure, or — worse, if the seed were redrawn — quietly
 * produce a DIFFERENT capital number from the same request. A failure here is
 * for a person to look at.
 */
class RunSimulationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksJobProgress;

    public int $tries = 1;

    public int $timeout = 1800;

    /** @param list<int> $scenarioIds */
    public function __construct(
        public int $simulationRunId,
        public array $scenarioIds,
        public ?int $seed = null,
        public ?int $jobRunId = null,
    ) {
        $this->onQueue('simulations');
    }

    public function handle(): void
    {
        $run = SimulationRun::withoutGlobalScopes()->find($this->simulationRunId);

        if ($run === null) {
            $this->failRun('The simulation run no longer exists.');

            return;
        }

        if ($run->status === 'cancelled' || $run->cancel_requested_at !== null) {
            $this->cancelRun('Cancelled before the worker picked it up.');
            $run->update(['status' => 'cancelled', 'completed_at' => now()]);

            return;
        }

        $this->startRun(total: ($run->iterations ?? 10000) * max(1, count($this->scenarioIds)));

        $this->runTenanted($run->organization_id, function () use ($run) {
            // The seed travels with the job rather than being drawn inside it,
            // so a run dispatched from a request and the same run replayed from
            // a queue retry produce the same figure.
            $service = new MonteCarloService($this->seed);

            $result = $service->runSimulation(
                $run,
                $this->scenarioIds,
                onProgress: function (int $done, int $total) use ($run) {
                    $this->reportProgress($done, $total);

                    // simulation_runs carries its own progress because the
                    // quantification screens read that table, not job_runs.
                    $run->forceFill([
                        'progress' => (int) min(99, floor(($done / max(1, $total)) * 100)),
                        'completed_iterations' => $done,
                    ])->saveQuietly();
                },
                shouldCancel: fn () => $this->shouldCancel() || $this->cancelRequestedOnRun($run),
            );

            if ($result->status === 'cancelled') {
                $this->cancelRun('Simulation cancelled; no results were written.');

                return;
            }

            $this->completeRun(
                [
                    'simulation_run_id' => $run->id,
                    'random_seed' => $service->seed(),
                    'runtime_seconds' => $result->runtime_seconds,
                ],
                'Simulation complete.',
            );
        });
    }

    public function failed(Throwable $e): void
    {
        $this->failRun($e);

        SimulationRun::withoutGlobalScopes()
            ->whereKey($this->simulationRunId)
            ->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
    }

    /**
     * A cancel asked for on the simulation row itself, rather than on the job
     * run — the quantification screen's own cancel button writes there.
     */
    private function cancelRequestedOnRun(SimulationRun $run): bool
    {
        return SimulationRun::withoutGlobalScopes()
            ->whereKey($run->id)
            ->whereNotNull('cancel_requested_at')
            ->exists();
    }
}

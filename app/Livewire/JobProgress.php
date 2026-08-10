<?php

namespace App\Livewire;

use App\Models\JobRun;
use App\Support\Tenancy\TenantContext;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * WP-07 TASK 1 — the progress bar for a queued job.
 *
 * Polls rather than pushes: this platform runs on-premise in institutions where
 * a WebSocket through the corporate proxy is a project of its own, and a job
 * that takes ninety seconds does not need sub-second latency. The poll STOPS
 * once the job finishes, so a dashboard left open overnight is not still
 * hitting the database every two seconds in the morning.
 *
 * Drop it anywhere a job was started:
 *   <livewire:job-progress :job-run-id="$run->job_run_id" />
 */
class JobProgress extends Component
{
    public ?int $jobRunId = null;

    public bool $showCancel = true;

    /** Emitted to the page when the job ends, so a parent can refresh itself. */
    public bool $finished = false;

    public function mount(?int $jobRunId = null, bool $showCancel = true): void
    {
        $this->jobRunId = $jobRunId;
        $this->showCancel = $showCancel;
    }

    public function render()
    {
        $run = $this->run();

        // Once it is over there is nothing left to poll for.
        if ($run !== null && $run->isFinished() && ! $this->finished) {
            $this->finished = true;
            $this->dispatch('job-finished', jobRunId: $run->id, status: $run->status);
        }

        return view('livewire.job-progress', ['run' => $run]);
    }

    #[On('job-progress-refresh')]
    public function refresh(): void
    {
        // The poll re-renders; this exists so a parent can force one.
    }

    public function cancel(): void
    {
        $run = $this->run();

        if ($run === null || $run->isFinished() || $run->isCancelRequested()) {
            return;
        }

        $run->forceFill([
            'cancel_requested_at' => now(),
            'cancel_requested_by' => auth()->id(),
        ])->save();

        // The simulation screens read simulation_runs, so the request has to
        // land there too — the worker checks both.
        if ($run->subject_type === 'simulation_run') {
            \App\Models\SimulationRun::withoutGlobalScopes()
                ->whereKey($run->subject_id)
                ->update(['cancel_requested_at' => now()]);
        }
    }

    private function run(): ?JobRun
    {
        if ($this->jobRunId === null) {
            return null;
        }

        return JobRun::query()
            ->where('organization_id', TenantContext::organizationIdOrNull())
            ->find($this->jobRunId);
    }
}

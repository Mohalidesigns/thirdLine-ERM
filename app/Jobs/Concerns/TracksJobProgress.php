<?php

namespace App\Jobs\Concerns;

use App\Models\JobRun;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gives a job a progress record, a cancel check and an honest ending.
 *
 * THREE THINGS IT EXISTS TO PREVENT
 *
 * 1. A button that appears to do nothing. Dispatching to a worker with no
 *    progress row is strictly worse for the user than the blocking request it
 *    replaced, because at least the request had a spinner.
 *
 * 2. A failure nobody sees. A queued job that throws disappears into
 *    failed_jobs, which no user has ever looked at. fail() writes the reason
 *    onto the row the user is actually watching.
 *
 * 3. A worker with no tenant. Queue workers have no session, so the global
 *    organization scope is inert — every read would silently span tenants.
 *    runTenanted() binds the tenant explicitly for the body of the job.
 */
trait TracksJobProgress
{
    public ?int $jobRunId = null;

    private ?JobRun $jobRun = null;

    /** How often progress is written, as a fraction of total. */
    private float $progressGranularity = 0.01;

    private int $lastReportedProgress = -1;

    /**
     * Create the progress row BEFORE dispatch, so the user sees a queued job
     * the moment they press the button rather than whenever a worker picks it
     * up — which on a busy queue can be minutes.
     */
    public static function track(
        string $label,
        ?Model $subject = null,
        ?int $organizationId = null,
        ?User $creator = null,
        ?int $total = null,
    ): JobRun {
        return JobRun::withoutGlobalScopes()->create([
            'organization_id' => $organizationId
                ?? $subject?->getAttribute('organization_id')
                ?? TenantContext::organizationIdOrNull(),
            'job_class' => static::class,
            'label' => $label,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'status' => JobRun::STATUS_QUEUED,
            'total' => $total,
            'created_by' => $creator?->id ?? auth()->id(),
        ]);
    }

    public function jobRun(): ?JobRun
    {
        if ($this->jobRunId === null) {
            return null;
        }

        return $this->jobRun ??= JobRun::withoutGlobalScopes()->find($this->jobRunId);
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle */
    /* ------------------------------------------------------------------ */

    protected function startRun(?int $total = null): void
    {
        $this->jobRun()?->forceFill(array_filter([
            'status' => JobRun::STATUS_RUNNING,
            'started_at' => now(),
            'queue' => $this->queue ?? 'default',
            'total' => $total,
            'attempts' => method_exists($this, 'attempts') ? $this->attempts() : 1,
        ], fn ($value) => $value !== null))->save();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function completeRun(array $result = [], ?string $message = null): void
    {
        $this->jobRun()?->forceFill([
            'status' => JobRun::STATUS_COMPLETED,
            'progress' => 100,
            'finished_at' => now(),
            'message' => $message,
            'result' => $result ?: null,
        ])->save();
    }

    protected function failRun(Throwable|string $reason): void
    {
        $message = $reason instanceof Throwable ? $reason->getMessage() : $reason;

        $this->jobRun()?->forceFill([
            'status' => JobRun::STATUS_FAILED,
            'finished_at' => now(),
            'error' => $message,
        ])->save();
    }

    protected function cancelRun(?string $message = null): void
    {
        $this->jobRun()?->forceFill([
            'status' => JobRun::STATUS_CANCELLED,
            'finished_at' => now(),
            'message' => $message ?? 'Cancelled at the user\'s request.',
        ])->save();
    }

    /* ------------------------------------------------------------------ */
    /*  Progress and cancellation */
    /* ------------------------------------------------------------------ */

    /**
     * Report progress, throttled.
     *
     * A 10,000-iteration simulation writing a row per iteration would spend
     * more time on progress than on arithmetic, so an update happens only when
     * the percentage actually moves.
     */
    protected function reportProgress(int $processed, ?int $total = null, ?string $message = null): void
    {
        $run = $this->jobRun();

        if ($run === null) {
            return;
        }

        $total ??= $run->total;
        $percent = $total > 0 ? (int) min(99, floor(($processed / $total) * 100)) : 0;

        if ($percent === $this->lastReportedProgress && $message === null) {
            return;
        }

        $this->lastReportedProgress = $percent;

        $run->forceFill(array_filter([
            'progress' => $percent,
            'processed' => $processed,
            'total' => $total,
            'message' => $message,
        ], fn ($value) => $value !== null))->save();
    }

    /**
     * Has somebody asked for this to stop?
     *
     * Re-read from the database on purpose: the request arrives after the job
     * started, so a cached model would never see it.
     */
    protected function shouldCancel(): bool
    {
        if ($this->jobRunId === null) {
            return false;
        }

        return JobRun::withoutGlobalScopes()
            ->whereKey($this->jobRunId)
            ->whereNotNull('cancel_requested_at')
            ->exists();
    }

    /* ------------------------------------------------------------------ */
    /*  Tenancy */
    /* ------------------------------------------------------------------ */

    /**
     * Run the body of the job as a named tenant, and record how it ended.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn|null
     */
    protected function runTenanted(?int $organizationId, callable $work)
    {
        try {
            return TenantContext::actingAs($organizationId, $work);
        } catch (Throwable $e) {
            $this->failRun($e);

            Log::error('Queued job failed.', [
                'job' => static::class,
                'job_run_id' => $this->jobRunId,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

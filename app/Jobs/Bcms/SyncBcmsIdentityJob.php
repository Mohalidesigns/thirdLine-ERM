<?php

namespace App\Jobs\Bcms;

use App\Enums\Bcms\SyncRunStatus;
use App\Enums\Bcms\SyncTrigger;
use App\Exceptions\Bcms\DirectorySyncException;
use App\Models\Bcms\IdentityConnector;
use App\Models\Bcms\IdentitySyncRun;
use App\Services\Bcms\Identity\DirectorySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use ThirdLine\Platform\Tenancy\TenantContext;
use Throwable;

/**
 * One tenant's directory read, full or delta — ADR 0018 §4 (reliability
 * section), work order §7.
 *
 * `tries = 1`, LIKE EVERY OTHER `bcms-sync` JOB (Phase 0's queue design). A
 * half-run sync retried from the top writes the same contacts twice —
 * staging is idempotent per run via the unique
 * `(sync_run_id, directory_object_id, kind)` index, but a SECOND run is a
 * second set of rows, and a worker that died partway is exactly the case
 * `bcms:watchdog` and the connector screen's "last run" surface, not a case
 * this job silently repairs by trying again.
 *
 * TENANCY IS SET EXPLICITLY, RESTORED RATHER THAN CLEARED — the same reason
 * `DispatchAlertChunkJob` does both: a real worker starts with no tenant
 * resolved, so `actingAs()` and a bare `clear()` are identical there; they
 * differ only when this job runs inline (a test, a `sync` queue driver),
 * where `clear()` would wipe the CALLER's tenant the moment this job returns.
 *
 * THE QUEUE IS A PROPERTY OF THE JOB, NOT OF WHOEVER DISPATCHED IT. Set in
 * the constructor from `config('bcms.queues.sync')` so that a `retry`, a
 * tinker session, a future controller or a `Bus::chain()` cannot land a
 * 3,600-second directory read on `default` behind interactive work. The
 * dispatcher keeps its own `->onQueue()` for readability; the two read the
 * same config key and cannot diverge.
 *
 * `ShouldBeUnique` KEYED ON THE CONNECTOR, NOT ON connector+delta. That is
 * deliberate: the nightly full and the 15-minute delta are the SAME lock, so
 * a delta tick that lands while the nightly reconciliation is still running is
 * dropped at dispatch rather than queued behind it. `uniqueFor` equals the
 * job timeout, so a worker killed with SIGKILL (which never reaches `failed()`)
 * frees the key within one run's budget rather than wedging the connector
 * until somebody flushes the cache. This is the dispatch-time half of the
 * guard; the run-time half is `DirectorySyncService`'s own connector lock
 * (`exclusively()`), which is what actually serialises two workers that both
 * got past this one — the admin's "Sync now" is this same job now too
 * (`IdentityController::sync()`), so there is no other path left to cover.
 *
 * NO `$backoff`, BECAUSE THERE IS NO SECOND ATTEMPT. `tries = 1` makes backoff
 * dead configuration, and writing one would advertise a retry that cannot
 * happen. What replaces it: `failed()` closes the run row out with a bounded
 * error class, so a crashed sync is a visibly `failed` run rather than a row
 * stuck on `running` for ever, and `bcms:watchdog` catches the SIGKILL case
 * that never reaches `failed()` at all.
 */
class SyncBcmsIdentityJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The one place the sync's time budget is written down. `bcms:watchdog`
     * derives its "stuck on running" threshold from this constant rather than
     * from a second copy of the number, so raising the budget cannot silently
     * leave the watchdog reporting healthy runs as stuck.
     */
    public const TIMEOUT_SECONDS = 3600;

    public int $tries = 1;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $uniqueFor = self::TIMEOUT_SECONDS;

    public function __construct(
        public int $connectorId,
        public int $organizationId,
        public bool $delta,
        /**
         * Who clicked "Sync now", or null for the scheduler. Recorded as
         * `triggered_by` on the run row and what decides whether the run is
         * labelled `manual` rather than `scheduled_full`/`scheduled_delta` —
         * the same job, the same queue, the same `ShouldBeUnique` key either
         * way, so an admin's click and a cron tick can never race each other
         * into two concurrent reads of the same connector.
         */
        public ?int $triggeredByUserId = null,
    ) {
        $this->onQueue((string) config('bcms.queues.sync'));
    }

    public function uniqueId(): string
    {
        return 'bcms-identity-sync:'.$this->organizationId.':'.$this->connectorId;
    }

    public function handle(DirectorySyncService $service): void
    {
        TenantContext::actingAs($this->organizationId, function () use ($service): void {
            $connector = IdentityConnector::query()->find($this->connectorId);

            // The connector may have been deactivated between the sweep that
            // queued this and the worker picking it up — a job in flight is
            // not consent to read a bank's directory after an admin has
            // switched the connector off.
            if ($connector === null || ! $connector->is_active) {
                return;
            }

            $trigger = $this->triggeredByUserId !== null
                ? SyncTrigger::Manual
                : ($this->delta ? SyncTrigger::ScheduledDelta : SyncTrigger::ScheduledFull);

            try {
                if ($this->delta) {
                    $service->runDelta($connector, $trigger, $this->triggeredByUserId);
                } else {
                    $service->runFull($connector, $trigger, $this->triggeredByUserId);
                }
            } catch (DirectorySyncException $e) {
                // The only exception the service lets out is contention on
                // the connector lock, and a skipped tick is not a failure:
                // failing the job would put a row in `failed_jobs` every
                // fifteen minutes for the length of a nightly run. It is
                // logged so that a connector whose deltas are ALWAYS skipped
                // is visible, and the run the lock holder is executing is the
                // one the screen and the watchdog read.
                Log::info('BCMS identity sync skipped: another run holds the connector lock', [
                    'organization_id' => $this->organizationId,
                    'connector_id' => $this->connectorId,
                    'delta' => $this->delta,
                    'error_class' => $e->errorClass,
                ]);
            }
        });
    }

    /**
     * Close out the write-ahead row a dead worker left behind.
     *
     * The run row is created `running` BEFORE the first Graph call (standing
     * rule 8), which is what makes a crash visible — but only if something
     * eventually says how it ended. Without this, a job that died on an
     * unexpected `Throwable` or hit its 3,600-second timeout leaves a row on
     * `running` that `ConnectorHealth` reports as neither a success nor a
     * failure, and the connector screen shows a sync that never came back.
     *
     * BOUNDED ERROR CLASS, NEVER PROVIDER TEXT (ADR 0018 §2.3). `class_basename`
     * of the throwable is a PHP class name — a closed vocabulary — and
     * `getMessage()` is not read here, not logged here, and not stored.
     */
    public function failed(?Throwable $exception): void
    {
        TenantContext::actingAs($this->organizationId, function () use ($exception): void {
            IdentitySyncRun::query()
                ->where('identity_connector_id', $this->connectorId)
                ->where('status', SyncRunStatus::Running->value)
                ->update([
                    'status' => SyncRunStatus::Failed->value,
                    'finished_at' => now(),
                    'error_class' => 'job_failed_'.mb_substr(class_basename($exception ?? self::class), 0, 160),
                    'error_code' => null,
                    'updated_at' => now(),
                ]);
        });

        Log::error('BCMS identity sync job failed', [
            'organization_id' => $this->organizationId,
            'connector_id' => $this->connectorId,
            'delta' => $this->delta,
            'exception_class' => $exception === null ? null : $exception::class,
        ]);
    }
}

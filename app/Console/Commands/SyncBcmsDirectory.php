<?php

namespace App\Console\Commands;

use App\Enums\Bcms\SyncRunStatus;
use App\Jobs\Bcms\SyncBcmsIdentityJob;
use App\Models\Bcms\IdentityConnector;
use App\Models\Bcms\IdentitySyncRun;
use App\Models\Organization;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The scheduler's entry point into Phase 2C — ADR 0018 §4, work order §7.
 *
 * A PER-TENANT LOOP THAT SKIPS A TENANT WITH NO ACTIVE CONNECTOR, exactly
 * like every other `bcms:*` scheduled command. A connector is created
 * disabled (ADR 0018 §2.2 point 5), so "no active connector" covers both "no
 * connector configured" and "configured but not yet tested" — neither should
 * queue a job.
 *
 * `--delta` ALSO SKIPS A CONNECTOR NOT ON `nightly_plus_delta` — a tenant on
 * plain `nightly` gets no 15-minute job at all, not a job that runs and finds
 * nothing to do.
 *
 * OFF WHEN THE MODULE IS OFF. The same rule `BcmsWatchdog` and every other
 * scheduled BCMS command follows: a module that is off must be off in the
 * scheduler too, not only at the HTTP boundary.
 *
 * `sync_schedule = manual` IS SKIPPED BY BOTH ARMS. A tenant that chose
 * manual has said a human decides when their directory is read; sweeping them
 * nightly anyway would also mean `ConnectorHealth::isStale()` — which returns
 * false for `manual` on purpose — never reports the resulting failures. The
 * connector screen's "Sync now" is their path.
 *
 * THE DELTA YIELDS TO A RUN ALREADY IN FLIGHT. The nightly full and the
 * quarter-hourly delta share one lock (`SyncBcmsIdentityJob::uniqueId()` keys
 * on the connector, not on the trigger), so a delta queued while the nightly
 * reconciliation is running would be dropped anyway — but dropping it HERE
 * keeps `failed_jobs`, the Horizon dashboard and this command's own output
 * honest about what happened, instead of queueing ninety-six jobs a night
 * that silently evaporate. The nightly full is never the one that yields: it
 * is what maintains the manager hierarchy (ADR 0018 §3.1), and a delta run
 * that keeps winning would leave the tree quietly unmaintained while every
 * health indicator stayed green.
 */
class SyncBcmsDirectory extends Command
{
    protected $signature = 'bcms:sync-directory {--delta : Run the 15-minute delta instead of the nightly full reconciliation}';

    protected $description = 'Queue a directory sync for every tenant with an active BCMS identity connector.';

    public function handle(): int
    {
        if (! config('features.bcms')) {
            $this->line('The BCMS module is switched off; nothing to sync.');

            return self::SUCCESS;
        }

        $delta = (bool) $this->option('delta');
        $queued = 0;
        $skippedInFlight = 0;

        // `cursor()`, not `get()` — every tenant on the estate, not a page of
        // them, and the per-organisation work below (a query per connector,
        // per in-flight check) never needs more than the one row in memory
        // at a time (gate 2 advisory 10).
        foreach (Organization::query()->cursor() as $organization) {
            TenantContext::set($organization->id);

            try {
                $connector = IdentityConnector::query()
                    ->where('organization_id', $organization->id)
                    ->where('is_active', true)
                    ->first();

                if ($connector === null) {
                    continue;
                }

                // A manual connector is read when a human asks and never on a
                // sweep — both arms, not only the delta.
                if ($connector->sync_schedule === 'manual') {
                    continue;
                }

                if ($delta && $connector->sync_schedule !== 'nightly_plus_delta') {
                    continue;
                }

                if ($delta && $this->hasRunInFlight($connector->getKey())) {
                    $skippedInFlight++;

                    continue;
                }

                // The queue is set in the job's constructor as well — this
                // repeats it so a reader of the scheduler can see where a
                // 3,600-second directory read lands without opening the job.
                // Both read `config('bcms.queues.sync')`, so they cannot
                // disagree.
                SyncBcmsIdentityJob::dispatch((int) $connector->getKey(), (int) $organization->id, $delta)
                    ->onQueue((string) config('bcms.queues.sync'));

                $queued++;
            } finally {
                TenantContext::clear();
            }
        }

        $this->info(sprintf(
            'Queued %d BCMS directory %s sync(s)%s.',
            $queued,
            $delta ? 'delta' : 'full',
            $skippedInFlight > 0 ? sprintf('; skipped %d with a run already in flight', $skippedInFlight) : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Is a run for this connector still open?
     *
     * `running` here means "written ahead and not yet closed out", which
     * covers both a run genuinely in progress and one whose worker was killed
     * before it could finish. Queueing behind either is wrong: the first would
     * be dropped by the lock, and the second needs `bcms:watchdog` to raise
     * it, not a fresh delta every fifteen minutes stacking up against a row
     * nothing is going to close.
     */
    private function hasRunInFlight(int $connectorId): bool
    {
        return IdentitySyncRun::query()
            ->where('identity_connector_id', $connectorId)
            ->where('status', SyncRunStatus::Running->value)
            ->exists();
    }
}

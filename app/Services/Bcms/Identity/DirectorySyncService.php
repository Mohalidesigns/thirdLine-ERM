<?php

namespace App\Services\Bcms\Identity;

use App\Contracts\Bcms\DirectoryClient;
use App\Contracts\Bcms\ReportsDirectoryFetchStats;
use App\Enums\Bcms\SyncChangeDecision;
use App\Enums\Bcms\SyncChangeKind;
use App\Enums\Bcms\SyncRunStatus;
use App\Enums\Bcms\SyncTrigger;
use App\Exceptions\Bcms\DirectorySyncException;
use App\Models\Bcms\Contact;
use App\Models\Bcms\IdentityConnector;
use App\Models\Bcms\IdentitySyncChange;
use App\Models\Bcms\IdentitySyncRun;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates one directory read into staged rows — ADR 0018 §3.3.
 *
 * WRITE-AHEAD (standing rule 8, Orchestration §8): the run row is created
 * with `status = running` BEFORE the first Graph call, not after. A worker
 * that dies mid-run leaves a visibly unfinished row rather than a silent gap
 * — `BcmsWatchdog` and the connector screen both read this.
 *
 * EVERY RUN WRITES ONLY TO `bcms_identity_sync_changes` (ADR 0018 §3.3).
 * Application is a separate, audited step — `apply()` here only ever calls
 * `ChangeApplier` for a row this service has itself just decided is
 * `auto_applied`; every `pending` row waits for a human via
 * `IdentitySyncController`.
 *
 * ONE RUN PER CONNECTOR AT A TIME, AND THE LOCK IS HERE RATHER THAN ONLY ON
 * THE JOB'S DISPATCH-TIME `ShouldBeUnique` KEY. Three callers can start a
 * run — the nightly full, the 15-minute delta and the admin's "Sync now" —
 * and all three are `SyncBcmsIdentityJob` today, sharing one `uniqueId()`.
 * This lock is the defence a future direct caller (a tinker session, a
 * one-off command, a path that bypasses the job) still gets for free, and
 * the belt to the queue lock's braces for the case that matters most: two
 * concurrent runs over one connector do not merely waste a read —
 * `stageAndApply()` supersedes the other run's still-pending rows, and
 * `ChangeApplier` would apply the same joiner twice from two runs whose
 * `after_json` provenance then disagrees.
 *
 * CONTENTION RETURNS THE RUN IN FLIGHT, IT DOES NOT RAISE. "Somebody else is
 * already doing this, here it is" is a truthful answer for every caller: the
 * scheduled job logs and returns, and the screen redirects to a run whose
 * status says `running`. Raising would 500 an admin screen for a double
 * click. The one case that DOES raise is a held lock with no visible running
 * run, which is a state nobody should be able to reach quietly.
 */
class DirectorySyncService
{
    /**
     * The lock's TTL. Deliberately the job's own timeout: a worker killed
     * with SIGKILL never runs `finally`, so the lock must expire on its own
     * within one run's budget or the connector is wedged until somebody
     * flushes the cache. Anything shorter would let a legitimately long
     * nightly reconciliation lose its own lock mid-run to a delta tick.
     */
    private const LOCK_SECONDS = \App\Jobs\Bcms\SyncBcmsIdentityJob::TIMEOUT_SECONDS;

    public function __construct(
        private DirectoryClient $client,
        private ChangeDetector $detector,
        private ImpactAssessor $impact,
        private ChangeApplier $applier,
    ) {}

    public function runFull(IdentityConnector $connector, SyncTrigger $trigger, ?int $triggeredByUserId = null): IdentitySyncRun
    {
        return $this->exclusively($connector, function () use ($connector, $trigger, $triggeredByUserId): IdentitySyncRun {
            $run = $this->startRun($connector, $trigger, $triggeredByUserId);
            $failure = null;

            // The provider's generator is handed straight to the detector
            // rather than drained into an array first (gate 2 advisory 3) —
            // a 5,000-user read is consumed one page at a time instead of
            // fully materialised in memory before detection can even start.
            // `ChangeDetector::detectFullReconciliation()` catches a
            // `DirectorySyncException` raised mid-read itself and hands the
            // failure back in its own return value, which is what makes this
            // safe: the caller no longer needs to know up front whether the
            // read finished in order to decide whether a leaver sweep runs.
            try {
                $detected = $this->detector->detectFullReconciliation($connector, $this->client->users($connector));

                $failure = $detected['failure'];

                $this->stageAndApply($connector, $run, $detected['changes']);
            } catch (Throwable $e) {
                $this->abort($run, $this->fetchStats(), $e);
            }

            return $this->finish($run, $this->fetchStats(), $failure);
        });
    }

    public function runDelta(IdentityConnector $connector, SyncTrigger $trigger, ?int $triggeredByUserId = null): IdentitySyncRun
    {
        return $this->exclusively($connector, function () use ($connector, $trigger, $triggeredByUserId): IdentitySyncRun {
            $run = $this->startRun($connector, $trigger, $triggeredByUserId);

            $failure = null;
            $objects = [];
            $stats = ['pages' => 0, 'objects' => 0];

            try {
                $result = $this->client->delta($connector, $connector->delta_link);
                $objects = $result['users'] instanceof \Traversable ? iterator_to_array($result['users'], false) : (array) $result['users'];

                $connector->forceFill(['delta_link' => $result['delta_link']])->save();

                $stats = $this->client instanceof ReportsDirectoryFetchStats
                    ? $this->client->lastFetchStats()
                    : ['pages' => 1, 'objects' => count($objects)];
            } catch (DirectorySyncException $e) {
                $failure = $e;
            }

            try {
                $detected = $this->detector->detectDelta($connector, $objects);

                $this->stageAndApply($connector, $run, $detected['changes']);
            } catch (Throwable $e) {
                $this->abort($run, $stats, $e);
            }

            return $this->finish($run, $stats, $failure);
        });
    }

    public function testConnection(IdentityConnector $connector): array
    {
        $result = $this->client->testConnection($connector);

        $connector->recordAudit('identity.connector.tested', [
            'ok' => $result['ok'],
            'scopes' => $result['scopes'],
            'sample_count' => $result['sample_count'],
        ]);

        return $result;
    }

    /**
     * Approve, reject or auto-apply already-staged rows respect this same
     * writer — exposed so `IdentitySyncController` never calls
     * `ChangeApplier` directly and every application is audited the same way
     * whether it happened during a run or a day later from the review queue.
     */
    public function decide(IdentitySyncChange $change, SyncChangeDecision $decision, ?int $userId = null): void
    {
        // Only `approved`/`rejected` are decisions a human makes from the
        // queue — `DecideIdentitySyncChangeRequest` and
        // `BulkDecideIdentitySyncChangesRequest` are the only two callers and
        // both validate `decision` against exactly those two values;
        // `auto_applied`/`superseded` are the sync's own doing and never
        // reach here.
        //
        // THE PENDING -> DECIDED TRANSITION IS CONDITIONAL, NOT READ-THEN-
        // WRITE. Two requests racing the same row (a double click, two open
        // tabs) must apply at most once — a second `forceFill()->save()`
        // with no guard would happily re-run `ChangeApplier::apply()` a
        // second time on a row that had already moved on. The `WHERE
        // decision = pending` makes the UPDATE itself the compare-and-swap;
        // zero affected rows means somebody else's request already decided
        // this one, and this call is a no-op rather than a second apply.
        $decided = IdentitySyncChange::query()
            ->whereKey($change->getKey())
            ->where('decision', SyncChangeDecision::Pending->value)
            ->update([
                'decision' => $decision->value,
                'decided_by' => $userId,
                'decided_at' => now(),
            ]);

        if ($decided === 0) {
            return;
        }

        // Reflect the write onto the caller's in-memory instance without a
        // second `save()` — the conditional UPDATE above is the only write.
        $change->forceFill([
            'decision' => $decision->value,
            'decided_by' => $userId,
            'decided_at' => now(),
        ]);

        $change->recordAudit(
            $decision === SyncChangeDecision::Approved ? 'identity.change.approved' : 'identity.change.rejected',
            ['sync_change_id' => $change->getKey(), 'kind' => $change->kind->value],
        );

        if ($decision === SyncChangeDecision::Approved) {
            $this->applier->apply($change);
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * Run `$work` while holding this connector's sync lock, or hand back the
     * run that already holds it.
     *
     * RELEASED IN A `finally`, so an abort, a bug or a `DirectorySyncException`
     * frees the connector immediately rather than parking it for the lock's
     * full hour. The TTL is the backstop for the case no `finally` can cover:
     * `kill -9` on the worker.
     *
     * @param  \Closure(): IdentitySyncRun  $work
     */
    private function exclusively(IdentityConnector $connector, \Closure $work): IdentitySyncRun
    {
        /** @var Lock $lock */
        $lock = Cache::lock($this->lockKey($connector), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return $this->runAlreadyInFlight($connector);
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    private function lockKey(IdentityConnector $connector): string
    {
        return 'bcms:identity:sync:'.$connector->organization_id.':'.$connector->getKey();
    }

    /**
     * The lock is held, so somebody else's run is the answer to this call.
     *
     * A held lock with no `running` run is the only genuinely wrong state
     * here — the run row is written BEFORE the first Graph call, so it exists
     * within milliseconds of the lock being taken. It raises rather than
     * inventing a run, and `SyncBcmsIdentityJob` treats that as a skipped
     * tick rather than a failure.
     */
    private function runAlreadyInFlight(IdentityConnector $connector): IdentitySyncRun
    {
        $running = IdentitySyncRun::query()
            ->where('identity_connector_id', $connector->getKey())
            ->where('status', SyncRunStatus::Running->value)
            ->orderByDesc('started_at')
            ->first();

        Log::info('BCMS identity sync declined: a run is already in flight for this connector', [
            'organization_id' => $connector->organization_id,
            'connector_id' => $connector->getKey(),
            'run_id' => $running?->getKey(),
        ]);

        if ($running === null) {
            throw new DirectorySyncException('sync_lock_held_without_run', null);
        }

        return $running;
    }

    /**
     * The provider client's own read counters — `runFull()` no longer keeps
     * a buffered array of every `DirectoryUser` it can reconstruct a count
     * from (gate 2 advisory 3), so this reads `pages`/`objects` from
     * whichever client is bound, exactly as `runDelta()` already does when
     * one is available. Both shipped clients (`EntraGraphClient`,
     * `FakeDirectoryClient`) report them; zero rather than a guess is the
     * honest fallback for a hypothetical future client that does not.
     *
     * @return array{pages: int, objects: int}
     */
    private function fetchStats(): array
    {
        return $this->client instanceof ReportsDirectoryFetchStats
            ? $this->client->lastFetchStats()
            : ['pages' => 0, 'objects' => 0];
    }

    /**
     * Close a run out when something OTHER than a directory read failed.
     *
     * `runFull()`/`runDelta()` already turn a `DirectorySyncException` from
     * the read into a `partial`/`failed` status with a bounded code. This is
     * the other half: a deadlock in `stageAndApply()`, a `ValueError` from an
     * enum cast, a `QueryException` from a column a future migration
     * narrowed. Before this, any of those escaped with the run row still on
     * `running` — the write-ahead row was written and then never closed,
     * which is the exact silence the write-ahead exists to break.
     *
     * BOUNDED ERROR CLASS, NEVER A MESSAGE (ADR 0018 §2.3). `class_basename`
     * of the throwable is a PHP class name; `getMessage()` is not read, not
     * logged and not stored — a `QueryException` message contains the SQL and
     * its bindings, which here are staff names and mobile numbers.
     *
     * IT RETHROWS. The run row tells the operator; the rethrow tells the
     * engineers, through `failed_jobs` and whatever the deployment's error
     * reporter is. Swallowing it would leave a scheduled job reporting success
     * on a night it staged nothing.
     *
     * @param  array{pages: int, objects: int}  $stats
     */
    private function abort(IdentitySyncRun $run, array $stats, Throwable $e): never
    {
        $tally = $this->tally($run);

        $run->forceFill(array_merge($tally, [
            'finished_at' => now(),
            'status' => ($stats['objects'] > 0 ? SyncRunStatus::Partial : SyncRunStatus::Failed)->value,
            'directory_objects_read' => $stats['objects'],
            'pages_fetched' => $stats['pages'],
            'error_class' => 'staging_'.mb_substr(class_basename($e), 0, 180),
            'error_code' => null,
        ]))->save();

        $run->recordAudit('identity.sync.finished', [
            'status' => $run->status->value,
            'objects_read' => $stats['objects'],
            'aborted' => true,
        ]);

        Log::error('BCMS identity sync aborted while staging changes', [
            'organization_id' => $run->organization_id,
            'run_id' => $run->getKey(),
            'exception_class' => $e::class,
        ]);

        throw $e;
    }

    private function startRun(IdentityConnector $connector, SyncTrigger $trigger, ?int $triggeredByUserId): IdentitySyncRun
    {
        $run = IdentitySyncRun::query()->create([
            'organization_id' => $connector->organization_id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => $trigger->value,
            'started_at' => now(),
            'status' => SyncRunStatus::Running->value,
            'triggered_by' => $triggeredByUserId,
        ]);

        $run->recordAudit('identity.sync.started', ['trigger' => $trigger->value]);

        return $run;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function stageAndApply(IdentityConnector $connector, IdentitySyncRun $run, array $entries): void
    {
        $policy = $connector->auto_apply_policy;

        // Deferred manager-edge resolution — a second pass, run AFTER every
        // entry below has been staged and (where the policy allows)
        // auto-applied. Directory order gives no guarantee that a manager
        // is created before their report within one page, let alone one
        // run, and a two-person management loop has no order that resolves
        // both edges in a single pass by construction. `ChangeApplier::
        // apply()`'s own inline resolution catches the common case; this
        // catches everyone else, by which point every contact this run
        // touched already exists.
        $deferredManagerEdges = [];

        DB::transaction(function () use ($connector, $run, $entries, $policy, &$deferredManagerEdges) {
            foreach ($entries as $entry) {
                if ($entry === null) {
                    continue;
                }

                /** @var SyncChangeKind $kind */
                $kind = $entry['kind'];

                // A still-pending change for the same object and kind from an
                // earlier run of this connector is superseded, not
                // duplicated — otherwise a leaver nobody has reviewed
                // reappears every delta tick (ADR 0018 §2.4).
                IdentitySyncChange::query()
                    ->whereHas('run', fn ($q) => $q->where('identity_connector_id', $connector->getKey()))
                    ->where('directory_object_id', $entry['directory_object_id'])
                    ->where('kind', $kind->value)
                    ->where('decision', SyncChangeDecision::Pending->value)
                    ->update(['decision' => SyncChangeDecision::Superseded->value]);

                $contact = $entry['contact_id'] !== null ? Contact::query()->find($entry['contact_id']) : null;

                $assessment = $contact !== null
                    ? $this->impact->assess($contact, $kind)
                    : ['impact' => null, 'requires_ack' => false];

                $change = IdentitySyncChange::query()->create([
                    'organization_id' => $connector->organization_id,
                    'sync_run_id' => $run->getKey(),
                    'contact_id' => $entry['contact_id'],
                    'kind' => $kind->value,
                    'directory_object_id' => $entry['directory_object_id'],
                    'subject_name' => $entry['subject_name'],
                    'before_json' => $entry['before_json'],
                    'after_json' => $entry['after_json'],
                    'impact_json' => $assessment['impact'],
                    'requires_ack' => $assessment['requires_ack'],
                    'decision' => SyncChangeDecision::Pending->value,
                ]);

                if ($policy->permitsAutoApply($assessment['requires_ack'])) {
                    $change->forceFill([
                        'decision' => SyncChangeDecision::AutoApplied->value,
                        'decided_at' => now(),
                    ])->save();

                    $this->applier->apply($change);

                    $change->recordAudit('identity.change.auto_applied', ['sync_change_id' => $change->getKey()]);

                    if (array_key_exists('_manager_object_id', $entry['after_json'] ?? []) && $change->contact_id !== null) {
                        $deferredManagerEdges[] = [$change->contact_id, $entry['after_json']['_manager_object_id']];
                    }
                }
            }
        });

        foreach ($deferredManagerEdges as [$contactId, $managerObjectId]) {
            $this->applier->finalizeManagerEdge($contactId, $managerObjectId);
        }
    }

    /**
     * The run's change counters, read back from the rows it actually staged.
     *
     * Shared by `finish()` and `abort()` so an aborted run reports the forty
     * rows it managed to stage rather than six zeroes — a `partial` whose
     * counters all read zero is indistinguishable on the screen from a run
     * that read nothing at all.
     *
     * @return array{joiner_count: int, leaver_count: int, mover_count: int, contact_change_count: int, auto_applied_count: int, pending_count: int}
     */
    private function tally(IdentitySyncRun $run): array
    {
        $counts = $run->changes()->selectRaw('kind, decision, count(*) as aggregate')
            ->groupBy('kind', 'decision')
            ->get();

        $joiners = $movers = $leavers = $contactChanges = $autoApplied = $pending = 0;

        foreach ($counts as $row) {
            // `aggregate` is a raw SELECT alias, not a declared model
            // attribute — `getAttribute()` reads it the same way `->aggregate`
            // would, without a static-analysis complaint about a property
            // the model never declares.
            $n = (int) $row->getAttribute('aggregate');

            match ($row->kind) {
                SyncChangeKind::Joiner => $joiners += $n,
                SyncChangeKind::Leaver => $leavers += $n,
                SyncChangeKind::Mover => $movers += $n,
                SyncChangeKind::ContactChange => $contactChanges += $n,
            };

            if ($row->decision === SyncChangeDecision::AutoApplied) {
                $autoApplied += $n;
            }

            if ($row->decision === SyncChangeDecision::Pending) {
                $pending += $n;
            }
        }

        return [
            'joiner_count' => $joiners,
            'leaver_count' => $leavers,
            'mover_count' => $movers,
            'contact_change_count' => $contactChanges,
            'auto_applied_count' => $autoApplied,
            'pending_count' => $pending,
        ];
    }

    /** @param  array{pages: int, objects: int}  $stats */
    private function finish(IdentitySyncRun $run, array $stats, ?DirectorySyncException $failure): IdentitySyncRun
    {
        $run->refresh();

        $status = match (true) {
            $failure === null => SyncRunStatus::Success,
            $failure->objectsReadBeforeFailure > 0 || $stats['objects'] > 0 => SyncRunStatus::Partial,
            default => SyncRunStatus::Failed,
        };

        $run->forceFill(array_merge($this->tally($run), [
            'finished_at' => now(),
            'status' => $status->value,
            'directory_objects_read' => $stats['objects'],
            'pages_fetched' => $stats['pages'],
            'error_class' => $failure?->errorClass,
            'error_code' => $failure?->errorCode,
        ]))->save();

        $run->recordAudit('identity.sync.finished', [
            'status' => $status->value,
            'objects_read' => $stats['objects'],
            'pages_fetched' => $stats['pages'],
        ]);

        return $run;
    }
}

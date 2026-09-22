<?php

namespace App\Console\Commands;

use App\Models\Bcms\AuditLog;
use App\Models\Bcms\NotificationDelivery;
use App\Models\Bcms\ReminderSchedule;
use App\Models\Organization;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The heartbeat that notices when the notification path has stopped working.
 *
 * WHY THIS IS AN OPUS-LEVEL CONCERN AND NOT A NICE-TO-HAVE. Orchestration §2
 * puts the reliability engineer on Opus because "a silent reminder failure is a
 * customer compliance breach, not a bug". The failure modes this looks for all
 * have the same shape: nothing errors, nothing appears in a log, and the
 * customer finds out when an examiner asks why the March drill was never run.
 *
 * THREE SIGNALS, each a specific broken thing:
 *
 *   OVERDUE PENDING REMINDERS — a row whose `send_at` has passed and is still
 *   `pending`. Either the scheduler is not running or the dispatcher is
 *   throwing. ADR 0005 is explicit that this is a DEFECT TO REPORT, never a row
 *   to quietly drop.
 *
 *   STUCK QUEUED DELIVERIES — a `bcms_notification_deliveries` row written
 *   ahead of a provider call (standing rule 8) that never moved off `queued`.
 *   This is exactly the row the write-ahead exists to leave behind when a
 *   worker dies mid-send, and it is only useful if something looks for it.
 *
 *   A DEAD LIFE-SAFETY QUEUE — the pool that must never be cold. It is checked
 *   separately from the others because it is the one whose failure kills
 *   somebody rather than a compliance date.
 *
 * IT REPORTS AND DOES NOT REPAIR. A watchdog that retried what it found would
 * hide the fault it exists to surface, and could re-send an alert that did in
 * fact go out.
 *
 * PHASE 5 ADDED THE ALERTING HOOK, and who it tells matters. The tenant's own
 * administrators are told because it is their compliance date; **Atheris support
 * is told as well** because a stalled scheduler is our fault far more often than
 * theirs, and a customer who discovers it from an examiner has discovered it too
 * late. That second recipient is the reason this is a watchdog rather than a
 * dashboard tile.
 */
class BcmsWatchdog extends Command
{
    protected $signature = 'bcms:watchdog {--minutes=30 : How late a pending send has to be to count}';

    protected $description = 'Check the BCMS notification path for silently stalled reminders and deliveries.';

    public function handle(): int
    {
        // Same reason as every other bcms: command — a module that is off must
        // be off in the scheduler too, not only at the HTTP boundary. This one
        // reads rather than sends, so the exposure was an hourly query and a
        // possible false alarm rather than a message to staff, but "dark"
        // cannot be a property of the routing table alone.
        if (! config('features.bcms')) {
            $this->line('The BCMS module is switched off; nothing to watch.');

            return self::SUCCESS;
        }

        $threshold = now()->subMinutes((int) $this->option('minutes'));
        $problems = [];
        $identityProblems = [];
        $health = app(\App\Services\Bcms\Identity\ConnectorHealth::class);

        foreach (Organization::query()->get() as $organization) {
            TenantContext::set($organization->id);

            try {
                $overdue = ReminderSchedule::query()
                    ->where('status', 'pending')
                    ->where('send_at', '<', $threshold)
                    ->count();

                $stuck = NotificationDelivery::query()
                    ->where('status', 'queued')
                    ->where('created_at', '<', $threshold)
                    ->count();

                if ($overdue > 0 || $stuck > 0) {
                    $problems[] = [
                        // The id is what `alertAdministrators()` looks the
                        // organisation back up by. `organizations.name` has no
                        // unique index — two tenants with similar registered
                        // names is not unusual in Nigerian banking group
                        // structures — so a name-based re-lookup can resolve to
                        // the WRONG tenant and notify its administrators about
                        // another tenant's stalled reminders.
                        'organization_id' => $organization->id,
                        'organization' => $organization->name,
                        'overdue_reminders' => $overdue,
                        'stuck_deliveries' => $stuck,
                    ];
                }

                // BCMS Phase 2C (ADR 0018 §4, work order §7). Two checks, both
                // derived from `bcms_identity_sync_runs` — never a stored
                // status column (`ConnectorHealth`, ADR 0018 §2.2 point 3). A
                // secret that lapses silently freezes the roster, which is the
                // failure mode this whole module exists to prevent, applied
                // to itself.
                foreach (\App\Models\Bcms\IdentityConnector::query()->where('is_active', true)->get() as $connector) {
                    $isStale = $health->isStale($connector);
                    $expiring = $health->credentialExpiringSoon($connector);
                    $stuckRun = $this->stuckRun((int) $connector->getKey());
                    $hierarchyStale = $this->hierarchyStale($connector);

                    if ($isStale || $expiring || $stuckRun !== null || $hierarchyStale) {
                        $identityProblems[] = [
                            'organization_id' => $organization->id,
                            'organization' => $organization->name,
                            'connector_id' => $connector->getKey(),
                            'is_stale' => $isStale,
                            'credential_expiring_soon' => $expiring,
                            'credential_expires_on' => $connector->credential_expires_on?->toDateString(),
                            'stuck_run_id' => $stuckRun?->getKey(),
                            'stuck_run_started_at' => $stuckRun?->started_at?->toIso8601String(),
                            'hierarchy_stale' => $hierarchyStale,
                        ];
                    }
                }
            } finally {
                TenantContext::clear();
            }
        }

        // A fourth signal, and the only one that is not about notifications:
        // audit rows that could not be written.
        //
        // BcmsAuditable::writeBcmsAuditRow() catches Throwable on purpose, so a
        // business write never fails because its audit row would not save. That
        // is right, and it is also exactly how a too-narrow `event` column
        // discarded audit rows for the life of the module without anything
        // noticing (ADR 0014). Widening the column fixed that cause; this
        // catches every other one — a lock timeout, a full disk, a byte that
        // will not encode, a future migration narrowing any column on the
        // table. In a module whose deliverable IS the log, an audit path that
        // has started failing is worth waking somebody for.
        $auditFailures = (int) Cache::get(AuditLog::AUDIT_FAILURE_CACHE_KEY, 0);

        if ($auditFailures > 0) {
            $this->error(sprintf(
                '%d BCMS audit row(s) could not be written since the last check. The audit trail has holes; '.
                'see the "BCMS audit row could not be written" log entries for the cause.',
                $auditFailures,
            ));

            // ITS OWN PATH, NOT A ROW IN $problems. This signal is not
            // per-tenant — the counter never records which organisation's
            // write failed, and TenantContext is not even set for a queue
            // worker or command outside a tenant loop — so there is no
            // organisation to notify and never was. Threading it through
            // `alertAdministrators()`'s per-org name lookup as an
            // 'ALL TENANTS' sentinel meant it matched nothing and `continue`d,
            // so the one signal that the audit trail itself has holes reached
            // nobody but a `Log::error` call further down that only fires when
            // $problems is non-empty for some other reason.
            $this->alertSupportOfAuditFailures($auditFailures);

            // Cleared so the next run reports only new failures. A count that
            // never resets stops meaning anything the day after it first fires.
            Cache::forget(AuditLog::AUDIT_FAILURE_CACHE_KEY);
        }

        if ($identityProblems !== []) {
            // THE SAME ALERTING PATH THE OTHER CHECKS USE. Before this, an
            // identity problem printed a line on a console nobody is reading
            // at 03:30 and was folded into a `Log::error` that only fires
            // when some other signal is also non-empty. A connector whose
            // secret expired in December would have gone to nobody until an
            // examiner asked why the leaver list stopped in January — the
            // exact silence this command exists to break, aimed at the
            // module's own supply of contacts.
            $this->alertOfIdentityProblems($identityProblems);

            foreach ($identityProblems as $problem) {
                $this->error(sprintf('%s: identity connector %s.', $problem['organization'], $this->describeIdentityProblem($problem)));
            }
        }
        // Three independent signals, checked together: a stalled notification
        // path, a stale/expiring identity connector and a hole in the audit
        // trail are all separately detectable, and none may hide another from
        // the exit code a cron wrapper reads.
        if ($problems === [] && $auditFailures === 0 && $identityProblems === []) {
            $this->info('BCMS notification path healthy.');

            return self::SUCCESS;
        }

        if ($problems !== []) {
            $this->alertAdministrators($problems);

            foreach ($problems as $problem) {
                $this->error(sprintf(
                    '%s: %d reminder(s) overdue, %d delivery(ies) stuck in queued.',
                    $problem['organization'],
                    $problem['overdue_reminders'],
                    $problem['stuck_deliveries'],
                ));
            }
        }

        // Error level, not warning: a stalled notification path or a hole in
        // the audit trail is a compliance breach in progress and should page
        // somebody.
        Log::error('BCMS watchdog found a stalled notification path', [
            'problems' => $problems,
            'audit_write_failures' => $auditFailures,
            'identity_problems' => $identityProblems,
        ]);

        // A non-zero exit so a cron wrapper or a monitor notices without
        // having to parse the output.
        return self::FAILURE;
    }

    /**
     * Tell the people whose problem this is.
     *
     * IN-APP AND A LOG LINE, NOT AN EMAIL THROUGH THE THING THAT IS BROKEN. The
     * fault being detected is "the notification path has stopped", so routing
     * the warning about it through that same path is how a watchdog reports
     * nothing at the moment it matters. `notifications_log` is a database
     * insert and the support line is a log record an operator's collector
     * scrapes; neither depends on a queue worker or a gateway.
     *
     * @param  list<array<string, mixed>>  $problems
     */
    private function alertAdministrators(array $problems): void
    {
        foreach ($problems as $problem) {
            // Looked up by id, not by `organizations.name` — the column has no
            // unique index, and two tenants with similar registered names is
            // not unusual in Nigerian banking group structures. The id was
            // already in hand when the problem was recorded above.
            $organization = Organization::query()->find($problem['organization_id']);

            if ($organization === null) {
                continue;
            }

            TenantContext::set($organization->id);

            try {
                $admins = User::query()
                    ->where('is_active', true)
                    ->get()
                    ->filter(fn (User $u) => $u->can('bcms.admin'));

                foreach ($admins as $admin) {
                    NotificationService::send(
                        organizationId: (int) $organization->id,
                        userId: (int) $admin->getKey(),
                        type: 'bcms.watchdog.stalled',
                        subject: 'Business continuity reminders have stopped sending',
                        body: sprintf(
                            '%d exercise reminders are past their send time and %d deliveries are stuck. '
                            .'Until this is fixed, exercise notices are not reaching anybody — which is a '
                            .'compliance gap rather than an inconvenience. Atheris support has been notified.',
                            $problem['overdue_reminders'],
                            $problem['stuck_deliveries'],
                        ),
                        metadata: $problem,
                        priority: 'high',
                        category: 'bcms',
                    );
                }
            } finally {
                TenantContext::clear();
            }
        }

        // The support channel. A structured line an operator's log collector
        // alerts on, deliberately separate from the per-tenant notification —
        // if every tenant's admin is asleep, somebody at Atheris is not.
        Log::critical('BCMS watchdog: notification path stalled, support notified', [
            'support_alert' => true,
            'problems' => $problems,
        ]);
    }

    /**
     * A directory sync that was written ahead and never closed out.
     *
     * The run row goes in with `status = running` BEFORE the first Graph call
     * (standing rule 8), and `SyncBcmsIdentityJob::failed()` closes it out on
     * any exception or on the queue timeout. Neither of those fires when the
     * worker is killed outright — `kill -9`, an OOM kill, the container going
     * away mid-deploy — and the row then sits on `running` for ever. It is
     * NOT stale by `ConnectorHealth::isStale()`'s reckoning either, because
     * that reads the last SUCCESSFUL run and this one never claimed to be one.
     * So without this check the single most likely 2 a.m. outcome — worker
     * dies mid-apply — produces a connector screen showing "sync in progress"
     * indefinitely and no alert at all.
     *
     * THE THRESHOLD IS THE JOB'S OWN TIMEOUT PLUS A TEN-MINUTE GRACE, read
     * from `SyncBcmsIdentityJob::TIMEOUT_SECONDS` rather than copied. A
     * legitimately long nightly reconciliation must never be reported as
     * stuck; anything past the budget the queue itself would have killed it at
     * is stuck by definition.
     */
    private function stuckRun(int $connectorId): ?\App\Models\Bcms\IdentitySyncRun
    {
        return \App\Models\Bcms\IdentitySyncRun::query()
            ->where('identity_connector_id', $connectorId)
            ->where('status', \App\Enums\Bcms\SyncRunStatus::Running->value)
            ->where('started_at', '<', now()->subSeconds(\App\Jobs\Bcms\SyncBcmsIdentityJob::TIMEOUT_SECONDS + 600))
            ->orderBy('started_at')
            ->first();
    }

    /**
     * The nightly FULL reconciliation has stopped, while deltas keep passing.
     *
     * `ConnectorHealth::isStale()` counts any successful run, which is right
     * for the screen and blind to this: on `nightly_plus_delta` a delta
     * succeeds every fifteen minutes, so a full run that has failed every
     * night for a month leaves every freshness indicator green. ADR 0018 §3.1
     * is explicit that a delta never re-resolves a manager edge — only the
     * full run maintains the hierarchy — so this failure mode is "the call
     * tree quietly stopped being maintained", which is exactly the kind of
     * thing that is discovered during an exercise.
     *
     * Forty-eight hours: twice the nightly schedule, the same threshold
     * `ConnectorHealth` uses, so one missed night is not an alarm and two are.
     */
    private function hierarchyStale(\App\Models\Bcms\IdentityConnector $connector): bool
    {
        if ($connector->sync_schedule === 'manual') {
            return false;
        }

        return ! \App\Models\Bcms\IdentitySyncRun::query()
            ->where('identity_connector_id', $connector->getKey())
            ->whereIn('trigger', [\App\Enums\Bcms\SyncTrigger::ScheduledFull->value, \App\Enums\Bcms\SyncTrigger::Manual->value])
            ->whereIn('status', [\App\Enums\Bcms\SyncRunStatus::Success->value, \App\Enums\Bcms\SyncRunStatus::Partial->value])
            ->where('started_at', '>=', now()->subHours(48))
            ->exists();
    }

    /** @param  array<string, mixed>  $problem */
    private function describeIdentityProblem(array $problem): string
    {
        $clauses = [];

        if ($problem['stuck_run_id'] !== null) {
            $clauses[] = sprintf('has a sync stuck on "running" since %s (run %d) — its worker died without closing the run out', $problem['stuck_run_started_at'], $problem['stuck_run_id']);
        }

        if ($problem['is_stale'] === true) {
            $clauses[] = 'has not run successfully in over 48 hours';
        }

        if ($problem['hierarchy_stale'] === true) {
            $clauses[] = 'has had no successful FULL reconciliation in over 48 hours, so the manager hierarchy is no longer being maintained';
        }

        if ($problem['credential_expiring_soon'] === true) {
            $clauses[] = 'has a credential expiring on '.$problem['credential_expires_on'];
        }

        return implode('; and ', $clauses);
    }

    /**
     * Tell the identity administrators, and tell support.
     *
     * `bcms.identity.manage` rather than `bcms.admin`: holding the credential
     * that reads the bank's directory is what this permission means (ADR 0018
     * §7), and it is the holder who can renew a lapsed secret. Structured
     * `Log::critical` with `support_alert` alongside, exactly as the
     * notification-path and regulatory-clock checks do — a roster that has
     * stopped updating is our fault more often than the customer's.
     *
     * @param  list<array<string, mixed>>  $problems
     */
    private function alertOfIdentityProblems(array $problems): void
    {
        foreach ($problems as $problem) {
            $organization = Organization::query()->find($problem['organization_id']);

            if ($organization === null) {
                continue;
            }

            TenantContext::set($organization->id);

            try {
                $admins = User::query()
                    ->where('is_active', true)
                    ->get()
                    ->filter(fn (User $u) => $u->can('bcms.identity.manage'));

                foreach ($admins as $admin) {
                    NotificationService::send(
                        organizationId: (int) $organization->id,
                        userId: (int) $admin->getKey(),
                        type: 'bcms.watchdog.identity_sync',
                        subject: 'The directory sync has stopped keeping the roster current',
                        body: sprintf(
                            'The Entra connector %s. Until it is fixed, joiners are missing from call trees and '
                            .'leavers are still on them — an alert sent today would reach the wrong list of people. '
                            .'Atheris support has been notified.',
                            $this->describeIdentityProblem($problem),
                        ),
                        metadata: $problem,
                        priority: 'high',
                        category: 'bcms',
                    );
                }
            } finally {
                TenantContext::clear();
            }
        }

        Log::critical('BCMS watchdog: identity sync is stale, stuck or about to lose its credential', [
            'support_alert' => true,
            'problems' => $problems,
        ]);
    }

    /**
     * Tell support that the audit trail itself has holes.
     *
     * ITS OWN PATH, NOT A SENTINEL ROW IN `alertAdministrators()`'s per-org
     * list. `AuditLog::AUDIT_FAILURE_CACHE_KEY` is a single tenant-agnostic
     * counter — `BcmsAuditable::writeBcmsAuditRow()` increments it wherever it
     * runs, including a queue worker or a console command with no tenant
     * resolved, so there is no organisation id to attach it to and never was.
     * Pretending otherwise with an unmatched organisation name is how this
     * signal reached nobody but a `Log::error` call that only fired when some
     * other, unrelated problem happened to exist that day.
     *
     * Structured and at `critical`, like the notification-path signal above,
     * so the same log collector alerts on it independently of whether any
     * tenant also has a stalled reminder.
     */
    private function alertSupportOfAuditFailures(int $auditFailures): void
    {
        Log::critical('BCMS watchdog: audit trail has holes', [
            'support_alert' => true,
            'audit_write_failures' => $auditFailures,
        ]);
    }
}

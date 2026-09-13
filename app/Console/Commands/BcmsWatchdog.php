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

        // Two independent signals, checked together: a stalled notification
        // path is per-tenant, a hole in the audit trail is not, and neither
        // may hide the other from the exit code a cron wrapper reads.
        if ($problems === [] && $auditFailures === 0) {
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

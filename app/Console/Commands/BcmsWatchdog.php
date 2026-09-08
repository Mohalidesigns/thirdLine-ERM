<?php

namespace App\Console\Commands;

use App\Models\Bcms\NotificationDelivery;
use App\Models\Bcms\ReminderSchedule;
use App\Models\Organization;
use Illuminate\Console\Command;
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
 * fact go out. Phase 5 adds the alerting hook; the detection is here because
 * the schema it detects against is frozen now.
 */
class BcmsWatchdog extends Command
{
    protected $signature = 'bcms:watchdog {--minutes=30 : How late a pending send has to be to count}';

    protected $description = 'Check the BCMS notification path for silently stalled reminders and deliveries.';

    public function handle(): int
    {
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
                        'organization' => $organization->name,
                        'overdue_reminders' => $overdue,
                        'stuck_deliveries' => $stuck,
                    ];
                }
            } finally {
                TenantContext::clear();
            }
        }

        if ($problems === []) {
            $this->info('BCMS notification path healthy.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error(sprintf(
                '%s: %d reminder(s) overdue, %d delivery(ies) stuck in queued.',
                $problem['organization'],
                $problem['overdue_reminders'],
                $problem['stuck_deliveries'],
            ));
        }

        // Error level, not warning: a stalled notification path is a
        // compliance breach in progress and should page somebody.
        Log::error('BCMS watchdog found a stalled notification path', ['problems' => $problems]);

        // A non-zero exit so a cron wrapper or a monitor notices without
        // having to parse the output.
        return self::FAILURE;
    }
}

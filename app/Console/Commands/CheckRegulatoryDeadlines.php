<?php

namespace App\Console\Commands;

use App\Models\LossEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Warn on loss events whose CBN reporting clock is about to run out.
 *
 * THIS COMMAND HAD NEVER RUN. Scheduled twice daily since it was written
 * (routes/console.php, `->twiceDaily(8, 16)`), it queried four columns that do
 * not exist on `loss_events` and never have:
 *
 *     cbn_report_deadline   → the column is `cbn_reporting_deadline`
 *     cbn_reported          → the column is `cbn_notification_sent`
 *     nfiu_report_deadline  → THERE IS NO SUCH COLUMN (see below)
 *     nfiu_reported         → the column is `nfiu_report_filed`
 *
 * Every run therefore died on the first query with
 * "Unknown column 'cbn_report_deadline' in 'where clause'", and not one alert
 * has ever been sent. This is the alarm that tells a bank it has hours left to
 * make a mandatory CBN loss report.
 *
 * Found in Phase 5.3 by writing the test the command had never had — the same
 * gap Phase 4's criterion 5 turned up for `measures:rebaseline-thresholds`.
 * Worth knowing for anything similar: `$this->artisan(...)->run()` returns 0
 * even when the command throws, so a test that only asserts success proves
 * nothing about a console command. The tests assert the ALERTS.
 *
 * A SECOND FATAL DEFECT SAT UNDER THE FIRST. The insert set
 * `'user_id' => null` on `notifications_log`, whose `user_id` is NOT NULL with
 * a foreign key — so even with the column names corrected, every alert would
 * have failed on the insert. And it would have been invisible anyway: the
 * notification bell counts rows `where('user_id', $user->id)`, so a row
 * belonging to nobody is read by nobody. The alert now goes to the officer the
 * loss event names — `responsible_officer_id`, then `assigned_to_id`, then
 * whoever recorded it — and an event naming no one at all is reported on
 * screen rather than dropped, because "nobody is accountable for this CBN
 * clock" is itself worth saying out loud.
 *
 * THE NFIU HALF IS GONE, and not because it was inconvenient. `loss_events`
 * carries `nfiu_reportable`, `nfiu_report_type`, `nfiu_str_reference` and
 * `nfiu_report_filed` — but NO NFIU DEADLINE COLUMN. There is no date to count
 * down to, so an NFIU countdown cannot be computed from what is stored, and
 * inventing one would be fabricating a regulatory clock. The gap is recorded in
 * docs/migration/phase-5-notes/regulatory.md; when the schema carries an NFIU
 * deadline, this command grows a second half against it.
 */
class CheckRegulatoryDeadlines extends Command
{
    protected $signature = 'regulatory:check-deadlines';

    protected $description = 'Warn on loss events whose CBN reporting deadline is approaching';

    /** How far ahead to look. */
    private const WINDOW_HOURS = 48;

    public function handle(): int
    {
        $this->info('Checking for approaching CBN reporting deadlines...');

        // `cbn_reporting_deadline` is a DATE column, so the window is measured
        // to the start of that day. A same-day deadline is inside it.
        $approaching = LossEvent::query()
            ->whereNotNull('cbn_reporting_deadline')
            ->where('cbn_reporting_deadline', '<=', now()->addHours(self::WINDOW_HOURS))
            ->where('cbn_reporting_deadline', '>', now())
            ->where('cbn_notification_sent', false)
            ->get();

        $sent = 0;
        $unaddressed = [];

        foreach ($approaching as $lossEvent) {
            $recipientId = $this->recipientFor($lossEvent);

            if ($recipientId === null) {
                $unaddressed[] = $lossEvent->event_reference;

                continue;
            }

            $this->raiseAlert($lossEvent, $recipientId);
            $sent++;
        }

        $this->info("Checked CBN reporting deadlines. Sent {$sent} urgent notifications.");

        if ($unaddressed !== []) {
            $this->warn(
                'No responsible officer on record for: '.implode(', ', $unaddressed).
                ' — these CBN deadlines could not be alerted to anybody.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * Who the alert goes to.
     *
     * The officer the event names, then whoever it is assigned to, then whoever
     * recorded it. Null when the event names nobody, which the caller reports
     * rather than swallowing.
     */
    private function recipientFor(LossEvent $lossEvent): ?int
    {
        foreach (['responsible_officer_id', 'assigned_to_id', 'created_by'] as $column) {
            $id = $lossEvent->getAttribute($column);

            if ($id !== null) {
                return (int) $id;
            }
        }

        return null;
    }

    /** `alert()` is taken: Command has its own, and it must stay public. */
    private function raiseAlert(LossEvent $lossEvent, int $recipientId): void
    {
        $hours = (int) round(now()->diffInHours($lossEvent->cbn_reporting_deadline));

        DB::table('notifications_log')->insert([
            'organization_id' => $lossEvent->organization_id,
            'user_id' => $recipientId,
            'channel' => 'database',
            'type' => 'regulatory_deadline_urgent',
            'subject' => "URGENT: CBN Reporting Deadline Approaching - {$lossEvent->event_reference}",
            'body' => "Loss event '{$lossEvent->title}' has a CBN reporting deadline in {$hours} hours.",
            'status' => 'sent',
            'metadata' => json_encode([
                'category' => 'regulatory',
                'priority' => 'critical',
                'action_url' => "/risk/loss-events/{$lossEvent->id}",
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->line("Deadline alert sent for {$lossEvent->event_reference} (CBN deadline in {$hours} hours).");
    }
}

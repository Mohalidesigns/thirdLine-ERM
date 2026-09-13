<?php

namespace App\Console\Commands;

use App\Services\Tprm\Reporting\ScheduledReportDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The daily scheduled-report sweep — FR-RPT-09.
 *
 * IT ASKS ABOUT THE DATE, NOT THE MINUTE. `ReportSchedule::isDueOn()` takes a
 * date; a schedule set for 07:00 that the cron reaches at 07:04 still fires.
 * Making the decision depend on the time of day would mean a schedule silently
 * skipped whenever the queue ran late, which is the failure this feature can
 * least afford — nobody notices an email that did not arrive.
 *
 * SENDING TWICE IS PREVENTED BY THE ROW, NOT BY THE CRON. `last_run_at` on the
 * same day means already sent, so re-running this command by hand — after a
 * deploy, or while debugging — does not put a second copy in twelve inboxes.
 *
 * A NON-ZERO EXIT ON FAILURE. TRD §14 asks for alerting on failed schedules,
 * and until that alerting exists the exit code is what a cron wrapper can see.
 */
class SendTprmScheduledReports extends Command
{
    protected $signature = 'tprm:send-scheduled-reports
        {--date= : Run as though it were this date, for testing a weekly or monthly schedule}';

    protected $description = 'Render and email every TPRM report schedule due today';

    public function handle(ScheduledReportDispatcher $dispatcher): int
    {
        if (! config('features.tprm')) {
            $this->comment('TPRM is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $on = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'))
            : CarbonImmutable::now();

        $totals = $dispatcher->dispatchDue($on);

        $this->info(sprintf(
            '%d due: %d sent, %d skipped, %d failed.',
            $totals['considered'],
            $totals['sent'],
            $totals['skipped'],
            $totals['failed'],
        ));

        if ($totals['skipped'] > 0) {
            // A skip is not a failure but it is not a delivery either, and a
            // run reporting only "0 failed" would read as healthy.
            $this->warn('Skipped schedules have a stated reason on the schedules screen.');
        }

        if (config('mail.default') === 'log') {
            // The module's known go-live gap. A green run here means the
            // mailer accepted the message, and the log driver accepts
            // everything.
            $this->warn('MAIL_MAILER is `log`: these reports went to the log file, not to anybody.');
        }

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

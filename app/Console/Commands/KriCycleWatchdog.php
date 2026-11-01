<?php

namespace App\Console\Commands;

use App\Models\KeyRiskIndicator;
use App\Models\KriCycleRun;
use App\Models\KriReturnNotice;
use App\Models\KriReturnNoticeRecipient;
use App\Models\Organization;
use App\Models\User;
use App\Services\Kri\KriCycleService;
use App\Services\Kri\KriNoticeService;
use App\Services\NotificationService;
use App\Support\Kri\KriWorkingCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * kri:cycle-watchdog - did the monthly cycle actually open, and did its mail
 * actually leave? (ADR 0026 section 12)
 *
 * For an active organisation with at least one active, non-automated KRI that
 * has a department, it is a FAILURE when:
 *   - Lagos today is on or after the first working day of this month and no
 *     `completed` kri_cycle_runs row exists for the reporting period; or
 *   - a run has been `running` for more than 60 minutes; or
 *   - a notice has been `queued` for more than 60 minutes with its recipients
 *     still unresolved; or
 *   - a notice has been `dispatching` for more than 60 minutes after its
 *     recipients were resolved and still has `pending` recipients. ADDED BY
 *     reliability-engineer: the dispatch job can resolve, commit and then die
 *     before queueing the sends, and that left every recipient `pending` and
 *     the notice `dispatching` for ever, matched by none of the three checks
 *     above. It is the same "mail that never left" condition one step later.
 * It WARNS (no failure) on recipients that failed in the last 24 hours.
 *
 * THE ALERT CHANNEL IS BcmsWatchdog's, ON PURPOSE. In-app, written straight to
 * `notifications_log` one holder at a time with NotificationService::send(),
 * and a structured Log::critical with `support_alert => true` that the
 * operator's log collector pages Atheris support on. NOT sendMany(): for more
 * than one holder that fans out through FanOutNotificationsJob on the queue,
 * and "the queue is not moving" is one of the things this command detects -
 * the warning would be posted through the fault it reports. Not email either,
 * for the same reason.
 *
 * ONE ORGANISATION'S ERROR IS ITS OWN FAILURE, never the end of the sweep.
 *
 * IT REPORTS AND, BY DEFAULT, DOES NOT REPAIR (BcmsWatchdog's rule: a watchdog
 * that quietly retried would hide the fault it exists to surface). The
 * operator's remedy is `--redispatch`, which the schedule never passes: it hands
 * stuck `queued` notices and stranded `pending` recipients back to the queue.
 * That is safe to run any number of times - DispatchKriReturnNotice acts only
 * on a still-`queued` notice under a lock, and SendKriReturnNoticeMail claims
 * its recipient row under a lock and sends only while it is `pending`.
 *
 * THRESHOLDS. 60 minutes for every "stuck" check: a dispatch job's whole retry
 * budget is under 3 minutes and a send job's under 10, so an hour is several
 * times the longest legitimate path and still the same morning. Scheduled
 * daily at 10:00 Africa/Lagos, three hours after kri:open-cycle.
 */
class KriCycleWatchdog extends Command
{
    /** Minutes before a run or a notice counts as stuck. */
    public const STUCK_MINUTES = 60;

    protected $signature = 'kri:cycle-watchdog
                            {--organization= : Only this organization id}
                            {--redispatch : Operator repair: hand stuck notices and stranded recipients back to the queue}';

    protected $description = 'Fail loudly when the monthly KRI cycle did not open or its notices are stuck';

    public function handle(KriCycleService $cycle, KriWorkingCalendar $calendar, KriNoticeService $notices): int
    {
        $only = $this->option('organization');

        if ($only !== null && preg_match('/^[1-9]\d*$/', (string) $only) !== 1) {
            $this->error('--organization must be an organization id.');

            return self::INVALID;
        }

        $organizationIds = TenantContext::bypass(
            fn () => Organization::query()
                ->where('is_active', true)
                ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
                ->orderBy('id')
                ->pluck('id')
                ->all(),
            'kri:cycle-watchdog organization sweep'
        );

        if ($only !== null && $organizationIds === []) {
            $this->error("Organization {$only} does not exist or is not active.");

            return self::FAILURE;
        }

        $failed = false;
        $supportProblems = [];

        foreach ($organizationIds as $organizationId) {
            $organizationId = (int) $organizationId;

            try {
                $problems = TenantContext::actingAs($organizationId, fn () => $this->check($organizationId, $cycle, $calendar));
            } catch (\Throwable $e) {
                report($e);
                $problems = ['failures' => ['The KRI cycle watchdog could not check this organisation: '.$e->getMessage()], 'warnings' => []];
            }

            foreach ($problems['warnings'] as $warning) {
                $this->warn("Organization {$organizationId}: {$warning}");
                Log::warning('KRI cycle watchdog', ['organization_id' => $organizationId, 'warning' => $warning]);
            }

            if ($this->option('redispatch')) {
                try {
                    TenantContext::actingAs($organizationId, fn () => $this->redispatch($organizationId, $notices));
                } catch (\Throwable $e) {
                    report($e);
                    $this->error("Organization {$organizationId}: redispatch failed - {$e->getMessage()}");
                }
            }

            if ($problems['failures'] === []) {
                continue;
            }

            $failed = true;
            $supportProblems[] = ['organization_id' => $organizationId, 'failures' => $problems['failures']];

            foreach ($problems['failures'] as $failure) {
                $this->error("Organization {$organizationId}: {$failure}");
            }

            TenantContext::actingAs($organizationId, fn () => $this->notifyHolders($organizationId, $problems['failures']));
        }

        if ($supportProblems !== []) {
            // The support channel: a structured line the log collector pages on,
            // independent of whether any tenant holder is awake or exists.
            Log::critical('KRI cycle watchdog: the monthly KRI cycle or its notices are stalled, support notified', [
                'support_alert' => true,
                'problems' => $supportProblems,
            ]);

            return self::FAILURE;
        }

        $this->info('KRI cycle healthy.');

        return self::SUCCESS;
    }

    /** @return array{failures: list<string>, warnings: list<string>} */
    private function check(int $organizationId, KriCycleService $cycle, KriWorkingCalendar $calendar): array
    {
        $out = ['failures' => [], 'warnings' => []];

        $hasKris = KeyRiskIndicator::query()
            ->whereNotNull('business_unit_id')
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))
            ->where(fn ($q) => $q->where('is_automated', false)->orWhereNull('is_automated'))
            ->exists();

        if (! $hasKris) {
            return $out;
        }

        $today = KriWorkingCalendar::today();
        $first = $calendar->firstWorkingDayOf((int) $today->format('Y'), (int) $today->format('n'), $organizationId);
        $stuckBefore = now()->subMinutes(self::STUCK_MINUTES);

        if ($today->toDateString() >= $first->toDateString()) {
            $period = $cycle->reportingPeriodFor($today, $organizationId);

            $completed = KriCycleRun::query()
                ->where('period_id', $period->id)
                ->where('status', KriCycleRun::STATUS_COMPLETED)
                ->exists();

            if (! $completed) {
                // The scheduled run acts only on the first working day, so a
                // missed or failed one is NOT retried by tomorrow's schedule.
                // The message carries the remedy.
                $out['failures'][] = "The KRI cycle for {$period->name} has not completed (first working day was {$first->toDateString()}). "
                    ."Open it with `php artisan kri:open-cycle --organization={$organizationId} --force`.";
            }
        }

        $stuckRuns = KriCycleRun::query()
            ->where('status', KriCycleRun::STATUS_RUNNING)
            ->where('started_at', '<', $stuckBefore)
            ->count();

        if ($stuckRuns > 0) {
            $out['failures'][] = "{$stuckRuns} KRI cycle run(s) have been running for more than ".self::STUCK_MINUTES.' minutes.';
        }

        $stuckNotices = KriReturnNotice::query()
            ->where('status', KriReturnNotice::STATUS_QUEUED)
            ->whereNull('recipients_resolved_at')
            ->where('queued_at', '<', $stuckBefore)
            ->count();

        if ($stuckNotices > 0) {
            $out['failures'][] = "{$stuckNotices} KRI notice(s) have been queued for more than ".self::STUCK_MINUTES.' minutes without being sent. '
                ."Re-queue them with `php artisan kri:cycle-watchdog --organization={$organizationId} --redispatch`.";
        }

        $stranded = count($this->strandedNoticeIds($stuckBefore));

        if ($stranded > 0) {
            $out['failures'][] = "{$stranded} KRI notice(s) resolved their recipients more than ".self::STUCK_MINUTES.' minutes ago and still have recipients waiting to be sent. '
                ."Re-queue them with `php artisan kri:cycle-watchdog --organization={$organizationId} --redispatch`.";
        }

        $recentFailures = KriReturnNoticeRecipient::query()
            ->where('status', KriReturnNoticeRecipient::STATUS_FAILED)
            ->where('failed_at', '>=', now()->subHours(24))
            ->count();

        if ($recentFailures > 0) {
            $out['warnings'][] = "{$recentFailures} KRI notice recipient(s) failed in the last 24 hours.";
        }

        return $out;
    }

    /**
     * `dispatching` notices resolved before $before that still have a
     * `pending` recipient. Two plain queries (ids, then a whereIn), no
     * correlated subquery and no GROUP BY, so it reads the same on both engines.
     *
     * @return list<int>
     */
    private function strandedNoticeIds(\DateTimeInterface $before): array
    {
        $dispatching = KriReturnNotice::query()
            ->where('status', KriReturnNotice::STATUS_DISPATCHING)
            ->where('recipients_resolved_at', '<', $before)
            ->pluck('id')
            ->all();

        if ($dispatching === []) {
            return [];
        }

        return KriReturnNoticeRecipient::query()
            ->whereIn('kri_return_notice_id', $dispatching)
            ->where('status', KriReturnNoticeRecipient::STATUS_PENDING)
            ->distinct()
            ->pluck('kri_return_notice_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Operator repair (--redispatch). Idempotent on row state; see the class comment. */
    private function redispatch(int $organizationId, KriNoticeService $notices): void
    {
        $before = now()->subMinutes(5);

        $queued = KriReturnNotice::query()
            ->where('status', KriReturnNotice::STATUS_QUEUED)
            ->whereNull('recipients_resolved_at')
            ->where('queued_at', '<', $before)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $requeued = 0;

        foreach ($queued as $noticeId) {
            $requeued += $notices->dispatchNotice($noticeId) ? 1 : 0;
        }

        $recipients = 0;

        foreach ($this->strandedNoticeIds($before) as $noticeId) {
            $pending = $notices->pendingRecipientIds($noticeId);

            try {
                $notices->dispatchSends($pending);
                $recipients += count($pending);
            } catch (\Throwable $e) {
                $this->error("Organization {$organizationId}: notice {$noticeId} could not be re-queued: {$e->getMessage()}");
            }
        }

        $this->line("Organization {$organizationId}: re-queued {$requeued} notice(s) and {$recipients} recipient send(s).");
        Log::info('KRI cycle watchdog redispatch', ['organization_id' => $organizationId, 'notices' => $requeued, 'recipients' => $recipients]);
    }

    /**
     * In-app, one direct insert per holder (see the class comment for why not
     * sendMany()). A failure here is logged and never masks the exit code.
     *
     * @param  list<string>  $failures
     */
    private function notifyHolders(int $organizationId, array $failures): void
    {
        try {
            $ids = User::permission('kri.send_reminder')
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->all();

            if ($ids === []) {
                Log::warning('KRI cycle watchdog found nobody in the organisation to tell; support alert only', ['organization_id' => $organizationId]);

                return;
            }

            foreach ($ids as $userId) {
                NotificationService::send(
                    organizationId: $organizationId,
                    userId: $userId,
                    type: 'kri_cycle_watchdog',
                    subject: 'The monthly KRI cycle needs attention',
                    body: implode("\n", $failures)."\nAtheris support has been notified.",
                    metadata: ['entity_type' => 'kri_cycle'],
                    actionUrl: '/risk/kri/returns',
                    priority: 'high',
                    category: 'kri',
                );
            }
        } catch (\Throwable $e) {
            Log::error('KRI watchdog could not notify the organisation', ['organization_id' => $organizationId, 'error' => $e->getMessage()]);
        }
    }
}

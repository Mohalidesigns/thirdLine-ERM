<?php

namespace App\Console\Commands;

use App\Models\KeyRiskIndicator;
use App\Models\KriCycleRun;
use App\Models\KriReturnNotice;
use App\Models\KriReturnNoticeRecipient;
use App\Models\Organization;
use App\Models\User;
use App\Services\Kri\KriCycleService;
use App\Services\NotificationService;
use App\Support\Kri\KriWorkingCalendar;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * kri:cycle-watchdog - did the monthly cycle actually open? (ADR 0026 section 12)
 *
 * For an active organisation with at least one active, non-automated KRI that
 * has a department, it is a FAILURE when:
 *   - Lagos today is on or after the first working day of this month and no
 *     `completed` kri_cycle_runs row exists for the reporting period; or
 *   - a run has been `running` for more than 60 minutes; or
 *   - a notice has been `queued` for more than 60 minutes with its recipients
 *     still unresolved.
 * It WARNS (no failure) on recipients that failed in the last 24 hours. On
 * failure it logs an error, notifies holders of kri.send_reminder, exits 1.
 */
class KriCycleWatchdog extends Command
{
    protected $signature = 'kri:cycle-watchdog {--organization= : Only this organization id}';

    protected $description = 'Fail loudly when the monthly KRI cycle did not open or its notices are stuck';

    public function handle(KriCycleService $cycle, KriWorkingCalendar $calendar): int
    {
        $only = $this->option('organization');

        $organizationIds = TenantContext::bypass(
            fn () => Organization::query()
                ->where('is_active', true)
                ->when($only !== null, fn ($q) => $q->whereKey($only))
                ->orderBy('id')
                ->pluck('id')
                ->all(),
            'kri:cycle-watchdog organization sweep'
        );

        $failed = false;

        foreach ($organizationIds as $organizationId) {
            $problems = TenantContext::actingAs((int) $organizationId, fn () => $this->check((int) $organizationId, $cycle, $calendar));

            foreach ($problems['warnings'] as $warning) {
                $this->warn("Organization {$organizationId}: {$warning}");
            }

            if ($problems['failures'] === []) {
                continue;
            }

            $failed = true;

            foreach ($problems['failures'] as $failure) {
                $this->error("Organization {$organizationId}: {$failure}");
                logger()->error('KRI cycle watchdog', ['organization_id' => $organizationId, 'problem' => $failure]);
            }

            TenantContext::actingAs((int) $organizationId, fn () => $this->notifyHolders((int) $organizationId, $problems['failures']));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
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

        if ($today->toDateString() >= $first->toDateString()) {
            $period = $cycle->reportingPeriodFor($today, $organizationId);

            $completed = KriCycleRun::query()
                ->where('period_id', $period->id)
                ->where('status', KriCycleRun::STATUS_COMPLETED)
                ->exists();

            if (! $completed) {
                $out['failures'][] = "The KRI cycle for {$period->name} has not completed (first working day was {$first->toDateString()}).";
            }
        }

        $stuckRuns = KriCycleRun::query()
            ->where('status', KriCycleRun::STATUS_RUNNING)
            ->where('started_at', '<', now()->subMinutes(60))
            ->count();

        if ($stuckRuns > 0) {
            $out['failures'][] = "{$stuckRuns} KRI cycle run(s) have been running for more than 60 minutes.";
        }

        $stuckNotices = KriReturnNotice::query()
            ->where('status', KriReturnNotice::STATUS_QUEUED)
            ->whereNull('recipients_resolved_at')
            ->where('queued_at', '<', now()->subMinutes(60))
            ->count();

        if ($stuckNotices > 0) {
            $out['failures'][] = "{$stuckNotices} KRI notice(s) have been queued for more than 60 minutes without being sent.";
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

    /** @param list<string> $failures */
    private function notifyHolders(int $organizationId, array $failures): void
    {
        try {
            $ids = User::permission('kri.send_reminder')
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($ids === []) {
                return;
            }

            NotificationService::sendMany(
                organizationId: $organizationId,
                userIds: $ids,
                type: 'kri_cycle_watchdog',
                subject: 'The monthly KRI cycle needs attention',
                body: implode("\n", $failures),
                metadata: ['entity_type' => 'kri_cycle'],
                actionUrl: '/risk/kri/returns',
                priority: 'high',
                category: 'kri',
            );
        } catch (\Throwable $e) {
            logger()->warning('KRI watchdog could not notify', ['error' => $e->getMessage()]);
        }
    }
}

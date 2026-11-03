<?php

namespace App\Console\Commands;

use App\Models\KriCycleRun;
use App\Models\Organization;
use App\Services\Kri\KriCycleService;
use App\Support\Kri\KriWorkingCalendar;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * kri:open-cycle - open the departmental monthly KRI cycle (ADR 0026 section 12).
 *
 * Scheduled DAILY at 07:00 Africa/Lagos, never monthlyOn(1): the 1st is often not
 * a working day. Without --force it acts only on the first working day of the
 * month. Idempotency comes from rows, not from the cron: a re-run, a forced run
 * or two servers cannot open a second return (unique key) or send a second
 * request (unique idempotency_key). --force never means "send again".
 */
class OpenKriCycle extends Command
{
    protected $signature = 'kri:open-cycle
                            {--date= : Act as though today (Africa/Lagos) were this Y-m-d}
                            {--force : Open even if that date is not the first working day of its month}
                            {--dry-run : Report departments, KRIs due and resolved champions; write nothing, send nothing}
                            {--organization= : Only this organization id}';

    protected $description = 'Open the monthly departmental KRI return cycle';

    public function handle(KriCycleService $cycle): int
    {
        $date = $this->option('date');

        try {
            $day = ($date !== null && $date !== '') ? KriWorkingCalendar::day((string) $date) : KriWorkingCalendar::today();
        } catch (\Throwable) {
            $this->error('--date must be a real calendar date written Y-m-d (for example 2026-11-02).');

            return self::INVALID;
        }

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
            'kri:open-cycle organization sweep'
        );

        // An operator replaying a missed cycle for one tenant must not be told
        // "success" because the id was wrong or the tenant is inactive.
        if ($only !== null && $organizationIds === []) {
            $this->error("Organization {$only} does not exist or is not active.");

            return self::FAILURE;
        }

        $failed = false;
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $trigger = ($force || ($date !== null && $date !== '')) ? 'manual' : 'schedule';

        foreach ($organizationIds as $organizationId) {
            // ONE TENANT'S FAILURE IS NOT EVERY TENANT'S. Before this, a throw
            // before the run row existed (a period that would not resolve, a
            // dropped connection) escaped the loop and every organisation after
            // it was silently never opened that month. The failing tenant has no
            // `completed` run, so kri:cycle-watchdog reports it at 10:00.
            try {
                $report = TenantContext::actingAs((int) $organizationId, fn () => $cycle->open(
                    (int) $organizationId,
                    $day,
                    $force,
                    $dryRun,
                    $trigger,
                ));
            } catch (\Throwable $e) {
                report($e);
                $this->error("Organization {$organizationId} ({$day->toDateString()}): failed - {$e->getMessage()}");
                $failed = true;

                continue;
            }

            $this->line("Organization {$organizationId} ({$day->toDateString()}): {$report['status']}".($report['reason'] ? " - {$report['reason']}" : ''));

            if ($report['period'] !== null) {
                $this->line("  Reporting period: {$report['period']->name}");
            }

            if ($dryRun) {
                foreach ($report['departments'] as $department) {
                    $champions = $department['champions'] === [] ? 'NO CHAMPION' : implode(', ', $department['champions']);
                    $this->line("  - {$department['name']}: {$department['kris_due']} KRIs due; champions: {$champions}");
                }
            } elseif ($report['status'] !== 'skipped') {
                $this->line("  {$report['departments_due']} departments due, {$report['returns_opened']} returns opened, {$report['requests_queued']} requests queued, {$report['requests_redispatched']} stuck requests re-queued, {$report['departments_without_champion']} without a champion.");
            }

            if (($report['requests_not_queued'] ?? 0) > 0) {
                // Persisted, not lost: the rows are `queued` and the watchdog
                // reports them. Exit non-zero so the scheduler log shows it.
                $this->error("  {$report['requests_not_queued']} request(s) are saved but could not be handed to the queue. Run `kri:cycle-watchdog --organization={$organizationId} --redispatch` once the queue is back.");
                $failed = true;
            }

            foreach ($report['errors'] as $error) {
                $this->error("  {$error}");
            }

            if ($report['status'] === KriCycleRun::STATUS_FAILED) {
                $failed = true;
            }
        }

        if (config('mail.default') === 'log') {
            // The known go-live gap. A green run means the mailer accepted the
            // message, and the log driver accepts everything.
            $this->warn('MAIL_MAILER is `log`: requests go to the log file, not to anybody.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

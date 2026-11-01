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
            $day = $date ? KriWorkingCalendar::day((string) $date) : KriWorkingCalendar::today();
        } catch (\Throwable) {
            $this->error('--date must be a valid Y-m-d.');

            return self::INVALID;
        }

        $only = $this->option('organization');

        $organizationIds = TenantContext::bypass(
            fn () => Organization::query()
                ->where('is_active', true)
                ->when($only !== null, fn ($q) => $q->whereKey($only))
                ->orderBy('id')
                ->pluck('id')
                ->all(),
            'kri:open-cycle organization sweep'
        );

        $failed = false;
        $dryRun = (bool) $this->option('dry-run');

        foreach ($organizationIds as $organizationId) {
            $report = TenantContext::actingAs((int) $organizationId, fn () => $cycle->open(
                (int) $organizationId,
                $day,
                (bool) $this->option('force'),
                $dryRun,
                $this->option('force') || $date ? 'manual' : 'schedule',
            ));

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
                $this->line("  {$report['departments_due']} departments due, {$report['returns_opened']} returns opened, {$report['requests_queued']} requests queued, {$report['departments_without_champion']} without a champion.");
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

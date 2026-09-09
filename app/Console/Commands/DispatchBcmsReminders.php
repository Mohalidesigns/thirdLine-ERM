<?php

namespace App\Console\Commands;

use App\Models\Bcms\ReminderSchedule;
use App\Models\Organization;
use App\Services\Bcms\Reminders\ReadinessService;
use App\Services\Bcms\Reminders\ReminderDispatcher;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The hourly tick that sends the T-10 countdown.
 *
 * PHASE 0 SHIPPED THE SKELETON; PHASE 5 FILLED IT IN. The scheduler
 * registration, the tenant loop and the claim semantics were settled early
 * because three tracks depend on them, and discovering in Week 6 that the
 * hourly tick was never wired is a week nobody has.
 *
 * THE CLAIM IS THE WHOLE DESIGN, and it is why this is a skeleton rather than
 * nothing. `bcms_reminder_schedules.idempotency_key` is UNIQUE in the database
 * and `status` moves `pending → sent` under a conditional update, so two
 * workers racing on the same tick have one of them update zero rows. Gate G1
 * states it as "a dispatcher re-run sends nothing twice", and that has to be a
 * property of the data rather than of the query (ADR 0005).
 *
 * IT RUNS PER TENANT, EXPLICITLY. `TenantContext` is not resolved for a console
 * command, so the loop sets it per organisation. A dispatcher that ran once
 * with no tenant would either see nothing or, worse, see everything.
 */
class DispatchBcmsReminders extends Command
{
    protected $signature = 'bcms:dispatch-reminders
                            {--organization= : Restrict to one organisation id}
                            {--dry-run : Report what is due and claim nothing}';

    protected $description = 'Dispatch due BCMS exercise reminders (T-10 countdown ladder).';

    public function handle(ReminderDispatcher $dispatcher, ReadinessService $readiness): int
    {
        $totals = [];

        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $totalDue = 0;

        foreach ($organizations as $organization) {
            TenantContext::set($organization->id);

            try {
                if ($this->option('dry-run')) {
                    $due = ReminderSchedule::query()
                        ->where('status', 'pending')
                        ->where('send_at', '<=', now())
                        ->count();

                    $totalDue += $due;

                    if ($due > 0) {
                        $this->line(sprintf('%s: %d reminder(s) due.', $organization->name, $due));
                    }

                    continue;
                }

                // Overdue readiness tasks are marked before the ladder is
                // dispatched, because the daily digest's content is "3 of 7
                // outstanding" and an item that went overdue overnight has to
                // read as overdue in the morning's message rather than in
                // tomorrow's.
                $readiness->markOverdue();

                $result = $dispatcher->dispatchDue();

                $totalDue += $result['claimed'];

                foreach ($result as $key => $value) {
                    $totals[$key] = ($totals[$key] ?? 0) + $value;
                }

                if ($result['claimed'] > 0 || $result['deferred'] > 0) {
                    $this->line(sprintf(
                        '%s: %d claimed, %d messages sent to %d people (%d consolidated digests), %d deferred, %d skipped.',
                        $organization->name,
                        $result['claimed'],
                        $result['sent'],
                        $result['recipients'],
                        $result['digests'],
                        $result['deferred'],
                        $result['skipped'],
                    ));
                }
            } finally {
                TenantContext::clear();
            }
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                '%d reminder(s) due across %d organisation(s). Nothing was claimed.',
                $totalDue,
                $organizations->count(),
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d claimed, %d sent, %d deferred for quiet hours, %d skipped across %d organisation(s).',
            $totals['claimed'] ?? 0,
            $totals['sent'] ?? 0,
            $totals['deferred'] ?? 0,
            $totals['skipped'] ?? 0,
            $organizations->count(),
        ));

        return self::SUCCESS;
    }
}

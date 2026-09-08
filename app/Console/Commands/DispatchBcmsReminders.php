<?php

namespace App\Console\Commands;

use App\Models\Bcms\ReminderSchedule;
use App\Models\Organization;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The hourly tick that sends the T-10 countdown.
 *
 * PHASE 0 SHIPS THE SKELETON ONLY. The dispatch logic is Phase 5's and this
 * command exists now for one reason: the scheduler registration, the tenant
 * loop and the claim semantics are infrastructure three tracks depend on, and
 * discovering in Week 6 that the hourly tick was never wired is a week nobody
 * has. What it does today is find due rows and report them; what it must never
 * do is send twice.
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

    public function handle(): int
    {
        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $totalDue = 0;

        foreach ($organizations as $organization) {
            TenantContext::set($organization->id);

            try {
                $due = ReminderSchedule::query()
                    ->where('status', 'pending')
                    ->where('send_at', '<=', now())
                    ->orderBy('send_at')
                    ->get();

                $totalDue += $due->count();

                if ($due->isNotEmpty()) {
                    $this->line(sprintf(
                        '%s: %d reminder(s) due.',
                        $organization->name,
                        $due->count()
                    ));
                }

                // PHASE 5 LANDS HERE: claim each row, resolve its audience
                // through AudienceResolver, render per contact language,
                // write-ahead a delivery row, and hand the send to the
                // bcms-reminders queue. Nothing is dispatched in Phase 0 —
                // shipping a half-dispatcher that sends some traffic would be
                // worse than shipping none.
            } finally {
                TenantContext::clear();
            }
        }

        $this->info(sprintf(
            '%d reminder(s) due across %d organisation(s). Dispatch lands in Phase 5.',
            $totalDue,
            $organizations->count()
        ));

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Rcsa\RcsaActionPlan;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The nightly sweep behind §9.3: mark what has gone overdue, and remind the
 * owners of what is about to.
 *
 * REMINDERS FIRE AT EXACTLY T-14, T-7 AND T-0, and that exactness is what makes
 * the command idempotent without a "last reminded" column. A plan due in nine
 * days matches nothing; the day it is seven days out it matches once. Running
 * the sweep twice in one day sends the same reminder twice — which is a
 * duplicate notification, not a wrong one — and running it not at all on a
 * Sunday means one reminder is missed rather than the register drifting.
 *
 * OVERDUE IS ANNOUNCED ONCE, at the moment the status flips, because the flip
 * can only happen once. A daily "still overdue" is how a register teaches its
 * owners to filter it into a folder they never open, and §9.3 asks for
 * "reminders at T-14/T-7/T-0/overdue" — four events, not three plus a drip.
 *
 * ONE PLAN MUST NOT TAKE THE SWEEP DOWN WITH IT. Each is marked and announced
 * inside its own transaction with its own catch — the lesson of
 * CheckOverdueTreatments, where an insert that threw on the first row meant the
 * nightly job marked exactly one plan overdue and died.
 *
 * IT RUNS WITHOUT TENANCY. There is no authenticated user in a scheduled
 * command, so the global organization scope has nothing to scope to; every
 * query here is deliberately unscoped and carries the plan's own
 * `organization_id` into the notification.
 */
class CheckRcsaActionPlans extends Command
{
    protected $signature = 'rcsa:check-action-plans {--dry-run : Report what would happen without writing or notifying}';

    protected $description = 'Mark overdue RCSA action plans and remind their owners at T-14, T-7 and T-0';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $marked = $this->markOverdue($dryRun);
        $reminded = $this->sendReminders($dryRun);

        $this->info(sprintf(
            '%s%d plan(s) marked overdue, %d reminder(s) sent.',
            $dryRun ? '[dry run] ' : '',
            $marked,
            $reminded,
        ));

        return self::SUCCESS;
    }

    /**
     * Everything past its date that nobody has finished.
     */
    private function markOverdue(bool $dryRun): int
    {
        $due = RcsaActionPlan::query()
            ->withoutGlobalScopes()
            ->with(['owner:id,name', 'line:id,risk_no,potential_risk'])
            ->whereNotNull('target_date')
            ->whereDate('target_date', '<', now()->toDateString())
            ->whereNotIn('status', RcsaActionPlan::SETTLED)
            ->where('status', '!=', RcsaActionPlan::OVERDUE)
            ->get();

        $marked = 0;

        foreach ($due as $plan) {
            if ($dryRun) {
                $this->line("Would mark #{$plan->id} overdue ({$plan->target_date?->toDateString()}).");
                $marked++;

                continue;
            }

            try {
                DB::transaction(function () use ($plan, &$marked) {
                    $plan->forceFill(['status' => RcsaActionPlan::OVERDUE])->save();

                    // Negated, because daysUntilDue() is negative once the date
                    // has passed. Carbon 3 made $absolute default to false and
                    // this is the sign that CheckOverdueTreatments got wrong.
                    $this->announceOverdue($plan, -1 * (int) $plan->daysUntilDue());

                    $marked++;
                });

                $this->line("Action plan #{$plan->id} is overdue.");
            } catch (\Throwable $e) {
                logger()->error('RCSA action-plan sweep failed for one plan', [
                    'action_plan_id' => $plan->id,
                    'error' => $e->getMessage(),
                ]);

                $this->warn("Plan #{$plan->id} could not be processed: {$e->getMessage()}");
            }
        }

        return $marked;
    }

    /**
     * T-14, T-7 and T-0.
     */
    private function sendReminders(bool $dryRun): int
    {
        $sent = 0;

        foreach (RcsaActionPlan::REMINDER_DAYS as $days) {
            $target = now()->addDays($days)->toDateString();

            $plans = RcsaActionPlan::query()
                ->withoutGlobalScopes()
                ->with(['owner:id,name', 'line:id,risk_no,potential_risk'])
                ->whereDate('target_date', $target)
                ->whereNotIn('status', RcsaActionPlan::SETTLED)
                ->get();

            foreach ($plans as $plan) {
                if ($plan->owner_id === null) {
                    // Not an error: a plan with no owner has nobody to remind.
                    // It is still swept for overdue above.
                    continue;
                }

                if ($dryRun) {
                    $this->line("Would remind user {$plan->owner_id} about #{$plan->id} (T-{$days}).");
                    $sent++;

                    continue;
                }

                try {
                    NotificationService::send(
                        organizationId: (int) $plan->organization_id,
                        userId: (int) $plan->owner_id,
                        type: 'rcsa.actionplan.reminder',
                        subject: $days === 0
                            ? sprintf('Due today: %s', $this->label($plan))
                            : sprintf('Due in %d days: %s', $days, $this->label($plan)),
                        body: sprintf(
                            'Your RCSA action plan for %s is due on %s. %s',
                            $this->riskNo($plan),
                            $plan->target_date?->toDateString(),
                            Str::limit((string) $plan->control_to_implement, 200),
                        ),
                        metadata: ['action_plan_id' => $plan->id, 'line_id' => $plan->line_id],
                        actionUrl: route('rcsa.action-plans.index', absolute: false),
                        priority: $days === 0 ? 'high' : 'medium',
                        category: 'workflow',
                    );

                    $sent++;
                } catch (\Throwable $e) {
                    logger()->error('RCSA action-plan reminder failed', [
                        'action_plan_id' => $plan->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $sent;
    }

    private function announceOverdue(RcsaActionPlan $plan, int $daysOverdue): void
    {
        if ($plan->owner_id === null) {
            logger()->info('Overdue RCSA action plan has no owner to notify', [
                'action_plan_id' => $plan->id,
                'organization_id' => $plan->organization_id,
            ]);

            return;
        }

        NotificationService::send(
            organizationId: (int) $plan->organization_id,
            userId: (int) $plan->owner_id,
            type: 'rcsa.actionplan.overdue',
            subject: sprintf('Overdue: %s', $this->label($plan)),
            body: sprintf(
                'Your RCSA action plan for %s was due on %s — %d day%s ago. Close it, or ask for an extension.',
                $this->riskNo($plan),
                $plan->target_date?->toDateString(),
                $daysOverdue,
                $daysOverdue === 1 ? '' : 's',
            ),
            metadata: ['action_plan_id' => $plan->id, 'line_id' => $plan->line_id],
            actionUrl: route('rcsa.action-plans.index', ['overdue' => 1], absolute: false),
            priority: 'high',
            category: 'workflow',
        );
    }

    private function label(RcsaActionPlan $plan): string
    {
        return Str::limit((string) $plan->control_to_implement, 60);
    }

    /**
     * The risk number the plan hangs off, defensively.
     *
     * An explicit null check rather than `?->risk_no ?? '...'`: larastan types
     * a `belongsTo` as non-nullable and rejects the nullsafe as dead code,
     * while the relation genuinely is null when a caller has not eager-loaded
     * it. This ends up in text somebody reads, so a fallback beats a fatal —
     * the same shape RcsaSubmissionService uses for the same reason.
     */
    private function riskNo(RcsaActionPlan $plan): string
    {
        $line = $plan->getRelationValue('line');

        return $line === null ? 'a risk' : (string) $line->risk_no;
    }
}

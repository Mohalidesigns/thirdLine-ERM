<?php

namespace App\Console\Commands;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaCycle;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * §11's remaining notification schedule: cycle due dates.
 *
 * P5 built the action-plan reminders. This is the other half — telling a
 * business unit its RCSA is due before it is late, rather than telling the
 * Head of ORM afterwards that it was.
 *
 * SAME MILESTONE SHAPE AS THE ACTION-PLAN SWEEP, and for the same reason:
 * reminders fire at exactly T-14, T-7 and T-0, so running the command twice in
 * a day sends a duplicate rather than a wrong answer, and no "last reminded"
 * column is needed to make it idempotent. Overdue is announced once a week
 * rather than daily — an assessment that is late stays late for a fortnight,
 * and a daily mail about it is how a unit learns to filter the sender.
 *
 * ONLY UNFINISHED ASSESSMENTS ARE CHASED. A unit that has already submitted has
 * done what the due date asked of it; reminding them anyway is the fastest way
 * to make the reminder mean nothing.
 *
 * IT RUNS WITHOUT TENANCY, like every scheduled command here: there is no
 * authenticated user, so the global organization scope has nothing to scope to
 * and every query is deliberately unscoped, carrying the row's own
 * `organization_id` into the notification.
 */
class CheckRcsaCycleDeadlines extends Command
{
    protected $signature = 'rcsa:check-cycle-deadlines {--dry-run : Report what would be sent without sending it}';

    protected $description = 'Remind business units whose RCSA is due at T-14, T-7 and T-0, and weekly once overdue';

    /**
     * Days before the due date at which a unit is reminded.
     *
     * @var list<int>
     */
    public const REMINDER_DAYS = [14, 7, 0];

    /** Once overdue, chase weekly rather than daily. */
    public const OVERDUE_EVERY_DAYS = 7;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $cycles = RcsaCycle::query()
            ->withoutGlobalScopes()
            ->whereIn('status', [RcsaCycle::OPEN, RcsaCycle::IN_REVIEW])
            ->whereNotNull('due_date')
            ->get();

        $sent = 0;

        foreach ($cycles as $cycle) {
            $daysToDue = (int) round(now()->startOfDay()->diffInDays($cycle->due_date->startOfDay(), false));

            if (! $this->isMilestone($daysToDue)) {
                continue;
            }

            $sent += $this->remind($cycle, $daysToDue, $dryRun);
        }

        $this->info(sprintf('%s%d reminder(s) sent.', $dryRun ? '[dry run] ' : '', $sent));

        return self::SUCCESS;
    }

    /**
     * T-14, T-7, T-0, then every seventh day after.
     */
    private function isMilestone(int $daysToDue): bool
    {
        if (in_array($daysToDue, self::REMINDER_DAYS, true)) {
            return true;
        }

        return $daysToDue < 0 && abs($daysToDue) % self::OVERDUE_EVERY_DAYS === 0;
    }

    private function remind(RcsaCycle $cycle, int $daysToDue, bool $dryRun): int
    {
        $unfinished = RcsaAssessment::query()
            ->withoutGlobalScopes()
            ->where('cycle_id', $cycle->id)
            // The states in which the unit still owes something. `bu_approval`
            // is here: it is with the head rather than the champion, but the
            // unit has not finished until it reaches the second line.
            ->whereIn('status', [
                RcsaAssessment::DRAFT,
                RcsaAssessment::IN_PROGRESS,
                RcsaAssessment::RETURNED,
                RcsaAssessment::BU_APPROVAL,
            ])
            ->with(['businessUnit:id,name,head_id', 'assignee:id,name'])
            ->get();

        $sent = 0;

        foreach ($unfinished as $assessment) {
            $recipients = $this->recipientsFor($assessment);

            if ($recipients === []) {
                // A real condition, not an error: a unit with nobody assigned
                // and no head has nobody to remind. It still shows as behind on
                // the completion tracker, which is where the Head of ORM sees
                // it.
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    'Would remind %d user(s) about %s (%s), %s.',
                    count($recipients),
                    $this->unitName($assessment, 'a unit'),
                    $cycle->name,
                    $daysToDue < 0 ? abs($daysToDue).' days overdue' : "T-{$daysToDue}",
                ));

                $sent += count($recipients);

                continue;
            }

            try {
                NotificationService::sendMany(
                    organizationId: (int) $assessment->organization_id,
                    userIds: $recipients,
                    type: 'rcsa.cycle.due',
                    subject: $daysToDue < 0
                        ? sprintf('%s is overdue', $cycle->name)
                        : ($daysToDue === 0
                            ? sprintf('%s is due today', $cycle->name)
                            : sprintf('%s is due in %d days', $cycle->name, $daysToDue)),
                    body: sprintf(
                        '%s is %d%% complete for %s and was due %s. %s',
                        $this->unitName($assessment, 'Your unit'),
                        $assessment->completion_pct,
                        $cycle->name,
                        $cycle->due_date?->toDateString(),
                        $daysToDue < 0
                            ? 'It is now '.abs($daysToDue).' days late.'
                            : 'Finish and submit it before then.',
                    ),
                    metadata: ['assessment_id' => $assessment->id, 'cycle_id' => $cycle->id],
                    actionUrl: route('rcsa.assessments.show', $assessment, absolute: false),
                    priority: $daysToDue <= 0 ? 'high' : 'medium',
                    category: 'workflow',
                );

                $sent += count($recipients);
            } catch (\Throwable $e) {
                // One unit must not take the sweep down with it — the lesson of
                // CheckOverdueTreatments, where an insert that threw on the
                // first row meant the nightly job processed exactly one.
                logger()->error('RCSA cycle deadline reminder failed for one assessment', [
                    'assessment_id' => $assessment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * The unit's name, defensively.
     *
     * An explicit null check rather than `?->name ?? '...'`: larastan types a
     * `belongsTo` as non-nullable and rejects the nullsafe as dead code, while
     * the relation genuinely is null when it has not been loaded. This ends up
     * in text somebody reads, so a fallback beats a fatal.
     */
    private function unitName(RcsaAssessment $assessment, string $fallback): string
    {
        $unit = $assessment->getRelationValue('businessUnit');

        return $unit === null ? $fallback : (string) $unit->name;
    }

    /**
     * Whoever owes the work: the assignee, the unit's head, and whoever filed
     * it last time it came back.
     *
     * @return list<int>
     */
    private function recipientsFor(RcsaAssessment $assessment): array
    {
        $unit = $assessment->getRelationValue('businessUnit');

        $ids = array_filter([
            $assessment->assigned_to,
            $unit?->head_id,
            // A returned assessment is owed by whoever submitted it before.
            $assessment->status === RcsaAssessment::RETURNED ? $assessment->submitted_by : null,
        ]);

        if ($ids === []) {
            // Nobody named. Fall back to the users assigned to this business
            // unit who can actually complete an assessment — which is what the
            // P7 assignment table is for.
            $ids = User::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $assessment->organization_id)
                ->where('is_active', true)
                ->whereIn('id', fn ($q) => $q
                    ->select('user_id')
                    ->from('business_unit_user')
                    ->where('business_unit_id', $assessment->business_unit_id))
                ->get()
                ->filter(fn (User $user) => $user->can('rcsa_assessment.complete'))
                ->pluck('id')
                ->all();
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}

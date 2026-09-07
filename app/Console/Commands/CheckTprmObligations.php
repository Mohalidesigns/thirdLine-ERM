<?php

namespace App\Console\Commands;

use App\Enums\Tprm\ObligationStatus;
use App\Models\Tprm\Obligation;
use App\Services\NotificationService;
use App\Services\Tprm\Contracts\ObligationService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The nightly obligation sweep — FR-CTR-06's "recurring obligations generate
 * tasks on schedule".
 *
 * THREE THINGS HAPPEN AND THEY ARE DELIBERATELY SEPARATE.
 *
 *   Reminders at T-30, T-7 and T-0. Exact days, so the sweep is idempotent
 *   without a "last reminded" column — the reasoning the other sweeps in this
 *   codebase carry.
 *
 *   `pending` becomes `due` the day the date arrives. That is a state change,
 *   not a notification, and it is what makes the register's "due" count mean
 *   something at nine in the morning.
 *
 *   `due` becomes `breached` after a grace period, and the breach advances the
 *   duty to its next occurrence. Without the advance, a missed quarterly
 *   review would report the same breach every night and the breach count would
 *   depend on how often the job ran rather than on how often the duty was
 *   missed.
 *
 * OUR OWN OBLIGATIONS ARE CHASED AS HARD AS THE VENDOR'S. The duties an
 * institution is found to have breached are almost always its own, and a sweep
 * that only chased the provider would be the tool quietly agreeing with the
 * habit that causes the problem.
 */
class CheckTprmObligations extends Command
{
    protected $signature = 'tprm:check-obligations
        {--dry-run : Report what would happen without writing or notifying}
        {--grace=7 : Days past the due date before an outstanding obligation is recorded as breached}';

    protected $description = 'Advance obligation states, remind owners at T-30/T-7/T-0, and record breaches past the grace period';

    /** @var list<int> */
    private const REMINDERS = [30, 7, 0];

    public function handle(ObligationService $obligations): int
    {
        if (! config('features.tprm')) {
            $this->comment('TPRM is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $grace = max(0, (int) $this->option('grace'));

        $marked = $dryRun ? $this->countDue() : $obligations->markDue();
        $reminded = $this->sendReminders($dryRun);
        $breached = $dryRun ? $this->countBreachable($grace) : $obligations->breachOverdue($grace);

        $this->info(sprintf(
            '%s%d marked due, %d reminder(s) sent, %d recorded as breached.',
            $dryRun ? '[dry run] ' : '',
            $marked,
            $reminded,
            $breached,
        ));

        return self::SUCCESS;
    }

    private function sendReminders(bool $dryRun): int
    {
        $sent = 0;

        foreach (self::REMINDERS as $days) {
            $due = Obligation::query()
                ->withoutGlobalScopes()
                ->outstanding()
                ->whereNotNull('next_due_date')
                ->whereDate('next_due_date', now()->addDays($days)->toDateString())
                ->with(['owner:id,name', 'engagement:id,reference,name'])
                ->get();

            foreach ($due as $obligation) {
                if ($obligation->owner_id === null) {
                    // An unowned duty is a duty nobody does, and it is worth
                    // saying so on the console rather than skipping quietly.
                    $this->warn(sprintf(
                        'Obligation #%d (%s) falls due in %d days and has no owner.',
                        $obligation->getKey(),
                        Str::limit($obligation->title, 60),
                        $days,
                    ));

                    continue;
                }

                if ($dryRun) {
                    $sent++;

                    continue;
                }

                try {
                    NotificationService::send(
                        organizationId: (int) $obligation->organization_id,
                        userId: (int) $obligation->owner_id,
                        type: 'tprm.obligation.reminder',
                        subject: $days === 0
                            ? sprintf('Due today: %s', Str::limit($obligation->title, 70))
                            : sprintf('Due in %d days: %s', $days, Str::limit($obligation->title, 70)),
                        body: sprintf(
                            '%s — %s. Due %s under %s.%s%s',
                            $obligation->engagement->reference ?? 'Engagement',
                            $obligation->title,
                            $obligation->next_due_date?->toDateString(),
                            $obligation->citation ?: ($obligation->source_reference ?: 'the contract'),
                            $obligation->evidence_required
                                ? ' Evidence is required to close this one.'
                                : '',
                            $obligation->obligor === Obligation::OBLIGOR_ENTITY
                                // Named explicitly, because the commonest
                                // reason one of these is missed is a reader
                                // assuming the vendor owes it.
                                ? ' This is an obligation on us, not on the provider.'
                                : ' This is an obligation on the provider — chase it rather than performing it.',
                        ),
                        metadata: [
                            'obligation_id' => $obligation->getKey(),
                            'engagement_id' => $obligation->engagement_id,
                            'obligor' => $obligation->obligor,
                            'days_remaining' => $days,
                        ],
                        actionUrl: route('tprm.obligations.index', absolute: false),
                        priority: $days === 0 ? 'high' : 'medium',
                        category: 'workflow',
                    );

                    $sent++;
                } catch (\Throwable $exception) {
                    logger()->error('TPRM obligation reminder failed', [
                        'obligation_id' => $obligation->getKey(),
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return $sent;
    }

    private function countDue(): int
    {
        return Obligation::query()
            ->withoutGlobalScopes()
            ->where('status', ObligationStatus::Pending->value)
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', now()->toDateString())
            ->count();
    }

    private function countBreachable(int $grace): int
    {
        return Obligation::query()
            ->withoutGlobalScopes()
            ->where('status', ObligationStatus::Due->value)
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<', now()->subDays($grace)->toDateString())
            ->count();
    }
}

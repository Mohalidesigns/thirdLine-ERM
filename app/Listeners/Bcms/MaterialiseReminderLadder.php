<?php

namespace App\Listeners\Bcms;

use App\Events\Bcms\ExerciseOccurrenceScheduled;
use App\Services\Bcms\Reminders\ReadinessService;
use App\Services\Bcms\Reminders\ReminderScheduleBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * The seam between Track B's two halves.
 *
 * PHASE 4 EMITS, PHASE 5 LISTENS, AND NEITHER KNOWS ABOUT THE OTHER. The
 * generator's job is to place dates; arming a countdown is a different concern
 * owned by a different track (Orchestration §5). Until this listener existed,
 * Phase 4 was complete and correct and sent nothing — which is exactly what a
 * seam is for.
 *
 * IT DOES TWO THINGS AND THEY BELONG TOGETHER. An occurrence with a date needs
 * its reminder ladder AND its readiness checklist: the countdown's daily
 * content is "3 of 7 readiness items outstanding", so a ladder without a
 * checklist would send six days of "nothing to report".
 *
 * IT NEVER FAILS THE THING THAT TRIGGERED IT. Generating a year's calendar must
 * not roll back because one occurrence's ladder could not be written; the
 * failure is logged and the watchdog finds an occurrence with no reminder rows.
 * ADR 0005 calls that an incomplete generation — a defect to report rather than
 * a reason to lose the calendar.
 */
class MaterialiseReminderLadder
{
    public function __construct(
        private readonly ReminderScheduleBuilder $builder,
        private readonly ReadinessService $readiness,
    ) {}

    public function handle(ExerciseOccurrenceScheduled $event): void
    {
        try {
            $this->readiness->materialise($event->occurrence);
            $this->builder->build($event->occurrence, $event->definition);
        } catch (\Throwable $e) {
            Log::error('BCMS could not arm the reminder ladder for an occurrence', [
                'occurrence_id' => $event->occurrence->getKey(),
                'definition_id' => $event->definition->getKey(),
                'exception' => get_class($e),
                ...$this->failureDiagnostics($e),
            ]);
        }
    }

    /**
     * Bounded, value-free context for a failed materialisation — never
     * `$e->getMessage()`.
     *
     * CHECKED, NOT ASSUMED SAFE. `ReadinessService::materialise()` writes
     * only `ReadinessTask` rows — a template-authored title/description, an
     * `owner_id` (a user id, not a name), dates and status — and
     * `ReminderScheduleBuilder::build()` writes only `ReminderSchedule` rows
     * — an `audience_rule` SELECTOR (an org node, site or role from ADR
     * 0003's grammar, resolved to actual people later, by
     * `ReminderAudienceResolver`, which this listener never calls), a
     * channel set, template key and timestamps. Neither write carries a
     * name, mobile number or email on THIS path, so there is no known
     * address or contact value for a `QueryException` here to have bound
     * into its SQL today.
     *
     * The fix is applied anyway, for the same reason `getMessage()` is
     * refused everywhere else in this phase: a `QueryException`'s message
     * inlines every bound value regardless of which columns happen to hold
     * them, so the safety of this catch block would otherwise depend on
     * nobody ever adding a contact-bearing column to either service without
     * revisiting it. Logging only the SQLSTATE and driver-specific error
     * code — `errorInfo[2]`, the driver's own message text, is deliberately
     * never read — costs nothing today and removes that dependency.
     *
     * @return array<string, mixed>
     */
    private function failureDiagnostics(\Throwable $e): array
    {
        if (! $e instanceof QueryException) {
            return ['code' => $e->getCode() ?: null];
        }

        return [
            'sqlstate' => $e->getCode() ?: ($e->errorInfo[0] ?? null),
            'driver_error_code' => $e->errorInfo[1] ?? null,
        ];
    }
}

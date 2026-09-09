<?php

namespace App\Listeners\Bcms;

use App\Events\Bcms\ExerciseOccurrenceScheduled;
use App\Services\Bcms\Reminders\ReadinessService;
use App\Services\Bcms\Reminders\ReminderScheduleBuilder;
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
                'message' => $e->getMessage(),
            ]);
        }
    }
}

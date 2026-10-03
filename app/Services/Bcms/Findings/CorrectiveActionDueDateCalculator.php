<?php

namespace App\Services\Bcms\Findings;

use App\Enums\Bcms\FindingSeverity;
use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\ExerciseOccurrence;
use App\Services\Bcms\Exercises\WorkingCalendar;
use Illuminate\Support\Carbon;

/**
 * The CAPA due-date rule for an action arising from an exercise (compliance
 * analyst, `phase-9-aar-clause-map.md` §3.3).
 *
 * `due_date = min(severity default, next occurrence of this definition minus
 * 5 working days)`, floored at `raised_at + 5 working days`.
 *
 * WHY THE FLOOR MATTERS. An action the next exercise is supposed to validate
 * must be due BEFORE that exercise, or "items to validate" arrives already
 * meaningless — but an occurrence booked for next week must not produce a
 * due date three days ago. The floor is what keeps the rule honest at the
 * edges rather than only in the common case.
 *
 * THIS IS PHASE 9's OWN CALCULATOR, NOT A CHANGE TO PHASE 1's SERVICE. Phase 1
 * (`CorrectiveActionService::create()`) accepts a `due_date` attribute; this
 * class computes what Phase 9 should pass, and passes it at its own API
 * boundary — the finding/action inline forms on the AAR builder — without
 * touching the shared service or its controller.
 *
 * `WorkingCalendar::addWorkingDays()` (Phase 4, extended for this phase) does
 * the actual arithmetic, per the analyst's explicit instruction: "do not add
 * days."
 */
class CorrectiveActionDueDateCalculator
{
    public function __construct(private WorkingCalendar $calendar) {}

    /**
     * The recommended due date for an action raised against `$occurrence`'s
     * exercise, at the given severity.
     */
    public function forOccurrence(ExerciseOccurrence $occurrence, FindingSeverity $severity, ?Carbon $raisedAt = null): Carbon
    {
        $raisedAt ??= now();
        $definition = $occurrence->definition;

        $severityDefault = $raisedAt->copy()->addDays($severity->defaultDueDays());
        $floor = $this->calendar->addWorkingDays($raisedAt->copy(), 5, $definition);

        $next = $this->nextOccurrence($occurrence);
        $candidate = $severityDefault;

        if ($next !== null) {
            $atDate = $next->scheduled_date ?? $next->scheduled_start;

            if ($atDate !== null) {
                $beforeNext = $this->calendar->addWorkingDays(Carbon::instance($atDate), -5, $definition);
                $candidate = $candidate->lt($beforeNext) ? $candidate : $beforeNext;
            }
        }

        return $candidate->lt($floor) ? $floor : $candidate;
    }

    /**
     * The next occurrence of the SAME definition, by sequence rather than by
     * date — `sequence_no` is unique per definition and assigned in
     * generation order, so it is the stable "what comes after this one"
     * ordering even for an occurrence the generator has not yet dated
     * (`needs_scheduling`).
     */
    private function nextOccurrence(ExerciseOccurrence $occurrence): ?ExerciseOccurrence
    {
        return ExerciseOccurrence::query()
            ->where('definition_id', $occurrence->definition_id)
            ->where('sequence_no', '>', $occurrence->sequence_no)
            ->whereNotIn('status', [OccurrenceStatus::Cancelled->value])
            ->orderBy('sequence_no')
            ->first();
    }
}

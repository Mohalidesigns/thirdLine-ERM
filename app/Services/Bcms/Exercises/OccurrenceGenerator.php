<?php

namespace App\Services\Bcms\Exercises;

use App\Enums\Bcms\DistributionMode;
use App\Enums\Bcms\OccurrenceStatus;
use App\Events\Bcms\ExerciseOccurrenceScheduled;
use App\Models\Bcms\BlackoutPeriod;
use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseOccurrence;
use App\Support\Bcms\PreferredWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Fire Drill × 2 per year" becomes two dated, conflict-free occurrences.
 *
 * THIS IS THE PRODUCT. Everything else in the module is a register somebody
 * else also ships; this is the sentence the module is sold on — a BC team
 * declares a rhythm and the system generates, distributes and governs the year.
 *
 * THE ALGORITHM, in the order it runs:
 *   1. Build the working-day set: the year minus weekends, minus this
 *      definition's applicable hard blackouts (`WorkingCalendar`).
 *   2. Split it into `frequency_per_year` equal segments — measured in WORKING
 *      days, so December's segment is shorter rather than emptier.
 *   3. In each segment, rank the candidate days by how well they fit
 *      `preferred_window`, and walk them in order.
 *   4. For each candidate, ask `ConflictDetector`. On a conflict, move to the
 *      next candidate IN THE SAME SEGMENT — never into the next one, because
 *      that is how four quarterly exercises silently become three in Q4.
 *   5. If the segment is exhausted, write the occurrence as `needs_scheduling`
 *      with the reason. **Nothing is ever silently dropped.**
 *   6. Emit `ExerciseOccurrenceScheduled` for each placed occurrence — this is
 *      what arms Phase 5's reminder ladder.
 *
 * IDEMPOTENT, AND CAREFULLY SO. Regenerating matches occurrences by
 * `sequence_no`, which is uniquely indexed against the definition, so a second
 * run updates rather than inserts. An occurrence that has STARTED, COMPLETED or
 * been CANCELLED is never moved or deleted — it is history, and a regeneration
 * that rewrote it would be rewriting the evidence an examiner asks for. Those
 * are counted as fixed points and the remaining occurrences are placed around
 * them.
 */
class OccurrenceGenerator
{
    /** Where a definition has no `preferred_window` start time. */
    public const DEFAULT_START_TIME = '09:00';

    public function __construct(
        private readonly WorkingCalendar $calendar,
        private readonly ConflictDetector $conflicts,
    ) {}

    /**
     * Work out what a generation would do, without writing anything.
     *
     * The wizard's "this will create 4 occurrences and 56 notifications" comes
     * from here. A preview that ran a different algorithm from the generator
     * would be a promise the product then breaks.
     *
     * @return array<string, mixed>
     */
    public function preview(ExerciseDefinition $definition): array
    {
        return $this->plan($definition);
    }

    /**
     * Generate, or regenerate, this definition's occurrences.
     *
     * @return array<string, mixed> the generation log, as stored on the definition
     */
    public function generate(ExerciseDefinition $definition, ?int $userId = null): array
    {
        $plan = $this->plan($definition);

        $log = DB::transaction(function () use ($definition, $plan, $userId) {
            $placed = [];

            foreach ($plan['occurrences'] as $entry) {
                $occurrence = ExerciseOccurrence::query()->firstOrNew([
                    'definition_id' => $definition->getKey(),
                    'sequence_no' => $entry['sequence_no'],
                ]);

                // History is not rewritten. An occurrence somebody has run, or
                // formally cancelled, is evidence.
                if ($occurrence->exists && $this->isFixed($occurrence)) {
                    continue;
                }

                $wasScheduled = $occurrence->exists ? $occurrence->scheduled_date?->toDateString() : null;

                $occurrence->fill([
                    'organization_id' => $definition->organization_id,
                    'scheduled_date' => $entry['date'],
                    'scheduled_start' => $entry['start'],
                    'scheduled_end' => $entry['end'],
                    // `status` is cast to the enum on the model, so this hands
                    // it a case rather than a string — the two are not equal and
                    // a string here would compare false everywhere downstream.
                    'status' => $entry['date'] === null
                        ? OccurrenceStatus::NeedsScheduling
                        : ($occurrence->exists ? $occurrence->status : OccurrenceStatus::Planned),
                    'site_id' => $definition->site_id,
                    'facilitator_id' => $definition->facilitator_id,
                    'iso_clause_ref' => $definition->iso_clause_ref,
                    'created_by' => $occurrence->exists ? $occurrence->created_by : ($userId ?? auth()->id()),
                    'updated_by' => $userId ?? auth()->id(),
                ]);

                // `originally_scheduled_date` is the first date this occurrence
                // was ever given, and it never changes afterwards — the board
                // pack's "how much did you move" question is asked against it.
                if ($occurrence->originally_scheduled_date === null && $entry['date'] !== null) {
                    $occurrence->originally_scheduled_date = $entry['date'];
                }

                // A regeneration that lands an occurrence somewhere new is a
                // machine decision, not a reschedule request, and it does not
                // touch `reschedule_count` — that counter is what a human moved.
                $occurrence->save();

                $placed[] = $occurrence;

                if ($entry['date'] !== null && $wasScheduled !== $entry['date']) {
                    ExerciseOccurrenceScheduled::dispatch($occurrence->refresh(), $definition);
                }
            }

            // Occurrences beyond the current frequency — the definition was
            // reduced from 4×/year to 2×/year. The unrun ones go; the ones that
            // have already happened stay, because they happened.
            $surplus = ExerciseOccurrence::query()
                ->where('definition_id', $definition->getKey())
                ->where('sequence_no', '>', $definition->frequency_per_year)
                ->get();

            $removed = 0;

            foreach ($surplus as $occurrence) {
                if ($this->isFixed($occurrence)) {
                    continue;
                }

                $occurrence->delete();
                $removed++;
            }

            $log = $plan['log'];
            $log['removed_surplus'] = $removed;
            $log['generated_at'] = now()->toIso8601String();
            $log['generated_by'] = $userId ?? auth()->id();

            $definition->forceFill([
                'generation_log' => $log,
                'updated_by' => $userId ?? auth()->id(),
            ])->save();

            return $log;
        });

        return $log;
    }

    /* ------------------------------------------------------------------ */
    /*  The algorithm */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{occurrences: list<array<string, mixed>>, log: array<string, mixed>}
     */
    private function plan(ExerciseDefinition $definition): array
    {
        $programme = $definition->exerciseProgramme;
        $year = (int) ($programme === null ? now()->year : $programme->year);
        $mode = DistributionMode::tryFrom((string) $definition->distribution_mode) ?? DistributionMode::Even;
        $window = PreferredWindow::fromJson($definition->preferred_window);

        $calendar = $this->calendar->build(
            $year,
            BlackoutPeriod::query()->where('is_active', true)->get(),
            $definition,
        );

        $frequency = max(1, (int) $definition->frequency_per_year);
        $segments = $this->segmentsFor($mode, $calendar, $frequency, $window);

        $occurrences = [];
        $entries = [];
        $placedCount = 0;
        $unplacedCount = 0;
        $shiftedCount = 0;

        foreach ($segments as $index => $segment) {
            $sequence = $index + 1;

            if (! $mode->placesDates()) {
                $occurrences[] = ['sequence_no' => $sequence, 'date' => null, 'start' => null, 'end' => null];
                $entries[] = [
                    'sequence_no' => $sequence,
                    'outcome' => 'unscheduled',
                    'reason' => 'This definition is set to be placed by hand, so the occurrence is created '
                        .'unscheduled rather than dated by the generator.',
                ];
                $unplacedCount++;

                continue;
            }

            $result = $this->placeInSegment($definition, $segment, $window, $calendar, $sequence);

            $occurrences[] = [
                'sequence_no' => $sequence,
                'date' => $result['date'],
                'start' => $result['start'],
                'end' => $result['end'],
            ];

            $entries[] = $result['log'];

            if ($result['date'] === null) {
                $unplacedCount++;
            } else {
                $placedCount++;

                if ($result['log']['attempts'] > 1) {
                    $shiftedCount++;
                }
            }
        }

        return [
            'occurrences' => $occurrences,
            'log' => [
                'year' => $year,
                'mode' => $mode->value,
                'frequency_per_year' => $frequency,
                'preferred_window' => $window?->describe() ?? 'no preference',
                'working_days' => $calendar->count(),
                'blocked_days' => count($calendar->blocked),
                'suppressed_blackouts' => $calendar->suppressed,
                'unresolved_blackouts' => $calendar->unresolved,
                'placed' => $placedCount,
                'shifted' => $shiftedCount,
                'needs_scheduling' => $unplacedCount,
                'occurrences' => $entries,
            ],
        ];
    }

    /**
     * Walk one segment's candidate days, best fit first, until one is free.
     *
     * @param  list<string>  $segment
     * @return array{date: ?string, start: ?string, end: ?string, log: array<string, mixed>}
     */
    private function placeInSegment(
        ExerciseDefinition $definition,
        array $segment,
        ?PreferredWindow $window,
        CalendarYear $calendar,
        int $sequence,
    ): array {
        if ($segment === []) {
            return [
                'date' => null, 'start' => null, 'end' => null,
                'log' => [
                    'sequence_no' => $sequence,
                    'outcome' => 'needs_scheduling',
                    'attempts' => 0,
                    'reason' => 'Every working day in this part of the year is blacked out, so there is nowhere to '
                        .'place this exercise. It has been created unscheduled rather than dropped.',
                ],
            ];
        }

        $candidates = $this->rank($segment, $window);
        $attempts = 0;
        $rejected = [];

        foreach ($candidates as $date) {
            $attempts++;
            [$start, $end] = $this->windowFor($definition, $window, $date);

            $conflicts = $this->conflicts->check($definition, $start, $end);

            if ($conflicts === []) {
                return [
                    'date' => $date,
                    'start' => $start->toDateTimeString(),
                    'end' => $end->toDateTimeString(),
                    'log' => [
                        'sequence_no' => $sequence,
                        'outcome' => 'placed',
                        'date' => $date,
                        'attempts' => $attempts,
                        'window_misses' => $window?->misses(Carbon::parse($date)) ?? 0,
                        'advisories' => $calendar->advisoriesOn($date),
                        // Only the days actually tried and rejected, capped: a
                        // log listing forty near-misses is one nobody reads.
                        'shifted_from' => array_slice($rejected, 0, 5),
                    ],
                ];
            }

            $rejected[] = ['date' => $date, 'because' => $conflicts[0]['message']];
        }

        return [
            'date' => null, 'start' => null, 'end' => null,
            'log' => [
                'sequence_no' => $sequence,
                'outcome' => 'needs_scheduling',
                'attempts' => $attempts,
                'reason' => 'Every one of the '.$attempts.' available days in this part of the year is already '
                    .'committed. The occurrence has been created unscheduled so the obligation stays visible.',
                'shifted_from' => array_slice($rejected, 0, 5),
            ],
        ];
    }

    /**
     * Candidate days, best fit first.
     *
     * THE MIDPOINT IS THE STARTING POINT, NOT THE RULE. `even` distribution
     * wants the middle of each segment; the window wants a particular weekday.
     * Ranking by (window misses, distance from the midpoint) satisfies both
     * where it can and degrades gracefully where it cannot — instead of picking
     * the midpoint, finding it is a Friday, and giving up.
     *
     * @param  list<string>  $segment
     * @return list<string>
     */
    private function rank(array $segment, ?PreferredWindow $window): array
    {
        $midpoint = intdiv(count($segment), 2);

        $scored = [];

        foreach ($segment as $index => $date) {
            $scored[] = [
                'date' => $date,
                'misses' => $window?->misses(Carbon::parse($date)) ?? 0,
                'distance' => abs($index - $midpoint),
            ];
        }

        usort($scored, fn (array $a, array $b) => [$a['misses'], $a['distance']] <=> [$b['misses'], $b['distance']]);

        return array_column($scored, 'date');
    }

    /** @return list<list<string>> */
    private function segmentsFor(
        DistributionMode $mode,
        CalendarYear $calendar,
        int $frequency,
        ?PreferredWindow $window,
    ): array {
        return match ($mode) {
            DistributionMode::Even, DistributionMode::Manual => $calendar->segments($frequency),
            DistributionMode::QuarterEnd => $this->quarterEndSegments($calendar, $frequency),
            DistributionMode::MonthSpecific => $this->monthSegments($calendar, $frequency, $window),
        };
    }

    /**
     * The last month of each quarter, cycling where the frequency is not four.
     *
     * @return list<list<string>>
     */
    private function quarterEndSegments(CalendarYear $calendar, int $frequency): array
    {
        $months = [3, 6, 9, 12];
        $out = [];

        for ($i = 0; $i < $frequency; $i++) {
            $out[] = $calendar->inMonth($months[$i % 4]);
        }

        return $out;
    }

    /**
     * @return list<list<string>>
     */
    private function monthSegments(CalendarYear $calendar, int $frequency, ?PreferredWindow $window): array
    {
        $months = $window?->months();

        if ($months === null || $months === []) {
            // `month_specific` with no months named is a definition nobody
            // finished. Falling back to `even` would hide that; falling back to
            // one segment per occurrence with no days makes every occurrence
            // `needs_scheduling`, which is visible and correct.
            return array_fill(0, $frequency, []);
        }

        $out = [];

        for ($i = 0; $i < $frequency; $i++) {
            $out[] = $calendar->inMonth($months[$i % count($months)]);
        }

        return $out;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function windowFor(ExerciseDefinition $definition, ?PreferredWindow $window, string $date): array
    {
        $start = Carbon::parse($date.' '.($window?->startTime() ?? self::DEFAULT_START_TIME));

        return [$start, $start->copy()->addMinutes(max(15, (int) $definition->duration_minutes))];
    }

    /**
     * An occurrence a regeneration must not touch.
     *
     * In progress, finished or formally cancelled — all three are records of
     * something that happened, and the generator's job is to plan the future.
     */
    private function isFixed(ExerciseOccurrence $occurrence): bool
    {
        // Enum cases, not their values. `status` is cast on the model, and a
        // string comparison here would be false for every occurrence — which
        // would make a regeneration quietly rewrite completed exercises.
        return in_array($occurrence->status, [
            OccurrenceStatus::InProgress,
            OccurrenceStatus::Completed,
            OccurrenceStatus::Cancelled,
            OccurrenceStatus::Missed,
        ], true);
    }
}

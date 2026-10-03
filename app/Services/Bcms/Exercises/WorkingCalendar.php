<?php

namespace App\Services\Bcms\Exercises;

use App\Models\Bcms\BlackoutPeriod;
use App\Models\Bcms\ExerciseDefinition;
use Illuminate\Support\Carbon;

/**
 * The programme year's working-day set: the calendar minus weekends, minus
 * blackouts.
 *
 * STEP 1 OF THE GENERATION ALGORITHM, and the reason `even` distribution is
 * worth anything. Splitting the CALENDAR into four equal quarters and taking
 * the midpoint of each puts one exercise in the middle of December; splitting
 * the WORKING-DAY SET into four equal segments does not, because December has
 * far fewer usable days in it. The difference is the whole of "does this engine
 * understand a Nigerian bank's year".
 *
 * BUILT ONCE PER DEFINITION AND REUSED. A definition's own
 * `blackout_overrides` can suppress an organisation-wide period — a definition
 * that must run at month-end because month-end is the thing being tested is a
 * real case — so the set is per definition, not per tenant.
 *
 * ADVISORY BLACKOUTS DO NOT REMOVE A DAY. They stay in the working set and are
 * returned as warnings by `advisoriesOn()`, so an occurrence that lands in an
 * unconfirmed Eid window is placed and flagged rather than avoided on a guess.
 */
class WorkingCalendar
{
    public function __construct(private readonly BlackoutResolver $resolver) {}

    /**
     * @param  iterable<BlackoutPeriod>  $periods
     */
    public function build(int $year, iterable $periods, ?ExerciseDefinition $definition = null): CalendarYear
    {
        $suppressed = $this->suppressedNames($definition);

        $applicable = [];

        foreach ($periods as $period) {
            if (in_array($period->name, $suppressed, true)) {
                continue;
            }

            // A period scoped to a business unit applies only to definitions in
            // that unit. An organisation-level period applies to everything.
            if ($period->business_unit_id !== null
                && $definition !== null
                && (int) $period->business_unit_id !== (int) $definition->business_unit_id) {
                continue;
            }

            $applicable[] = $period;
        }

        $blocked = $this->resolver->datesFor($applicable, $year);

        $working = [];
        $hardBlocked = [];
        $advisories = [];

        $cursor = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->startOfDay();

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();

            if ($this->resolver->isWeekend($cursor)) {
                $cursor->addDay();

                continue;
            }

            $entries = $blocked[$date] ?? [];
            $hard = array_values(array_filter($entries, fn (array $e) => $e['hard']));
            $soft = array_values(array_filter($entries, fn (array $e) => ! $e['hard']));

            if ($soft !== []) {
                $advisories[$date] = array_column($soft, 'name');
            }

            if ($hard !== []) {
                $hardBlocked[$date] = array_column($hard, 'name');
            } else {
                $working[] = $date;
            }

            $cursor->addDay();
        }

        return new CalendarYear(
            year: $year,
            workingDays: $working,
            blocked: $hardBlocked,
            advisories: $advisories,
            unresolved: $this->resolver->unresolved($applicable, $year),
            suppressed: $suppressed,
        );
    }

    /**
     * `$fromDate` plus `$days` WORKING days — weekends and hard blackouts
     * skipped, exactly as the generator's own placement does.
     *
     * BUILT FOR PHASE 9's CAPA DUE-DATE RULE (compliance analyst §3.3): "do
     * not add days" is the instruction this method exists to satisfy, so a
     * corrective action's due date is not quietly wrong the week it lands on
     * a bank holiday. `$days` may be negative, which is how "5 working days
     * before the next occurrence" is computed.
     *
     * A period scoped to a business unit is included only when `$definition`
     * is in that unit, matching `build()`'s own rule — a due date has to
     * agree with the calendar the occurrence itself was placed against.
     */
    public function addWorkingDays(Carbon $fromDate, int $days, ?ExerciseDefinition $definition = null): Carbon
    {
        if ($days === 0) {
            return $fromDate->copy();
        }

        $direction = $days > 0 ? 1 : -1;
        $remaining = abs($days);
        $cursor = $fromDate->copy();
        $periods = BlackoutPeriod::query()->where('is_active', true)->get();

        // A year at a time so a due date that crosses a year boundary (a
        // finding raised in December with a January due date) still resolves
        // — the working set is built per calendar year (§ class docblock).
        $yearsSeen = [];

        while ($remaining > 0) {
            $cursor->addDays($direction);

            $year = $cursor->year;

            if (! isset($yearsSeen[$year])) {
                $yearsSeen[$year] = $this->build($year, $periods, $definition);
            }

            $calendar = $yearsSeen[$year];

            if ($calendar->isWorkingDay($cursor->toDateString())) {
                $remaining--;
            }
        }

        return $cursor;
    }

    /**
     * Blackout names this definition has explicitly opted out of.
     *
     * `blackout_overrides` is a list of period names rather than ids because it
     * is authored against a calendar the tenant can edit, and a name survives
     * the reseeding of a system-default row where an id does not.
     *
     * @return list<string>
     */
    private function suppressedNames(?ExerciseDefinition $definition): array
    {
        $overrides = $definition?->blackout_overrides;

        if (! is_array($overrides)) {
            return [];
        }

        $names = $overrides['suppress'] ?? $overrides;

        return is_array($names) ? array_values(array_map('strval', $names)) : [];
    }
}

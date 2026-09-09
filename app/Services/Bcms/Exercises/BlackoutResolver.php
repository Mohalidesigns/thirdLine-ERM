<?php

namespace App\Services\Bcms\Exercises;

use App\Models\Bcms\BlackoutPeriod;
use Illuminate\Support\Carbon;

/**
 * Turns the blackout calendar's recurrence grammar into actual dates for a year.
 *
 * SIX RULES, AND TWO OF THEM DELIBERATELY RESOLVE TO NOTHING. Eid and the
 * election period cannot be computed — the federal government declares Eid a few
 * days ahead and INEC publishes the election timetable — and the Phase 0
 * calendar seeds them as ADVISORY for exactly that reason. Returning a guessed
 * date would be worse than returning none: the generator would avoid the wrong
 * week and the customer would trust it. They resolve to no dates and are
 * reported as unresolved, so a screen can say "confirm these before you rely on
 * the calendar" instead of silently omitting them.
 *
 * HARD AND ADVISORY ARE DIFFERENT ANSWERS. A hard block removes the day from the
 * working set; an advisory one leaves it in and is reported as a warning against
 * whatever lands on it. Conflating them would either book a fire drill on
 * Christmas Eve or refuse to book anything in a fortnight nobody has confirmed.
 *
 * EVERYTHING IS COMPUTED, NOTHING IS STORED. A materialised blackout calendar
 * would need regenerating whenever somebody edited a period, and the failure
 * mode of a stale one is an exercise booked into a close.
 */
class BlackoutResolver
{
    /** The Nigerian working week. A deployment with a different weekend overrides it on the rule. */
    public const DEFAULT_WEEKEND = ['saturday', 'sunday'];

    /**
     * Every date blocked in this year, keyed by `Y-m-d`.
     *
     * @param  iterable<BlackoutPeriod>  $periods
     * @return array<string, list<array{name: string, category: ?string, hard: bool}>>
     */
    public function datesFor(iterable $periods, int $year): array
    {
        $blocked = [];

        foreach ($periods as $period) {
            foreach ($this->datesForPeriod($period, $year) as $date) {
                $blocked[$date][] = [
                    'name' => $period->name,
                    'category' => $period->category,
                    'hard' => (bool) $period->is_hard_block,
                ];
            }
        }

        ksort($blocked);

        return $blocked;
    }

    /**
     * The periods that could not be resolved to dates, and why.
     *
     * @param  iterable<BlackoutPeriod>  $periods
     * @return list<array{name: string, reason: string}>
     */
    public function unresolved(iterable $periods, int $year): array
    {
        $out = [];

        foreach ($periods as $period) {
            $rule = $this->ruleOf($period);

            if ($rule === 'lunar_advisory') {
                $out[] = [
                    'name' => $period->name,
                    'reason' => 'The dates move with the lunar calendar and are declared by the federal government a '
                        .'few days ahead. Confirm this year\'s dates and record them as a fixed window.',
                ];

                continue;
            }

            if ($rule === 'declared_dates') {
                $out[] = [
                    'name' => $period->name,
                    'reason' => 'These dates are announced rather than computed. Confirm them against the published '
                        .'timetable and record them as a fixed window.',
                ];

                continue;
            }

            if ($rule === null && $period->starts_on === null && ($period->recurrence['starts'] ?? null) === null) {
                $out[] = [
                    'name' => $period->name,
                    'reason' => 'This period has neither dates nor a recurrence rule, so it blocks nothing.',
                ];
            }
        }

        return $out;
    }

    /**
     * The dates one period covers in one year.
     *
     * @return list<string>
     */
    public function datesForPeriod(BlackoutPeriod $period, int $year): array
    {
        // Concrete dates on the row win over any rule: an organisation that has
        // confirmed this year's Eid has replaced the advisory rule with the
        // answer, and the answer is what should be honoured.
        if ($period->starts_on !== null && $period->ends_on !== null) {
            return $this->range(
                Carbon::parse($period->starts_on)->startOfDay(),
                Carbon::parse($period->ends_on)->startOfDay(),
            );
        }

        return match ($this->ruleOf($period)) {
            'annual_window' => $this->annualWindow($period, $year),
            'days_of_month' => $this->daysOfMonth($period, $year),
            'last_working_days_of_month' => $this->lastWorkingDaysOfMonth($period, $year),
            'easter_window' => $this->easterWindow($period, $year),
            // Advisory and unresolvable — see the class docblock. `unresolved()`
            // is what tells somebody these exist.
            'lunar_advisory', 'declared_dates' => [],
            default => [],
        };
    }

    /* ------------------------------------------------------------------ */
    /*  The rules */
    /* ------------------------------------------------------------------ */

    /**
     * "15 December to 5 January", expressed as month-day so it does not expire.
     *
     * @return list<string>
     */
    private function annualWindow(BlackoutPeriod $period, int $year): array
    {
        $starts = $period->recurrence['starts'] ?? null;
        $ends = $period->recurrence['ends'] ?? null;

        if (! is_string($starts) || ! is_string($ends)) {
            return [];
        }

        $from = Carbon::parse($year.'-'.$starts)->startOfDay();
        $to = Carbon::parse($year.'-'.$ends)->startOfDay();

        if ($to->lt($from)) {
            // The window crosses the year boundary. BOTH HALVES ARE BLOCKED IN
            // THIS YEAR: 15-31 December of this year and 1-5 January of it. A
            // naive `addYear()` on the end would block next January and leave
            // this one open, which is the half a generator actually collides
            // with when it is placing a Q1 exercise.
            return array_merge(
                $this->range($from, Carbon::parse($year.'-12-31')->startOfDay()),
                $this->range(Carbon::parse($year.'-01-01')->startOfDay(), $to),
            );
        }

        return $this->range($from, $to);
    }

    /** @return list<string> */
    private function daysOfMonth(BlackoutPeriod $period, int $year): array
    {
        $days = $period->recurrence['days'] ?? [];

        if (! is_array($days) || $days === []) {
            return [];
        }

        $dates = [];

        for ($month = 1; $month <= 12; $month++) {
            $lastDay = Carbon::create($year, $month, 1)->endOfMonth()->day;

            foreach ($days as $day) {
                $day = (int) $day;

                // A "31st" rule in February is not an error and is not the 28th
                // either — it simply does not occur that month.
                if ($day < 1 || $day > $lastDay) {
                    continue;
                }

                $dates[] = Carbon::create($year, $month, $day)->toDateString();
            }
        }

        return $dates;
    }

    /**
     * The single most important entry in the calendar: month-end close.
     *
     * @return list<string>
     */
    private function lastWorkingDaysOfMonth(BlackoutPeriod $period, int $year): array
    {
        $count = max(1, (int) ($period->recurrence['count'] ?? 3));
        $weekend = $this->weekendOf($period);
        $dates = [];

        for ($month = 1; $month <= 12; $month++) {
            $cursor = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();
            $found = 0;

            while ($found < $count && $cursor->month === $month) {
                if (! $this->isWeekend($cursor, $weekend)) {
                    $dates[] = $cursor->toDateString();
                    $found++;
                }

                $cursor->subDay();
            }
        }

        return $dates;
    }

    /**
     * Good Friday to Easter Monday, computed rather than fixed.
     *
     * @return list<string>
     */
    private function easterWindow(BlackoutPeriod $period, int $year): array
    {
        $before = max(0, (int) ($period->recurrence['days_before'] ?? 2));
        $after = max(0, (int) ($period->recurrence['days_after'] ?? 1));

        $easter = $this->easterSunday($year);

        return $this->range($easter->copy()->subDays($before), $easter->copy()->addDays($after));
    }

    /**
     * Easter Sunday, by the anonymous Gregorian algorithm.
     *
     * PHP's `easter_date()` needs ext-calendar, which is not guaranteed on a
     * customer's on-premise PHP build, and a blackout calendar that silently
     * loses Easter on one deployment is exactly the sort of difference nobody
     * finds until an exercise is booked on Good Friday.
     */
    public function easterSunday(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day)->startOfDay();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function ruleOf(BlackoutPeriod $period): ?string
    {
        $rule = $period->recurrence['rule'] ?? null;

        return is_string($rule) ? $rule : null;
    }

    /** @return list<string> */
    private function weekendOf(BlackoutPeriod $period): array
    {
        $weekend = $period->recurrence['weekend'] ?? null;

        return is_array($weekend) && $weekend !== []
            ? array_map('strtolower', array_map('strval', $weekend))
            : self::DEFAULT_WEEKEND;
    }

    /** @param list<string> $weekend */
    public function isWeekend(Carbon $date, array $weekend = self::DEFAULT_WEEKEND): bool
    {
        return in_array(strtolower($date->format('l')), $weekend, true);
    }

    /** @return list<string> */
    private function range(Carbon $from, Carbon $to): array
    {
        $dates = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        // Bounded: a period whose end is before its start after all the
        // resolution above is a bad row, not a reason to spin.
        $guard = 0;

        while ($cursor->lte($end) && $guard++ < 800) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }
}

<?php

namespace App\Services\Bcms\Exercises;

use Illuminate\Support\Carbon;

/**
 * One programme year's usable days, and what was taken out of it.
 *
 * A VALUE OBJECT RATHER THAN AN ARRAY, because the generator asks it four
 * different questions and each one is easy to get subtly wrong against a bare
 * list: which days are in segment three, what is the middle of that segment,
 * what blocked the day I wanted, and is this date usable at all.
 *
 * IT CARRIES WHAT IT REMOVED. A calendar that returns only the days that are
 * left cannot tell the programme owner why an occurrence could not be placed,
 * and "no slot was available" without a reason is the sort of message that gets
 * a product returned.
 */
final readonly class CalendarYear
{
    /**
     * @param  list<string>  $workingDays  `Y-m-d`, ascending
     * @param  array<string, list<string>>  $blocked  date => the hard periods that removed it
     * @param  array<string, list<string>>  $advisories  date => unconfirmed periods overlapping it
     * @param  list<array{name: string, reason: string}>  $unresolved
     * @param  list<string>  $suppressed  periods this definition opted out of
     */
    public function __construct(
        public int $year,
        public array $workingDays,
        public array $blocked,
        public array $advisories,
        public array $unresolved,
        public array $suppressed,
    ) {}

    public function count(): int
    {
        return count($this->workingDays);
    }

    public function isWorkingDay(string $date): bool
    {
        return in_array($date, $this->workingDays, true);
    }

    /** Why this date is not usable, in words. */
    public function reasonBlocked(string $date): ?string
    {
        if (isset($this->blocked[$date])) {
            return implode(', ', $this->blocked[$date]);
        }

        $parsed = Carbon::parse($date);

        if (in_array(strtolower($parsed->format('l')), BlackoutResolver::DEFAULT_WEEKEND, true)) {
            return 'weekend';
        }

        return $parsed->year === $this->year ? null : 'outside the programme year';
    }

    /**
     * Split the working year into `$segments` equal parts, measured in working
     * days rather than in calendar days.
     *
     * @return list<list<string>>
     */
    public function segments(int $segments): array
    {
        $segments = max(1, $segments);
        $total = $this->count();

        if ($total === 0) {
            return array_fill(0, $segments, []);
        }

        $out = [];
        $size = $total / $segments;

        for ($i = 0; $i < $segments; $i++) {
            $from = (int) floor($i * $size);
            $to = (int) floor(($i + 1) * $size);

            // Every segment gets at least one day where there are enough days
            // to go round; where there are not, an empty segment is the honest
            // answer and the generator turns it into `needs_scheduling`.
            $out[] = array_slice($this->workingDays, $from, max(0, $to - $from));
        }

        return $out;
    }

    /**
     * The working days of one month.
     *
     * @return list<string>
     */
    public function inMonth(int $month): array
    {
        return array_values(array_filter(
            $this->workingDays,
            fn (string $d) => (int) substr($d, 5, 2) === $month,
        ));
    }

    /** @return list<string> */
    public function advisoriesOn(string $date): array
    {
        return $this->advisories[$date] ?? [];
    }
}

<?php

namespace App\Enums\Bcms;

/**
 * How a definition's occurrences are spread across the programme year
 * (Blueprint §5.2).
 *
 * THE MODE IS THE SHAPE OF THE YEAR, and choosing it wrongly is how a bank ends
 * up running every drill in November. `even` is the default and the one the
 * board view exists to check: four occurrences at the midpoints of four equal
 * segments of the WORKING-day set, not of the calendar — a segment containing
 * the year-end close has fewer working days in it and should therefore be
 * shorter, or the exercise lands in the close.
 *
 * The Phase 0 migration's comment lists these as `even|quarterly|manual|window`.
 * The stored values are the phase prompt's names, which are the ones on the
 * screen. Nothing had been written to the column, so there is nothing to
 * migrate; the comment is stale and the enum is the definition.
 */
enum DistributionMode: string
{
    case Even = 'even';
    case QuarterEnd = 'quarter_end';
    case MonthSpecific = 'month_specific';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Even => 'Evenly across the year',
            self::QuarterEnd => 'Near each quarter end',
            self::MonthSpecific => 'In named months',
            self::Manual => 'Placed by hand',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Even => 'Split the working year into equal segments and place one exercise in the middle of each. '
                .'The segments are measured in working days, so a quieter period does not get more than its share.',
            self::QuarterEnd => 'Place each exercise in the last month of a quarter. Useful where a regulator asks '
                .'for a quarterly cadence and expects it to look quarterly.',
            self::MonthSpecific => 'Place exercises only in the months named on the definition.',
            self::Manual => 'Generate the occurrences unscheduled and let somebody place each one. Every occurrence '
                .'starts as "needs scheduling" — deliberately visible, rather than dated by a machine that was told '
                .'not to.',
        };
    }

    /**
     * Whether this mode asks the generator to choose dates at all.
     *
     * `manual` does not, and the difference matters: its occurrences are
     * created UNSCHEDULED rather than skipped, so the obligation is on the
     * calendar with nobody able to pretend it was never required.
     */
    public function placesDates(): bool
    {
        return $this !== self::Manual;
    }

    /** @return list<array{value: string, label: string, description: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'description' => $case->description(),
        ], self::cases());
    }
}

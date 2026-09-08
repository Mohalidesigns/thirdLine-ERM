<?php

namespace Database\Seeders\Bcms\Reference;

/**
 * The Nigerian blackout calendar — the windows in which a bank will not run an
 * exercise, whatever the programme says.
 *
 * WHY THIS SHIPS RATHER THAN BEING CONFIGURED. A generator that books a
 * full-scale failover on the 27th of the month, or during the year-end close,
 * has produced a calendar nobody will run, and the customer's conclusion is
 * that the engine does not understand their business. Every row is editable and
 * every row can be overridden per definition; what is not acceptable is an
 * empty calendar on day one.
 *
 * TWO SHAPES, AND BOTH ARE NECESSARY. Fixed dates express "15 December to 5
 * January". A recurrence expresses "the last three working days of every
 * month", which has no fixed date and is the single most important entry here —
 * month-end is when a Nigerian bank's operations, treasury and reporting teams
 * are least able to lose an afternoon.
 *
 * WHAT IS DELIBERATELY NOT HERE. Eid al-Fitr and Eid al-Adha move with the
 * lunar calendar and are declared by the federal government a few days ahead;
 * shipping a computed date would be shipping a wrong date most years. They are
 * seeded as ADVISORY windows around the conventional period, and the
 * organisation confirms the actual dates. An election day is likewise a
 * declared date, not a derivable one — 2027 is seeded as advisory because it is
 * announced, and nothing beyond it is guessed.
 */
class BlackoutCalendar
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'name' => 'Month-end close (last three working days)',
                'category' => 'month_end',
                'hard' => true,
                'recurrence' => [
                    'rule' => 'last_working_days_of_month',
                    'count' => 3,
                    // Nigerian working week. Stated rather than assumed: the
                    // same rule in a Gulf deployment has a different weekend,
                    // and a hard-coded Saturday/Sunday would be wrong there and
                    // invisible here.
                    'weekend' => ['saturday', 'sunday'],
                ],
                'note' => 'Operations, treasury and financial control are at capacity. The single most common reason a scheduled exercise is abandoned.',
            ],
            [
                'name' => 'Year-end close',
                'category' => 'year_end',
                'hard' => true,
                'starts' => '12-15',
                'ends' => '01-05',
                'note' => 'Financial year-end close and the audit that follows it. Spans the year boundary, so the window is expressed as month-day and resolved per year.',
            ],
            [
                'name' => 'Salary week',
                'category' => 'payroll',
                'hard' => true,
                'recurrence' => ['rule' => 'days_of_month', 'days' => [25, 26, 27, 28]],
                'note' => 'Peak transaction volume across every channel. A failover exercise here risks a real outage on the busiest days of the month.',
            ],
            [
                'name' => 'CBN returns deadline plus one',
                'category' => 'regulatory',
                'hard' => true,
                'recurrence' => ['rule' => 'days_of_month', 'days' => [1, 2, 3]],
                'note' => 'The days on which regulatory returns are compiled and filed. Configure the exact deadline days for the institution\'s own return set.',
            ],
            [
                'name' => 'Christmas and New Year',
                'category' => 'public_holiday',
                'hard' => true,
                'starts' => '12-24',
                'ends' => '01-02',
                'note' => 'Public holidays plus skeleton staffing either side.',
            ],
            [
                'name' => 'Easter (Good Friday to Easter Monday)',
                'category' => 'public_holiday',
                'hard' => true,
                'recurrence' => ['rule' => 'easter_window', 'days_before' => 2, 'days_after' => 1],
                'note' => 'Computed from the Easter date for the year rather than fixed.',
            ],
            [
                'name' => 'Eid al-Fitr (advisory — confirm the declared dates)',
                'category' => 'public_holiday',
                'hard' => false,
                'recurrence' => ['rule' => 'lunar_advisory', 'festival' => 'eid_al_fitr', 'days' => 3],
                'note' => 'ADVISORY. The dates are declared by the federal government a few days ahead and cannot be computed reliably. Confirm and convert to a hard block each year.',
            ],
            [
                'name' => 'Eid al-Adha (advisory — confirm the declared dates)',
                'category' => 'public_holiday',
                'hard' => false,
                'recurrence' => ['rule' => 'lunar_advisory', 'festival' => 'eid_al_adha', 'days' => 3],
                'note' => 'ADVISORY, for the same reason as Eid al-Fitr.',
            ],
            [
                'name' => 'Independence Day',
                'category' => 'public_holiday',
                'hard' => true,
                'starts' => '10-01',
                'ends' => '10-01',
                'note' => '1 October, fixed.',
            ],
            [
                'name' => 'Workers\' Day',
                'category' => 'public_holiday',
                'hard' => true,
                'starts' => '05-01',
                'ends' => '05-01',
                'note' => '1 May, fixed.',
            ],
            [
                'name' => 'Democracy Day',
                'category' => 'public_holiday',
                'hard' => true,
                'starts' => '06-12',
                'ends' => '06-12',
                'note' => '12 June, fixed.',
            ],
            [
                'name' => 'General election period (advisory — confirm INEC dates)',
                'category' => 'election',
                'hard' => false,
                'recurrence' => ['rule' => 'declared_dates', 'source' => 'inec'],
                'note' => 'ADVISORY. Election days and the days around them see restricted movement and elevated security risk. Confirm against the INEC timetable and convert to a hard block for the cycle.',
            ],
        ];
    }
}

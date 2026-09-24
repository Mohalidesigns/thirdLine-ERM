<?php

namespace App\Support\Bcms;

use Illuminate\Support\Carbon;

/**
 * Normalises every incoming incident-clock datetime to UTC before it is
 * compared or stored (Gate 1 re-gate, code review #2, defect 1).
 *
 * `Carbon::parse('2026-09-23T10:00:00+05:00')` returns an instant five hours
 * earlier than midday UTC — correct — but Eloquent's `datetime` cast then
 * writes that Carbon instance's LOCAL wall-clock figure into a MariaDB
 * `TIMESTAMP`/`DATETIME` column, which carries no offset of its own. The
 * column ends up holding `10:00`, not `05:00` — a five-hour error baked into
 * `awareness_at`, `due_at` (derived from it), and every comparison that
 * follows: `detected_at <= declared_at` can fail for a genuinely valid
 * declaration, and Amendment 2 rule 6's "moving awareness later needs a
 * reason" compares two wall-clock-in-different-offsets figures that are not
 * the same kind of number, so it can both false-negative and false-positive
 * depending on which side carried the larger offset.
 *
 * The fix is not "refuse an offset" — a mobile officer filing from a
 * different time zone than the datacentre is real information the payload
 * is right to carry. It is: resolve the true instant once, at the one point
 * each value enters the system, and store/compare THAT.
 */
final class IncidentClock
{
    /**
     * @param  \Illuminate\Support\Carbon|string|null  $value
     */
    public static function utc($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof Carbon ? $value->copy() : Carbon::parse($value))->utc();
    }
}

<?php

namespace App\Support\Periods;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Day boundaries for comparing against a DATE column.
 *
 * Eloquent's `date` cast serialises through the model's date format, so a
 * column declared DATE is written as "2026-06-30 00:00:00". Comparing that
 * against a bare "2026-06-30" excludes the day itself — lexically on SQLite,
 * and after implicit DATETIME promotion on MySQL. The symptom is a range query
 * that silently drops its last period, which is exactly the kind of off-by-one
 * that makes a quarterly total wrong by one month and looks plausible.
 *
 * whereDate() would also work and would be shorter, but it wraps the column in
 * a function and gives up the (organization_id, start_date, end_date) index —
 * on a table sized for a 12-period trend over 5,000 risks that is the whole
 * performance budget.
 */
class DateBounds
{
    public static function startOfDay(string|DateTimeInterface|CarbonImmutable $date): string
    {
        return self::parse($date)->startOfDay()->format('Y-m-d H:i:s');
    }

    public static function endOfDay(string|DateTimeInterface|CarbonImmutable $date): string
    {
        return self::parse($date)->endOfDay()->format('Y-m-d H:i:s');
    }

    private static function parse(string|DateTimeInterface|CarbonImmutable $date): CarbonImmutable
    {
        return $date instanceof CarbonImmutable ? $date : CarbonImmutable::parse($date);
    }
}

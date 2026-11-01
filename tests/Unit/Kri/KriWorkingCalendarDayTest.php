<?php

namespace Tests\Unit\Kri;

use App\Support\Kri\KriWorkingCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `kri:open-cycle --date=` is how an operator replays a missed first working
 * day, so the parser behind it is STRICT: exactly Y-m-d, and a real date.
 * createFromFormat() silently overflows an impossible date, so a typo would
 * open the cycle for a day nobody typed.
 *
 * The pure (database-free) half of KriWorkingCalendar; the holiday-aware half is
 * tests/Feature/Kri/KriWorkingCalendarTest.php.
 */
class KriWorkingCalendarDayTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function badDates(): array
    {
        return [
            'impossible day' => ['2026-02-30'],
            'month 13' => ['2026-13-01'],
            'day 0' => ['2026-11-00'],
            'trailing junk' => ['2026-11-02xyz'],
            'trailing time' => ['2026-11-02 10:00:00'],
            'slashes' => ['2026/11/02'],
            'day-first' => ['02-11-2026'],
            'short year' => ['26-11-02'],
            'no zero padding' => ['2026-1-2'],
            'words' => ['next monday'],
            'empty' => [''],
            'not a leap day' => ['2027-02-29'],
        ];
    }

    #[Test]
    #[DataProvider('badDates')]
    public function a_date_that_is_not_exactly_a_real_y_m_d_is_refused(string $text): void
    {
        $this->expectException(\InvalidArgumentException::class);

        KriWorkingCalendar::day($text);
    }

    #[Test]
    public function a_real_date_is_a_lagos_midnight(): void
    {
        $day = KriWorkingCalendar::day('2026-11-02');

        $this->assertSame('2026-11-02', $day->toDateString());
        $this->assertSame('Africa/Lagos', $day->getTimezone()->getName());
        $this->assertSame('00:00:00', $day->format('H:i:s'));
    }

    #[Test]
    public function a_leap_day_that_exists_is_accepted(): void
    {
        $this->assertSame('2028-02-29', KriWorkingCalendar::day('2028-02-29')->toDateString());
    }

    #[Test]
    public function the_constants_are_in_code_not_config(): void
    {
        // A published config must not be able to move a due date the code has never seen.
        $this->assertSame('Africa/Lagos', KriWorkingCalendar::TIMEZONE);
        $this->assertSame(5, KriWorkingCalendar::DUE_WORKING_DAYS);
        $this->assertNull(config('kri'));
    }

    #[Test]
    public function today_is_the_lagos_day_not_the_utc_day(): void
    {
        // 23:30 UTC on 1 Nov is 00:30 on 2 Nov in Lagos (UTC+1, no DST).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 23:30:00', 'UTC'));

        try {
            $this->assertSame('2026-11-02', KriWorkingCalendar::today()->toDateString());
            $this->assertSame('Africa/Lagos', KriWorkingCalendar::today()->getTimezone()->getName());
        } finally {
            CarbonImmutable::setTestNow();
        }

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 22:30:00', 'UTC'));

        try {
            $this->assertSame('2026-11-01', KriWorkingCalendar::today()->toDateString());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    #[Test]
    public function a_weekend_is_not_a_working_day_without_asking_anyone(): void
    {
        $calendar = new KriWorkingCalendar;

        // Saturday and Sunday are decided before any holiday lookup, so no tenant is needed.
        $this->assertFalse($calendar->isWorkingDay(KriWorkingCalendar::day('2026-11-01'), 1));
        $this->assertFalse($calendar->isWorkingDay(KriWorkingCalendar::day('2026-10-31'), 1));
    }
}

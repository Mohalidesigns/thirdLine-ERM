<?php

namespace Tests\Feature\Kri;

use App\Models\OrganizationHoliday;
use App\Support\Kri\KriWorkingCalendar;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0026 section 11 / decision 0.2: a working day is Monday to Friday in
 * Africa/Lagos minus the ORGANISATION's holiday list. No BCMS BlackoutPeriod.
 *
 * Calendar facts used (2026): 1 Nov is a Sunday, 1 Aug a Saturday, 1 Jun a
 * Monday, 1 Oct a Thursday (Independence Day).
 */
class KriWorkingCalendarTest extends KriCycleTestCase
{
    private function calendar(): KriWorkingCalendar
    {
        // Not a singleton, deliberately: a fresh instance re-reads the list.
        return app(KriWorkingCalendar::class);
    }

    private function holiday(string $date, string $name = 'Holiday', ?int $organizationId = null): OrganizationHoliday
    {
        return TenantContext::bypass(fn () => OrganizationHoliday::create([
            'organization_id' => $organizationId ?? $this->organization->id,
            'holiday_date' => $date,
            'name' => $name,
            'created_by' => $this->actor->id,
        ]), 'test fixture');
    }

    #[Test]
    public function the_first_of_the_month_on_a_weekday_is_the_first_working_day(): void
    {
        // 1 Jul 2026 is a Wednesday.
        $this->assertSame('2026-07-01', $this->calendar()->firstWorkingDayOf(2026, 7)->toDateString());
    }

    #[Test]
    public function a_first_that_falls_on_a_sunday_moves_to_monday(): void
    {
        $this->assertSame('2026-11-02', $this->calendar()->firstWorkingDayOf(2026, 11)->toDateString());
    }

    #[Test]
    public function a_first_that_falls_on_a_saturday_moves_to_monday(): void
    {
        $this->assertSame('2026-08-03', $this->calendar()->firstWorkingDayOf(2026, 8)->toDateString());
    }

    #[Test]
    public function a_first_that_is_an_organisation_holiday_moves_to_the_next_working_day(): void
    {
        // 1 Oct 2026 is a Thursday - Nigeria's Independence Day.
        $this->assertSame('2026-10-01', $this->calendar()->firstWorkingDayOf(2026, 10)->toDateString(), 'before the list is maintained');

        $this->holiday('2026-10-01', 'Independence Day');

        $this->assertSame('2026-10-02', $this->calendar()->firstWorkingDayOf(2026, 10)->toDateString());
    }

    #[Test]
    public function a_holiday_on_a_monday_first_moves_to_tuesday(): void
    {
        // 1 Jun 2026 is a Monday.
        $this->assertSame('2026-06-01', $this->calendar()->firstWorkingDayOf(2026, 6)->toDateString());

        $this->holiday('2026-06-01', 'Public holiday');

        $this->assertSame('2026-06-02', $this->calendar()->firstWorkingDayOf(2026, 6)->toDateString());
    }

    #[Test]
    public function a_saturday_first_followed_by_a_monday_holiday_lands_on_tuesday(): void
    {
        // 1 Aug 2026 is a Saturday; Monday 3 Aug is a holiday.
        $this->holiday('2026-08-03');

        $this->assertSame('2026-08-04', $this->calendar()->firstWorkingDayOf(2026, 8)->toDateString());
    }

    #[Test]
    public function a_run_of_consecutive_holidays_is_skipped_whole(): void
    {
        // Sun 1 Nov + Mon 2 and Tue 3 are holidays: first working day is Wed 4 Nov.
        $this->holiday('2026-11-02');
        $this->holiday('2026-11-03');

        $this->assertSame('2026-11-04', $this->calendar()->firstWorkingDayOf(2026, 11)->toDateString());
    }

    #[Test]
    public function a_holiday_that_falls_on_a_weekend_changes_nothing(): void
    {
        $this->holiday('2026-11-01', 'A Sunday holiday');

        $this->assertSame('2026-11-02', $this->calendar()->firstWorkingDayOf(2026, 11)->toDateString());
    }

    #[Test]
    public function another_organisations_holiday_does_not_move_this_ones_first_working_day(): void
    {
        $this->holiday('2026-11-02', 'Their holiday', $this->otherOrg->id);

        $this->assertSame('2026-11-02', $this->calendar()->firstWorkingDayOf(2026, 11)->toDateString());
        $this->assertTrue($this->calendar()->isWorkingDay(KriWorkingCalendar::day('2026-11-02'), $this->organization->id));
        $this->assertFalse($this->calendar()->isWorkingDay(KriWorkingCalendar::day('2026-11-02'), $this->otherOrg->id));
    }

    #[Test]
    public function an_organisation_holiday_is_not_a_working_day_but_the_next_weekday_is(): void
    {
        $this->holiday('2026-11-04');
        $calendar = $this->calendar();

        $this->assertFalse($calendar->isWorkingDay(KriWorkingCalendar::day('2026-11-04'), $this->organization->id));
        $this->assertTrue($calendar->isWorkingDay(KriWorkingCalendar::day('2026-11-05'), $this->organization->id));
        $this->assertFalse($calendar->isWorkingDay(KriWorkingCalendar::day('2026-11-07'), $this->organization->id), 'Saturday');
    }

    #[Test]
    public function the_due_date_is_five_working_days_after_the_request_and_the_start_day_is_not_counted(): void
    {
        // Mon 2 Nov -> Mon 9 Nov.
        $this->assertSame('2026-11-09', $this->calendar()->addWorkingDays(KriWorkingCalendar::day('2026-11-02'), KriWorkingCalendar::DUE_WORKING_DAYS)->toDateString());
        // Thu 5 Nov -> Thu 12 Nov.
        $this->assertSame('2026-11-12', $this->calendar()->addWorkingDays(KriWorkingCalendar::day('2026-11-05'), 5)->toDateString());
        // Fri 6 Nov + 1 -> Mon 9 Nov (the weekend is skipped).
        $this->assertSame('2026-11-09', $this->calendar()->addWorkingDays(KriWorkingCalendar::day('2026-11-06'), 1)->toDateString());
    }

    #[Test]
    public function a_holiday_inside_the_window_pushes_the_due_date_out_a_day(): void
    {
        $this->holiday('2026-11-04');

        $this->assertSame('2026-11-10', $this->calendar()->addWorkingDays(KriWorkingCalendar::day('2026-11-02'), 5)->toDateString());
    }

    #[Test]
    public function the_due_date_crosses_a_year_end(): void
    {
        // Wed 30 Dec 2026 + 5 working days: 31 Dec (1), 1 Jan (2), 4 Jan (3), 5 Jan (4), 6 Jan (5).
        $this->assertSame('2027-01-06', $this->calendar()->addWorkingDays(KriWorkingCalendar::day('2026-12-30'), 5)->toDateString());

        // ...and a 1 Jan holiday in the NEXT year is honoured.
        $this->holiday('2027-01-01', 'New Year');

        $this->assertSame('2027-01-07', $this->calendar()->addWorkingDays(KriWorkingCalendar::day('2026-12-30'), 5)->toDateString());
    }

    #[Test]
    public function a_due_date_walk_is_one_holiday_query_per_year_not_one_per_day(): void
    {
        $calendar = $this->calendar();

        DB::enableQueryLog();
        $calendar->addWorkingDays(KriWorkingCalendar::day('2026-11-02'), 5);
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'organization_holidays'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $queries);
    }

    #[Test]
    public function with_no_tenant_resolved_the_calendar_fails_closed_rather_than_guessing_a_holiday_list(): void
    {
        TenantContext::clear();

        $this->expectException(\RuntimeException::class);

        // A weekday, so the holiday lookup is reached; there is no tenant to read it for.
        (new KriWorkingCalendar)->isWorkingDay(KriWorkingCalendar::day('2026-11-02'));
    }

    #[Test]
    public function the_reporting_period_is_the_month_just_ended_in_lagos_time(): void
    {
        $cycle = app(\App\Services\Kri\KriCycleService::class);

        // First working day of November -> October.
        $this->assertSame('2026-10-01', $cycle->reportingPeriodFor(KriWorkingCalendar::day('2026-11-02'), $this->organization->id)->start_date->toDateString());
        $this->assertSame('2026-10-31', $cycle->reportingPeriodFor(KriWorkingCalendar::day('2026-11-02'), $this->organization->id)->end_date->toDateString());

        // First working day of January -> December of the PREVIOUS year.
        $january = $cycle->reportingPeriodFor(KriWorkingCalendar::day('2027-01-04'), $this->organization->id);
        $this->assertSame('2026-12-01', $january->start_date->toDateString());
        $this->assertSame('2026-12-31', $january->end_date->toDateString());

        // A forced run LATER in the month still reports the month just ended, not the current one.
        $this->assertSame('2026-10-31', $cycle->reportingPeriodFor(KriWorkingCalendar::day('2026-11-19'), $this->organization->id)->end_date->toDateString());
    }
}

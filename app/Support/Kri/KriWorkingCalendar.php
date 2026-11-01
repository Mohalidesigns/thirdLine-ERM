<?php

namespace App\Support\Kri;

use App\Models\OrganizationHoliday;
use Carbon\CarbonImmutable;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Working days for the departmental KRI cycle (ADR 0026 section 11).
 *
 * A working day is Monday to Friday in Africa/Lagos, minus the organisation's
 * holiday list. THE CONSTANTS ARE NOT IN CONFIG: a published config must not be
 * able to change a due date the code has never seen. `config('app.timezone')`
 * stays UTC; every "today / overdue / first working day" decision here uses
 * today(), which is the Lagos day.
 *
 * Holidays are read per organisation and memoised for the life of the instance,
 * keyed by organisation and year, so a due-date calculation is one query per
 * year touched rather than one per day walked.
 */
class KriWorkingCalendar
{
    public const TIMEZONE = 'Africa/Lagos';

    public const DUE_WORKING_DAYS = 5;

    /** @var array<string, array<string, true>> "org:year" => [Y-m-d => true] */
    private array $holidays = [];

    /** Today in Africa/Lagos, as a date (start of day), never a UTC instant. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    /** A Y-m-d string or any date as a Lagos calendar day. */
    public static function day(string|\DateTimeInterface $value): CarbonImmutable
    {
        $text = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr($value, 0, 10);

        return CarbonImmutable::createFromFormat('Y-m-d', $text, self::TIMEZONE)->startOfDay();
    }

    public function isWorkingDay(CarbonImmutable $day, ?int $organizationId = null): bool
    {
        if ($day->isWeekend()) {
            return false;
        }

        return ! $this->isHoliday($day, $organizationId ?? TenantContext::organizationId());
    }

    public function isHoliday(CarbonImmutable $day, int $organizationId): bool
    {
        return isset($this->holidaysFor($organizationId, (int) $day->format('Y'))[$day->toDateString()]);
    }

    public function firstWorkingDayOf(int $year, int $month, ?int $organizationId = null): CarbonImmutable
    {
        $organizationId ??= TenantContext::organizationId();
        $day = CarbonImmutable::create($year, $month, 1, 0, 0, 0, self::TIMEZONE);

        // A month cannot have no working day in any realistic list; the bound
        // stops a list that holidays a whole month from looping for ever.
        for ($guard = 0; $guard < 62; $guard++) {
            if ($this->isWorkingDay($day, $organizationId)) {
                return $day;
            }

            $day = $day->addDay();
        }

        return $day;
    }

    /**
     * The start day is not counted: a request on Monday 2 November is due
     * Monday 9 November.
     */
    public function addWorkingDays(CarbonImmutable $from, int $n, ?int $organizationId = null): CarbonImmutable
    {
        $organizationId ??= TenantContext::organizationId();
        $day = $from->startOfDay();
        $added = 0;

        for ($guard = 0; $added < $n && $guard < 366; $guard++) {
            $day = $day->addDay();

            if ($this->isWorkingDay($day, $organizationId)) {
                $added++;
            }
        }

        return $day;
    }

    /** Forget cached holidays - after the list is edited within one process. */
    public function flush(): void
    {
        $this->holidays = [];
    }

    /** @return array<string, true> */
    private function holidaysFor(int $organizationId, int $year): array
    {
        $key = $organizationId.':'.$year;

        if (! isset($this->holidays[$key])) {
            $this->holidays[$key] = OrganizationHoliday::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereBetween('holiday_date', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)])
                ->pluck('holiday_date')
                ->mapWithKeys(fn ($date) => [substr((string) (is_object($date) ? $date->format('Y-m-d') : $date), 0, 10) => true])
                ->all();
        }

        return $this->holidays[$key];
    }
}

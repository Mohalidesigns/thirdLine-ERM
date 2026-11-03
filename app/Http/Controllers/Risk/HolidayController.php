<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kri\StoreOrganizationHolidayRequest;
use App\Models\OrganizationHoliday;
use App\Services\AuditTrailService;
use App\Support\Kri\KriWorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The organisation's holiday list (ADR 0026 section 3, `holiday.manage`). Each
 * date moves the first working day of its month and every return's due date, so
 * the screen shows that effect: `firstWorkingDays`.
 */
class HolidayController extends Controller
{
    public function index(Request $request, KriWorkingCalendar $calendar)
    {
        $organizationId = TenantContext::organizationId();
        $thisYear = (int) KriWorkingCalendar::today()->format('Y');
        $year = $request->integer('year') ?: $thisYear;

        $years = OrganizationHoliday::query()->pluck('holiday_date')
            ->map(fn ($d) => (int) $d->format('Y'))
            ->merge([$thisYear - 1, $thisYear, $thisYear + 1, $year])
            ->unique()->sort()->values()->all();

        $firstWorkingDays = [];

        for ($month = 1; $month <= 12; $month++) {
            $first = $calendar->firstWorkingDayOf($year, $month, $organizationId);
            $plain = CarbonImmutable::create($year, $month, 1, 0, 0, 0, KriWorkingCalendar::TIMEZONE);

            while ($plain->isWeekend()) {
                $plain = $plain->addDay();
            }

            $firstWorkingDays[] = [
                'month' => $month,
                'date' => $first->toDateString(),
                // True when a holiday moved it off the month's first Mon-Fri.
                'moved_by_holiday' => $first->toDateString() !== $plain->toDateString(),
            ];
        }

        return Inertia::render('Settings/Holidays', [
            'year' => $year,
            'years' => $years,
            'holidays' => OrganizationHoliday::query()
                ->whereBetween('holiday_date', ["{$year}-01-01", "{$year}-12-31"])
                ->orderBy('holiday_date')
                ->get()
                ->map(fn (OrganizationHoliday $h) => [
                    'id' => (int) $h->id,
                    'date' => $h->holiday_date->toDateString(),
                    'name' => $h->name,
                    'weekday' => $h->holiday_date->format('l'),
                ])->all(),
            'firstWorkingDays' => $firstWorkingDays,
        ]);
    }

    public function store(StoreOrganizationHolidayRequest $request)
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $request) {
            $holiday = OrganizationHoliday::query()->create([
                'organization_id' => TenantContext::organizationId(),
                'holiday_date' => $validated['holiday_date'],
                'name' => $validated['name'],
                'created_by' => $request->user()->id,
            ]);

            AuditTrailService::record($holiday, 'holiday_added', 'holiday_date', null, $holiday->holiday_date->toDateString().' '.$holiday->name);
        });

        return back()->with('success', 'Holiday added.');
    }

    public function destroy(OrganizationHoliday $holiday)
    {
        Gate::authorize('delete', $holiday);

        DB::transaction(function () use ($holiday) {
            AuditTrailService::record($holiday, 'holiday_removed', 'holiday_date', $holiday->holiday_date->toDateString().' '.$holiday->name, null);
            $holiday->delete();
        });

        return back()->with('success', 'Holiday removed.');
    }
}

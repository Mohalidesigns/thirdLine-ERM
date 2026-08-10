<?php

namespace App\Services;

use App\Models\MeasureValue;
use App\Models\Period;
use App\Models\PeriodCalendar;
use App\Models\User;
use App\Support\Periods\PeriodGenerator;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Everything the platform knows about "when".
 *
 * The calendar is provisioned lazily. An organisation created by SSO
 * just-in-time provisioning, by the SCIM endpoint or by a seeder never passes
 * through an install step, so a calendar that only existed if a migration
 * happened to run after the organisation did would leave those tenants with no
 * periods at all — and a null period means every dashboard silently falls back
 * to "all time". ensureCalendar() makes the first read build what is missing.
 */
class PeriodService
{
    /** Fiscal years generated either side of the current one. */
    public const HORIZON_YEARS = 3;

    public const DEFAULT_CALENDAR_CODE = 'DEFAULT';

    /* ------------------------------------------------------------------ */
    /*  Calendar provisioning */
    /* ------------------------------------------------------------------ */

    /**
     * The organisation's default calendar, created with its periods if absent.
     */
    public function ensureCalendar(?int $organizationId = null): PeriodCalendar
    {
        $organizationId = $organizationId ?? TenantContext::organizationId();

        $calendar = PeriodCalendar::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($calendar === null) {
            $calendar = PeriodCalendar::withoutGlobalScopes()->create([
                'organization_id' => $organizationId,
                'code' => self::DEFAULT_CALENDAR_CODE,
                'name' => 'Default reporting calendar',
                'fiscal_year_start_month' => 1,
                'is_default' => true,
            ]);
        }

        $this->ensureHorizon($calendar);

        return $calendar;
    }

    /**
     * Generate any fiscal year within the horizon that does not exist yet.
     *
     * Idempotent, and cheap once populated: a single count against the year
     * codes decides whether there is anything to do.
     */
    public function ensureHorizon(PeriodCalendar $calendar, ?int $centreYear = null): void
    {
        $centreYear = $centreYear ?? PeriodGenerator::fiscalYearOf(
            CarbonImmutable::now(), $calendar->fiscal_year_start_month
        );

        $wanted = range($centreYear - self::HORIZON_YEARS, $centreYear + self::HORIZON_YEARS);

        $existing = Period::withoutGlobalScopes()
            ->where('calendar_id', $calendar->id)
            ->where('type', 'year')
            ->pluck('code')
            ->all();

        foreach ($wanted as $year) {
            if (! in_array('FY'.$year, $existing, true)) {
                $this->generateFiscalYear($calendar, $year);
            }
        }
    }

    /**
     * Write one fiscal year's periods, parents first so children can link.
     */
    public function generateFiscalYear(PeriodCalendar $calendar, int $fiscalYear): void
    {
        $specs = PeriodGenerator::forFiscalYear($fiscalYear, $calendar->fiscal_year_start_month);

        DB::transaction(function () use ($calendar, $specs) {
            /** @var array<string, int> $idsByCode */
            $idsByCode = [];

            foreach ($specs as $spec) {
                $period = Period::withoutGlobalScopes()->firstOrCreate(
                    ['calendar_id' => $calendar->id, 'code' => $spec['code']],
                    [
                        'organization_id' => $calendar->organization_id,
                        'type' => $spec['type'],
                        'name' => $spec['name'],
                        'start_date' => $spec['start_date'],
                        'end_date' => $spec['end_date'],
                        'parent_period_id' => $spec['parent_code'] === null
                            ? null
                            : ($idsByCode[$spec['parent_code']] ?? null),
                    ]
                );

                $idsByCode[$spec['code']] = $period->id;
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Resolution */
    /* ------------------------------------------------------------------ */

    /**
     * The period containing today, at the given granularity.
     */
    public function current(string $type = 'month', ?int $organizationId = null): Period
    {
        return $this->resolve(CarbonImmutable::now()->toDateString(), $type, $organizationId);
    }

    /**
     * The period of the given type containing $date, generating the fiscal year
     * on demand if the date falls outside the current horizon.
     *
     * Never returns null. A caller asking "which quarter is this date in" has
     * asked a question that always has an answer, and returning null would
     * push that impossibility into every call site.
     */
    public function resolve(string|CarbonImmutable $date, string $type = 'month', ?int $organizationId = null): Period
    {
        $date = $date instanceof CarbonImmutable ? $date : CarbonImmutable::parse($date);
        $calendar = $this->ensureCalendar($organizationId);

        $period = $this->find($calendar, $date, $type);

        if ($period === null) {
            // Outside the ±3-year horizon — a backfill of historic assessments
            // or a forward-dated plan. Generate that year and try once more.
            $this->generateFiscalYear(
                $calendar,
                PeriodGenerator::fiscalYearOf($date, $calendar->fiscal_year_start_month)
            );

            $period = $this->find($calendar, $date, $type);
        }

        if ($period === null) {
            throw new \RuntimeException(
                "No {$type} period could be resolved for {$date->toDateString()} on calendar {$calendar->code}."
            );
        }

        return $period;
    }

    private function find(PeriodCalendar $calendar, CarbonImmutable $date, string $type): ?Period
    {
        return Period::withoutGlobalScopes()
            ->where('calendar_id', $calendar->id)
            ->where('type', $type)
            ->containing($date->toDateString())
            ->first();
    }

    public function byId(int $periodId, ?int $organizationId = null): ?Period
    {
        return Period::withoutGlobalScopes()
            ->where('id', $periodId)
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->first();
    }

    /* ------------------------------------------------------------------ */
    /*  Navigation */
    /* ------------------------------------------------------------------ */

    /**
     * The period of the same type immediately before this one, generating the
     * preceding fiscal year if the horizon runs out.
     */
    public function previous(Period $period): ?Period
    {
        return $this->neighbour($period, 'previous');
    }

    public function next(Period $period): ?Period
    {
        return $this->neighbour($period, 'next');
    }

    private function neighbour(Period $period, string $direction): ?Period
    {
        $query = fn () => Period::withoutGlobalScopes()
            ->where('calendar_id', $period->calendar_id)
            ->where('type', $period->type)
            ->when(
                $direction === 'previous',
                fn ($q) => $q->where('start_date', '<', $period->start_date)->orderByDesc('start_date'),
                fn ($q) => $q->where('start_date', '>', $period->start_date)->orderBy('start_date'),
            )
            ->first();

        $found = $query();

        if ($found === null && $period->calendar !== null) {
            $step = $direction === 'previous' ? -1 : 1;
            $this->generateFiscalYear(
                $period->calendar,
                PeriodGenerator::fiscalYearOf(
                    CarbonImmutable::parse($period->start_date)->addYears($step),
                    $period->calendar->fiscal_year_start_month
                )
            );

            $found = $query();
        }

        return $found;
    }

    /**
     * Every period of a type between two dates, chronologically.
     *
     * This is what a trend chart iterates. It generates whatever fiscal years
     * the range spans, so "the last 12 months" works on 2 January without the
     * caller worrying about the year boundary.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Period>
     */
    public function range(
        string|CarbonImmutable $from,
        string|CarbonImmutable $to,
        string $type = 'month',
        ?int $organizationId = null
    ) {
        $from = $from instanceof CarbonImmutable ? $from : CarbonImmutable::parse($from);
        $to = $to instanceof CarbonImmutable ? $to : CarbonImmutable::parse($to);

        $calendar = $this->ensureCalendar($organizationId);

        $firstYear = PeriodGenerator::fiscalYearOf($from, $calendar->fiscal_year_start_month);
        $lastYear = PeriodGenerator::fiscalYearOf($to, $calendar->fiscal_year_start_month);

        for ($year = $firstYear; $year <= $lastYear; $year++) {
            $exists = Period::withoutGlobalScopes()
                ->where('calendar_id', $calendar->id)
                ->where('code', 'FY'.$year)
                ->exists();

            if (! $exists) {
                $this->generateFiscalYear($calendar, $year);
            }
        }

        return Period::withoutGlobalScopes()
            ->where('calendar_id', $calendar->id)
            ->where('type', $type)
            ->between($from->toDateString(), $to->toDateString())
            ->get();
    }

    /**
     * The last $count periods of a type ending with (and including) $period.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Period>
     */
    public function trailing(Period $period, int $count, ?string $type = null)
    {
        $type = $type ?? $period->type;

        return Period::withoutGlobalScopes()
            ->where('calendar_id', $period->calendar_id)
            ->where('type', $type)
            ->where('end_date', '<=', $period->end_date)
            ->orderByDesc('start_date')
            ->limit($count)
            ->get()
            ->sortBy('start_date')
            ->values();
    }

    /* ------------------------------------------------------------------ */
    /*  Close and reopen */
    /* ------------------------------------------------------------------ */

    /**
     * Close a period and lock every value recorded in it and in the periods
     * beneath it.
     *
     * Closing a quarter closes its months: a figure that could still move in
     * March would make the Q1 total a number that reconciles only by accident.
     *
     * @return int the number of values locked
     */
    public function close(Period $period, ?User $actor = null): int
    {
        return DB::transaction(function () use ($period, $actor) {
            $periodIds = $period->descendantIds();

            $locked = MeasureValue::withoutGlobalScopes()
                ->whereIn('period_id', $periodIds)
                ->where('status', '!=', 'locked')
                ->update(['status' => 'locked', 'updated_at' => now()]);

            Period::withoutGlobalScopes()
                ->whereIn('id', $periodIds)
                ->update([
                    'is_closed' => true,
                    'closed_at' => now(),
                    'closed_by' => $actor?->id ?? auth()->id(),
                    'updated_at' => now(),
                ]);

            AuditTrailService::record(
                $period->fresh(),
                'period_closed',
                'is_closed',
                false,
                true,
                "Closed {$period->name}; locked {$locked} measure value(s) across ".count($periodIds).' period(s).'
            );

            return $locked;
        });
    }

    /**
     * Reopen a closed period, unlocking its values.
     *
     * Requires `period.reopen`. This is the one operation in the measure engine
     * that can change a number a board pack has already been built on, so the
     * permission check lives in the service rather than only on the route — a
     * console command or a queued job reaches this method too.
     *
     * @throws AuthorizationException
     */
    public function reopen(Period $period, User $actor, string $reason): int
    {
        if (! $actor->can('period.reopen')) {
            throw new AuthorizationException(
                'Reopening a closed period requires the period.reopen permission.'
            );
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required to reopen a closed period.');
        }

        return DB::transaction(function () use ($period, $actor, $reason) {
            $periodIds = $period->descendantIds();

            $unlocked = MeasureValue::withoutGlobalScopes()
                ->whereIn('period_id', $periodIds)
                ->where('status', 'locked')
                ->update(['status' => 'approved', 'updated_at' => now()]);

            Period::withoutGlobalScopes()
                ->whereIn('id', $periodIds)
                ->update([
                    'is_closed' => false,
                    'closed_at' => null,
                    'closed_by' => null,
                    'updated_at' => now(),
                ]);

            AuditTrailService::record(
                $period->fresh(),
                'period_reopened',
                'is_closed',
                true,
                false,
                "Reopened {$period->name} by {$actor->name}: {$reason}. Unlocked {$unlocked} measure value(s)."
            );

            return $unlocked;
        });
    }
}

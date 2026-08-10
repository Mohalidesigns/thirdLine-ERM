<?php

namespace App\Repositories;

use App\Models\Measure;
use App\Models\Period;
use App\Models\Risk;
use App\Support\Measures\MeasureCatalog;
use App\Support\Periods\DateBounds;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The risk register, read at a point in time.
 *
 * WP-04 TASK 4. `risks` holds the CURRENT score and always will — it is what
 * makes the register list render in one query. This class answers the other
 * question: what did the register say at the end of Q1.
 *
 * CARRY-FORWARD SEMANTICS. A register "as at Q1" shows every risk that existed
 * then, at the last score approved on or before the end of Q1 — not only risks
 * reassessed inside Q1. A risk assessed in Q1 and not touched in Q2 still has a
 * score in Q2; it has simply not moved. Reporting a gap there would understate
 * the register, which is the opposite of what a risk report should do when it
 * is uncertain.
 */
class RiskRepository
{
    /**
     * The register as at a period.
     *
     * Returns Risk models whose score attributes have been REPLACED with the
     * as-at values. The models are not saved and the database rows are
     * untouched; `as_of_period_id` and `as_of_measured_at` are set on each so a
     * caller can tell a carried-forward number from a fresh one.
     *
     * @param  array{status?:string|list<string>, category_id?:int, node_id?:int, business_unit_id?:int}  $filters
     * @return Collection<int, Risk>
     */
    public function asOf(Period|int $period, array $filters = [], ?int $organizationId = null): Collection
    {
        $organizationId = $organizationId ?? TenantContext::organizationId();
        $period = $period instanceof Period
            ? $period
            : (Period::withoutGlobalScopes()->findOrFail($period));

        $risks = $this->baseQuery($filters, $organizationId)
            // Risks identified after the period ended did not exist as at that
            // date and do not belong in the register for it.
            ->where(function ($query) use ($period) {
                $query->whereNull('date_identified')
                    ->orWhere('date_identified', '<=', DateBounds::endOfDay($period->end_date));
            })
            ->get();

        if ($risks->isEmpty()) {
            return $risks;
        }

        $values = $this->valuesAsOf($period, $risks->pluck('id')->all(), $organizationId);

        foreach ($risks as $risk) {
            $this->overlay($risk, $values[$risk->id] ?? [], $period);
        }

        return $risks;
    }

    /**
     * The as-at value of every risk measure, keyed riskId => code => entry.
     *
     * One query for the whole register. The inner grouping picks, per object
     * and per measure, the latest period ending on or before the target — the
     * carry-forward rule — and the outer join fetches that row's value.
     *
     * @param  list<int>  $riskIds
     * @return array<int, array<string, array{value: float, currency: ?string, period_id: int, measured_at: string}>>
     */
    public function valuesAsOf(Period $period, array $riskIds, ?int $organizationId = null): array
    {
        $organizationId = $organizationId ?? TenantContext::organizationId();

        if ($riskIds === []) {
            return [];
        }

        $measureIds = Measure::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereIn('code', MeasureCatalog::riskMeasureCodes())
            ->pluck('code', 'id');

        if ($measureIds->isEmpty()) {
            return [];
        }

        $objects = DB::table('objects')
            ->where('source_model_type', 'risk')
            ->whereIn('source_model_id', $riskIds)
            ->pluck('source_model_id', 'id');

        if ($objects->isEmpty()) {
            return [];
        }

        $latest = DB::table('measure_values as mv')
            ->join('periods as p', 'p.id', '=', 'mv.period_id')
            ->select('mv.object_id', 'mv.measure_id', DB::raw('MAX(p.end_date) as max_end'))
            ->where('mv.organization_id', $organizationId)
            ->where('mv.scenario', 'actual')
            ->whereIn('mv.object_id', $objects->keys()->all())
            ->whereIn('mv.measure_id', $measureIds->keys()->all())
            ->where('p.end_date', '<=', DateBounds::endOfDay($period->end_date))
            ->groupBy('mv.object_id', 'mv.measure_id');

        $rows = DB::table('measure_values as mv')
            ->join('periods as p', 'p.id', '=', 'mv.period_id')
            ->joinSub($latest, 'latest', function ($join) {
                $join->on('latest.object_id', '=', 'mv.object_id')
                    ->on('latest.measure_id', '=', 'mv.measure_id')
                    ->on('latest.max_end', '=', 'p.end_date');
            })
            ->where('mv.organization_id', $organizationId)
            ->where('mv.scenario', 'actual')
            ->get(['mv.object_id', 'mv.measure_id', 'mv.value', 'mv.currency_code', 'mv.period_id', 'p.end_date']);

        $byRisk = [];

        foreach ($rows as $row) {
            $riskId = (int) ($objects[$row->object_id] ?? 0);
            $code = $measureIds[$row->measure_id] ?? null;

            if ($riskId === 0 || $code === null) {
                continue;
            }

            $byRisk[$riskId][$code] = [
                'value' => (float) $row->value,
                'currency' => $row->currency_code,
                'period_id' => (int) $row->period_id,
                'measured_at' => (string) $row->end_date,
            ];
        }

        return $byRisk;
    }

    /**
     * A measure's trend across periods for many risks, in one query.
     *
     * riskId => [periodId => value], with periods in the order supplied. This
     * is what the animated heat map and the 12-period register trend read; it
     * is a single index scan over
     * measure_values(organization_id, period_id, measure_id).
     *
     * @param  list<int>  $riskIds
     * @param  list<int>|Collection<int, Period>  $periods
     * @return array<int, array<int, float>>
     */
    public function trend(
        string $measureCode,
        array $riskIds,
        array|Collection $periods,
        ?int $organizationId = null
    ): array {
        $organizationId = $organizationId ?? TenantContext::organizationId();

        $periodIds = $periods instanceof Collection
            ? $periods->pluck('id')->all()
            : $periods;

        if ($riskIds === [] || $periodIds === []) {
            return [];
        }

        $measureId = Measure::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', $measureCode)
            ->value('id');

        if ($measureId === null) {
            return [];
        }

        $objects = DB::table('objects')
            ->where('source_model_type', 'risk')
            ->whereIn('source_model_id', $riskIds)
            ->pluck('source_model_id', 'id');

        if ($objects->isEmpty()) {
            return [];
        }

        $rows = DB::table('measure_values')
            ->where('organization_id', $organizationId)
            ->where('measure_id', $measureId)
            ->where('scenario', 'actual')
            ->whereIn('period_id', $periodIds)
            ->whereIn('object_id', $objects->keys()->all())
            ->get(['object_id', 'period_id', 'value']);

        $series = [];

        foreach ($rows as $row) {
            $riskId = (int) ($objects[$row->object_id] ?? 0);

            if ($riskId !== 0) {
                $series[$riskId][(int) $row->period_id] = (float) $row->value;
            }
        }

        return $series;
    }

    /**
     * The register's rating distribution as at a period.
     *
     * @return array<string, int>
     */
    public function ratingDistributionAsOf(Period $period, ?int $organizationId = null): array
    {
        $distribution = ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0, 'Unrated' => 0];

        foreach ($this->asOf($period, [], $organizationId) as $risk) {
            $rating = $risk->residual_rating ?: $risk->inherent_rating ?: 'Unrated';
            $distribution[$rating] = ($distribution[$rating] ?? 0) + 1;
        }

        return $distribution;
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    private function baseQuery(array $filters, int $organizationId)
    {
        $query = Risk::withoutGlobalScopes()->where('organization_id', $organizationId)->whereNull('deleted_at');

        if (isset($filters['status'])) {
            $query->whereIn('status', (array) $filters['status']);
        }

        foreach (['category_id', 'node_id', 'business_unit_id', 'risk_owner_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        return $query->orderBy('risk_code');
    }

    /**
     * @param  array<string, array{value: float, currency: ?string, period_id: int, measured_at: string}>  $values
     */
    private function overlay(Risk $risk, array $values, Period $period): void
    {
        $map = [
            MeasureCatalog::RISK_INHERENT_LIKELIHOOD => 'inherent_likelihood',
            MeasureCatalog::RISK_INHERENT_IMPACT => 'inherent_impact',
            MeasureCatalog::RISK_INHERENT_SCORE => 'inherent_score',
            MeasureCatalog::RISK_RESIDUAL_LIKELIHOOD => 'residual_likelihood',
            MeasureCatalog::RISK_RESIDUAL_IMPACT => 'residual_impact',
            MeasureCatalog::RISK_RESIDUAL_SCORE => 'residual_score',
            MeasureCatalog::RISK_CONTROL_EFFECTIVENESS => 'control_effectiveness_pct',
        ];

        $scoring = app(\App\Services\RiskScoringService::class);
        $measuredAt = null;

        foreach ($map as $code => $column) {
            if (! isset($values[$code])) {
                // No value on or before the period: the risk had not been
                // assessed yet. Null rather than the current column, which
                // would leak today's number into a historic view.
                $risk->setAttribute($column, null);

                continue;
            }

            $risk->setAttribute($column, $values[$code]['value']);
            $measuredAt = max($measuredAt ?? '', $values[$code]['measured_at']);
        }

        // Ratings are derived, not stored per period: re-deriving them keeps
        // the label and the score consistent even if the organisation has
        // re-banded its matrix since.
        //
        // Read from the raw attribute bag, not through the model. Risk's own
        // inherentScore accessor falls back to likelihood x impact, which for a
        // risk that had not been assessed as at this period would turn two
        // nulls into a score of 0 and a rating of Low. Callers wanting "was
        // this measured at all" should test as_of_measured_at, which is null
        // exactly when nothing was.
        $raw = fn (string $column) => $risk->getAttributes()[$column] ?? null;

        $risk->setAttribute(
            'inherent_rating',
            $raw('inherent_score') === null ? null : $scoring->calculateRating((int) round((float) $raw('inherent_score')))
        );
        $risk->setAttribute(
            'residual_rating',
            $raw('residual_score') === null ? null : $scoring->calculateRating((int) round((float) $raw('residual_score')))
        );

        if (isset($values[MeasureCatalog::RISK_FINANCIAL_EXPOSURE])) {
            $entry = $values[MeasureCatalog::RISK_FINANCIAL_EXPOSURE];
            $risk->setAttribute(
                'financial_exposure_ngn',
                \App\Services\CurrencyService::toMajor((int) $entry['value'], $entry['currency'] ?? 'NGN')
            );
        }

        $risk->setAttribute('as_of_period_id', $period->id);
        $risk->setAttribute('as_of_period_code', $period->code);
        $risk->setAttribute('as_of_measured_at', $measuredAt ?: null);

        // Nothing here is a change to persist. syncOriginal keeps an accidental
        // save() from writing a historic snapshot over the current row.
        $risk->syncOriginal();
    }
}

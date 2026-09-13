<?php

namespace App\Services;

use App\Models\ControlTest;
use App\Models\KeyRiskIndicator;
use App\Models\KriMeasurement;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\TreatmentPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Forward view of the organisation's risk position, computed entirely from its
 * own records.
 *
 * This service replaces the previous AiDataService, which invented its trend
 * line, its "predictions", its escalation probabilities and its model accuracy
 * metrics with mt_rand() and hardcoded constants. Nothing here is invented:
 * every figure this class returns traces to a query, and the queries are
 * documented in docs/ai-number-provenance.md.
 *
 * Two deliberate limits on what is claimed:
 *
 *  1. The forward numbers are a PROJECTION, not a prediction. They are an
 *     ordinary-least-squares extrapolation of the observed monthly mean
 *     residual score. The band around them is the standard error of prediction
 *     from that fit — a computable quantity — and is reported as such rather
 *     than dressed up as a confidence percentage. There is no model, so there
 *     is no model accuracy, precision, recall or AUC to report.
 *
 *  2. The watchlist ranks risks by an OBSERVED score movement between two
 *     dated assessments, not by a probability of escalating. A probability
 *     would require a fitted hazard model and a labelled outcome history that
 *     this product does not have.
 */
class RiskForecastService
{
    /**
     * A fit needs at least this many months carrying assessments before a
     * straight line through them means anything. Two points always fit a line
     * perfectly and would report a zero-width band, which is worse than saying
     * nothing.
     */
    private const MIN_POINTS_FOR_FIT = 3;

    public function __construct(
        private readonly int $historyMonths = 12,
        private readonly int $horizonMonths = 3,
    ) {}

    /**
     * @return array{
     *     history: list<array<string,mixed>>,
     *     projection: list<array<string,mixed>>,
     *     fit: array<string,mixed>,
     *     signals: array<string,mixed>,
     *     watchlist: list<array<string,mixed>>,
     *     inputs: array<string,mixed>,
     *     as_at: string
     * }
     */
    public function forecast(int $orgId): array
    {
        $months = $this->monthWindow();

        $history = $this->residualScoreHistory($orgId, $months);
        $fit = $this->fitTrend($history);
        $projection = $this->project($fit, $months, $history);

        return [
            'history' => $history,
            'projection' => $projection,
            'fit' => $fit,
            'signals' => [
                'treatment_velocity' => $this->treatmentVelocity($orgId, $months),
                'kri_breach_frequency' => $this->kriBreachFrequency($orgId, $months),
                'control_test_failure_rate' => $this->controlTestFailureRate($orgId, $months),
            ],
            'watchlist' => $this->watchlist($orgId),
            'inputs' => $this->inputCounts($orgId, $months),
            'as_at' => CarbonImmutable::now()->toDateTimeString(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Window */
    /* ------------------------------------------------------------------ */

    /**
     * The history window, oldest first, as month-start immutables.
     *
     * @return list<CarbonImmutable>
     */
    private function monthWindow(): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($this->historyMonths - 1);

        return array_map(
            fn (int $i) => $start->addMonths($i),
            range(0, $this->historyMonths - 1)
        );
    }

    /* ------------------------------------------------------------------ */
    /*  History: observed monthly mean scores from risk_assessments */
    /* ------------------------------------------------------------------ */

    /**
     * Monthly mean inherent (overall_score) and residual (residual_score) from
     * the assessments actually dated in each month.
     *
     * Months with no assessment carry nulls rather than a carried-forward or
     * interpolated value: an empty month is a fact about the assessment
     * programme, and hiding it would make the trend line look better-evidenced
     * than it is.
     *
     * @param  list<CarbonImmutable>  $months
     * @return list<array<string,mixed>>
     */
    private function residualScoreHistory(int $orgId, array $months): array
    {
        $first = $months[0];
        $last = end($months)->endOfMonth();

        $rows = RiskAssessment::query()
            ->where('organization_id', $orgId)
            ->whereBetween('assessment_date', [$first->toDateString(), $last->toDateString()])
            ->get(['assessment_date', 'overall_score', 'residual_score']);

        $byMonth = $rows->groupBy(fn ($r) => CarbonImmutable::parse($r->assessment_date)->format('Y-m'));

        $series = [];
        foreach ($months as $month) {
            $key = $month->format('Y-m');
            $bucket = $byMonth->get($key);

            $residuals = $bucket?->pluck('residual_score')->filter(fn ($v) => $v !== null);
            $inherents = $bucket?->pluck('overall_score')->filter(fn ($v) => $v !== null);

            $series[] = [
                'month' => $key,
                'label' => $month->format('M Y'),
                'label_short' => $month->format('M'),
                'assessment_count' => $bucket?->count() ?? 0,
                'mean_residual_score' => ($residuals && $residuals->isNotEmpty())
                    ? round((float) $residuals->avg(), 2)
                    : null,
                'mean_inherent_score' => ($inherents && $inherents->isNotEmpty())
                    ? round((float) $inherents->avg(), 2)
                    : null,
            ];
        }

        return $series;
    }

    /* ------------------------------------------------------------------ */
    /*  Trend fit */
    /* ------------------------------------------------------------------ */

    /**
     * Ordinary least squares of mean residual score on month index.
     *
     * Returns the slope and intercept plus the residual standard error, which
     * is what makes an honest band possible downstream. When there are too few
     * populated months the method says so instead of fitting something
     * meaningless.
     *
     * @param  list<array<string,mixed>>  $history
     * @return array<string,mixed>
     */
    private function fitTrend(array $history): array
    {
        $points = [];
        foreach ($history as $i => $row) {
            if ($row['mean_residual_score'] !== null) {
                $points[] = ['x' => (float) $i, 'y' => (float) $row['mean_residual_score']];
            }
        }

        $n = count($points);

        if ($n < self::MIN_POINTS_FOR_FIT) {
            return [
                'available' => false,
                'reason' => sprintf(
                    'Only %d of the last %d months carry a dated risk assessment. A trend line needs at least %d.',
                    $n,
                    $this->historyMonths,
                    self::MIN_POINTS_FOR_FIT
                ),
                'method' => 'ordinary least squares on monthly mean residual score',
                'n' => $n,
            ];
        }

        $meanX = array_sum(array_column($points, 'x')) / $n;
        $meanY = array_sum(array_column($points, 'y')) / $n;

        $sxx = 0.0;
        $sxy = 0.0;
        foreach ($points as $p) {
            $sxx += ($p['x'] - $meanX) ** 2;
            $sxy += ($p['x'] - $meanX) * ($p['y'] - $meanY);
        }

        // Every assessment landed in a single month: the x values are identical,
        // so there is no slope to estimate.
        if ($sxx <= 0.0) {
            return [
                'available' => false,
                'reason' => 'All assessments fall in a single month, so no trend over time can be estimated.',
                'method' => 'ordinary least squares on monthly mean residual score',
                'n' => $n,
            ];
        }

        $slope = $sxy / $sxx;
        $intercept = $meanY - ($slope * $meanX);

        $sse = 0.0;
        foreach ($points as $p) {
            $sse += ($p['y'] - ($intercept + $slope * $p['x'])) ** 2;
        }

        // Residual standard error. With n == 2 the denominator would be zero;
        // MIN_POINTS_FOR_FIT already rules that out.
        $residualStdError = sqrt($sse / ($n - 2));

        return [
            'available' => true,
            'method' => 'ordinary least squares on monthly mean residual score',
            'n' => $n,
            'slope_per_month' => round($slope, 4),
            'intercept' => round($intercept, 4),
            'mean_x' => $meanX,
            'sxx' => $sxx,
            'residual_std_error' => round($residualStdError, 4),
            'direction' => match (true) {
                $slope > 0.05 => 'rising',
                $slope < -0.05 => 'falling',
                default => 'flat',
            },
        ];
    }

    /**
     * Extrapolate the fitted line over the horizon.
     *
     * The band is the standard error of prediction for a new observation at
     * x0: s * sqrt(1 + 1/n + (x0 - x̄)² / Sxx). It widens with distance from the
     * observed months, which is the point — it is reported as a range, without
     * a confidence percentage attached, because a percentage would imply
     * distributional assumptions nobody has checked.
     *
     * @param  array<string,mixed>  $fit
     * @param  list<CarbonImmutable>  $months
     * @param  list<array<string,mixed>>  $history
     * @return list<array<string,mixed>>
     */
    private function project(array $fit, array $months, array $history): array
    {
        if (! ($fit['available'] ?? false)) {
            return [];
        }

        $lastMonth = end($months);
        $baseIndex = count($history) - 1;

        $projection = [];
        for ($h = 1; $h <= $this->horizonMonths; $h++) {
            $x0 = (float) ($baseIndex + $h);
            $month = $lastMonth->addMonths($h);

            $centre = $fit['intercept'] + ($fit['slope_per_month'] * $x0);

            $sePrediction = $fit['residual_std_error'] * sqrt(
                1 + (1 / $fit['n']) + (($x0 - $fit['mean_x']) ** 2 / $fit['sxx'])
            );

            $projection[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'label_short' => $month->format('M'),
                'horizon_months' => $h,
                'projected_mean_residual_score' => round(max(0, $centre), 2),
                'range_low' => round(max(0, $centre - $sePrediction), 2),
                'range_high' => round($centre + $sePrediction, 2),
                'range_basis' => 'standard error of prediction from the fitted line',
            ];
        }

        return $projection;
    }

    /* ------------------------------------------------------------------ */
    /*  Signal 1: overdue treatment velocity */
    /* ------------------------------------------------------------------ */

    /**
     * How fast treatment plans fall overdue relative to how fast they close.
     *
     * There is no historical status snapshot in the schema, so this is derived
     * from stored dates only: a plan counts as "became overdue in month M" when
     * its target date fell in M and it was not completed on or before that
     * date. A plan counts as "closed in month M" when its completion date fell
     * in M. Net velocity is the difference — positive means the backlog grew.
     *
     * @param  list<CarbonImmutable>  $months
     * @return array<string,mixed>
     */
    private function treatmentVelocity(int $orgId, array $months): array
    {
        $first = $months[0];
        $last = end($months)->endOfMonth();

        $plans = TreatmentPlan::query()
            ->where('organization_id', $orgId)
            ->get(['target_date', 'completion_date', 'status']);

        $becameOverdue = array_fill_keys(array_map(fn ($m) => $m->format('Y-m'), $months), 0);
        $closed = $becameOverdue;

        foreach ($plans as $plan) {
            $completedOn = $plan->completion_date;
            $completedOn = $completedOn ? CarbonImmutable::parse($completedOn) : null;

            if ($plan->target_date) {
                $target = CarbonImmutable::parse($plan->target_date);
                $missed = $completedOn === null || $completedOn->greaterThan($target);

                if ($missed && $target->betweenIncluded($first, $last)) {
                    $key = $target->format('Y-m');
                    if (isset($becameOverdue[$key])) {
                        $becameOverdue[$key]++;
                    }
                }
            }

            if ($completedOn && $completedOn->betweenIncluded($first, $last)) {
                $key = $completedOn->format('Y-m');
                if (isset($closed[$key])) {
                    $closed[$key]++;
                }
            }
        }

        $series = [];
        foreach ($months as $month) {
            $key = $month->format('Y-m');
            $series[] = [
                'month' => $key,
                'label_short' => $month->format('M'),
                'became_overdue' => $becameOverdue[$key],
                'closed' => $closed[$key],
                'net' => $becameOverdue[$key] - $closed[$key],
            ];
        }

        $currentlyOverdue = TreatmentPlan::query()
            ->where('organization_id', $orgId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('target_date')
            ->whereDate('target_date', '<', CarbonImmutable::now()->toDateString())
            ->count();

        $totalNet = array_sum(array_column($series, 'net'));

        return [
            'series' => $series,
            'currently_overdue' => $currentlyOverdue,
            'net_over_window' => $totalNet,
            'net_per_month' => round($totalNet / max(1, count($months)), 2),
            'definition' => 'Plans whose target date passed unmet in the month, less plans closed in the month.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Signal 2: KRI breach frequency */
    /* ------------------------------------------------------------------ */

    /**
     * Share of KRI measurements recorded red, by month.
     *
     * kri_measurements has no organization_id of its own, so the tenant filter
     * is applied through the parent indicator.
     *
     * @param  list<CarbonImmutable>  $months
     * @return array<string,mixed>
     */
    private function kriBreachFrequency(int $orgId, array $months): array
    {
        $first = $months[0];
        $last = end($months)->endOfMonth();

        $kriIds = KeyRiskIndicator::query()
            ->where('organization_id', $orgId)
            ->pluck('id');

        $measurements = KriMeasurement::query()
            ->whereIn('kri_id', $kriIds)
            ->whereBetween('measurement_date', [$first->toDateString(), $last->toDateString()])
            ->get(['measurement_date', 'status']);

        $byMonth = $measurements->groupBy(
            fn ($m) => CarbonImmutable::parse($m->measurement_date)->format('Y-m')
        );

        $series = [];
        $totalMeasurements = 0;
        $totalBreaches = 0;

        foreach ($months as $month) {
            $key = $month->format('Y-m');
            $bucket = $byMonth->get($key);
            $count = $bucket?->count() ?? 0;
            $red = $bucket?->where('status', 'red')->count() ?? 0;

            $totalMeasurements += $count;
            $totalBreaches += $red;

            $series[] = [
                'month' => $key,
                'label_short' => $month->format('M'),
                'measurements' => $count,
                'breaches' => $red,
                'breach_rate_pct' => $count > 0 ? round($red / $count * 100, 1) : null,
            ];
        }

        return [
            'series' => $series,
            'measurements_in_window' => $totalMeasurements,
            'breaches_in_window' => $totalBreaches,
            'breach_rate_pct' => $totalMeasurements > 0
                ? round($totalBreaches / $totalMeasurements * 100, 1)
                : null,
            'currently_red' => KeyRiskIndicator::query()
                ->where('organization_id', $orgId)
                ->where('current_status', 'red')
                ->count(),
            'definition' => 'Measurements recorded with a red status, over all measurements recorded in the month.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Signal 3: control test failure rate */
    /* ------------------------------------------------------------------ */

    /**
     * Share of completed control tests returning less than fully effective.
     *
     * @param  list<CarbonImmutable>  $months
     * @return array<string,mixed>
     */
    private function controlTestFailureRate(int $orgId, array $months): array
    {
        $first = $months[0];
        $last = end($months)->endOfMonth();

        $tests = ControlTest::query()
            ->where('organization_id', $orgId)
            ->whereNotNull('completed_date')
            ->whereBetween('completed_date', [$first->toDateString(), $last->toDateString()])
            ->get(['completed_date', 'result']);

        $byMonth = $tests->groupBy(
            fn ($t) => CarbonImmutable::parse($t->completed_date)->format('Y-m')
        );

        $failing = ['ineffective', 'partially_effective'];

        $series = [];
        $totalTests = 0;
        $totalFailures = 0;

        foreach ($months as $month) {
            $key = $month->format('Y-m');
            $bucket = $byMonth->get($key);
            $count = $bucket?->count() ?? 0;
            $failed = $bucket?->whereIn('result', $failing)->count() ?? 0;

            $totalTests += $count;
            $totalFailures += $failed;

            $series[] = [
                'month' => $key,
                'label_short' => $month->format('M'),
                'tests_completed' => $count,
                'failures' => $failed,
                'failure_rate_pct' => $count > 0 ? round($failed / $count * 100, 1) : null,
            ];
        }

        return [
            'series' => $series,
            'tests_in_window' => $totalTests,
            'failures_in_window' => $totalFailures,
            'failure_rate_pct' => $totalTests > 0
                ? round($totalFailures / $totalTests * 100, 1)
                : null,
            'definition' => 'Completed tests returning ineffective or partially effective, over all tests completed in the month.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Watchlist: observed movement, not predicted probability */
    /* ------------------------------------------------------------------ */

    /**
     * Risks whose residual score moved between their two most recent dated
     * assessments, worst deterioration first.
     *
     * This is a measurement, not a forecast. Each row carries the two scores
     * and the two dates that produced it so the reader can check it.
     *
     * @return list<array<string,mixed>>
     */
    private function watchlist(int $orgId, int $limit = 10): array
    {
        $assessments = RiskAssessment::query()
            ->where('organization_id', $orgId)
            ->whereNotNull('residual_score')
            ->orderBy('risk_id')
            ->orderByDesc('assessment_date')
            ->orderByDesc('id')
            ->get(['risk_id', 'assessment_date', 'residual_score', 'residual_rating']);

        $movements = [];
        foreach ($assessments->groupBy('risk_id') as $riskId => $forRisk) {
            if ($forRisk->count() < 2) {
                continue;
            }

            $current = $forRisk[0];
            $previous = $forRisk[1];
            $delta = (int) $current->residual_score - (int) $previous->residual_score;

            if ($delta === 0) {
                continue;
            }

            $movements[] = [
                'risk_id' => (int) $riskId,
                'previous_score' => (int) $previous->residual_score,
                'previous_rating' => $previous->residual_rating,
                'previous_date' => CarbonImmutable::parse($previous->assessment_date)->toDateString(),
                'current_score' => (int) $current->residual_score,
                'current_rating' => $current->residual_rating,
                'current_date' => CarbonImmutable::parse($current->assessment_date)->toDateString(),
                'delta' => $delta,
            ];
        }

        usort($movements, fn ($a, $b) => $b['delta'] <=> $a['delta']);
        $movements = array_slice($movements, 0, $limit);

        if ($movements === []) {
            return [];
        }

        $risks = Risk::query()
            ->where('organization_id', $orgId)
            ->whereIn('id', array_column($movements, 'risk_id'))
            ->get(['id', 'risk_code', 'title'])
            ->keyBy('id');

        $openTreatments = TreatmentPlan::query()
            ->where('organization_id', $orgId)
            ->whereIn('risk_id', array_column($movements, 'risk_id'))
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('target_date')
            ->whereDate('target_date', '<', CarbonImmutable::now()->toDateString())
            ->select('risk_id', DB::raw('COUNT(*) as c'))
            ->groupBy('risk_id')
            ->pluck('c', 'risk_id');

        return array_values(array_filter(array_map(function (array $m) use ($risks, $openTreatments) {
            $risk = $risks->get($m['risk_id']);
            if (! $risk) {
                return null;
            }

            return $m + [
                'risk_code' => $risk->risk_code,
                'title' => $risk->title,
                'overdue_treatments' => (int) ($openTreatments[$m['risk_id']] ?? 0),
                'direction' => $m['delta'] > 0 ? 'deteriorating' : 'improving',
            ];
        }, $movements)));
    }

    /* ------------------------------------------------------------------ */
    /*  Input disclosure */
    /* ------------------------------------------------------------------ */

    /**
     * What the figures above were computed from. Shown on the screen so a
     * reader can judge whether the window carries enough evidence.
     *
     * @param  list<CarbonImmutable>  $months
     * @return array<string,mixed>
     */
    private function inputCounts(int $orgId, array $months): array
    {
        $first = $months[0];
        $last = end($months)->endOfMonth();

        return [
            'window_start' => $first->toDateString(),
            'window_end' => $last->toDateString(),
            'history_months' => $this->historyMonths,
            'horizon_months' => $this->horizonMonths,
            'assessments_in_window' => RiskAssessment::query()
                ->where('organization_id', $orgId)
                ->whereBetween('assessment_date', [$first->toDateString(), $last->toDateString()])
                ->count(),
            'active_risks' => Risk::query()
                ->where('organization_id', $orgId)
                ->where('status', 'active')
                ->count(),
        ];
    }
}

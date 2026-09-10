<?php

namespace App\Services\Analysis;

use App\Models\Period;
use App\Models\Risk;
use App\Models\ScoringProfile;
use App\Models\TreatmentPlan;
use App\Repositories\RiskRepository;
use App\Services\PeriodService;
use App\Services\RiskScoringService;
use App\Support\Measures\MeasureCatalog;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The register's shape over time, for the analysis charts (Phase 5.1).
 *
 * Lifted out of AnalysisController's five private builders —
 * buildRiskMovementData(), buildRatingTrendData(), buildScoreTrendData(),
 * buildCategoryTrendData() and buildTreatmentTrendData() — which between them
 * ran one whole-register `->get()` PER BUCKET and filtered in PHP: four loads
 * for the quarterly chart, and thirteen loads per chart × four charts for a
 * default twelve-month trends page. The register is now loaded ONCE per screen
 * and bucketed in memory.
 *
 * THE BANDS COME FROM THE TENANT'S SCORING PROFILE. The controller hard-coded
 * `>= 20 / >= 12 / >= 5`, which are the default 5×5 profile's edges (Low 1-4,
 * Medium 5-11, High 12-19, Critical 20-25) and no other profile's. A bank on a
 * 4×4 or 6×6 matrix — which the builder offers and ScoringProfileTemplates
 * generates bands for — had its movement chart drawn against a scale it does
 * not use. Characterisation/RiskMovementCharacterisationTest pins that the two
 * agree exactly for the default profile, so this is a no-op there and a
 * correction everywhere else.
 *
 * IT IS A REAL POINT-IN-TIME READ. The controller selected
 * `where('created_at', '<=', $end)` and then read each risk's CURRENT score, so
 * a risk rated Low in January and Critical in June was counted Critical in
 * January too. Every one of these series could therefore only slope upward as
 * the register grew, and NONE of them could ever show a risk moving between
 * bands — on charts titled "Risk Movement" and "Rating Trend". The scores now
 * come from `RiskRepository::valuesAsOf()`, the same carry-forward read the
 * register's own as-at view uses.
 *
 * EXISTENCE IS `date_identified ?? created_at`, and that is why this does not
 * simply call `RiskRepository::asOf()`. That method treats a null
 * `date_identified` as "existed in every period" — the safe reading for a
 * register listing, where omitting a risk understates it. Here it would put
 * risks in quarters before they were entered and overstate every historic
 * bucket, so the row's own creation timestamp is the fallback.
 */
class RiskMovementService
{
    /** How many quarters the movement chart looks back, inclusive of the current one. */
    public const MOVEMENT_QUARTERS = 4;

    public function __construct(
        private readonly RiskScoringService $scoring,
        private readonly RiskRepository $risks,
        private readonly PeriodService $periods,
    ) {}

    /**
     * Quarterly counts per rating band, for the heat map's movement chart.
     *
     * @return array{labels: list<string>, critical: list<int>, high: list<int>, medium: list<int>, low: list<int>}
     */
    public function quarterly(?int $organizationId = null): array
    {
        $organizationId = $organizationId ?? TenantContext::organizationId();

        $buckets = [];

        for ($q = self::MOVEMENT_QUARTERS - 1; $q >= 0; $q--) {
            $start = now()->subQuarters($q)->startOfQuarter();

            $buckets[] = [
                'label' => 'Q'.$start->quarter.' '.$start->format('Y'),
                'boundary' => now()->subQuarters($q)->endOfQuarter(),
                'type' => 'quarter',
            ];
        }

        return $this->bandSeries($buckets, $organizationId);
    }

    /**
     * Monthly counts per rating band across a window.
     *
     * @return array{labels: list<string>, critical: list<int>, high: list<int>, medium: list<int>, low: list<int>}
     */
    public function monthlyRatings(CarbonInterface $from, ?CarbonInterface $to = null, ?int $organizationId = null): array
    {
        return $this->bandSeries($this->months($from, $to), $organizationId ?? TenantContext::organizationId());
    }

    /**
     * Monthly average inherent score across a window.
     *
     * @return array{labels: list<string>, values: list<float>}
     */
    public function monthlyAverageScore(CarbonInterface $from, ?CarbonInterface $to = null, ?int $organizationId = null): array
    {
        $organizationId = $organizationId ?? TenantContext::organizationId();
        $register = $this->register($organizationId);

        $labels = [];
        $values = [];

        foreach ($this->months($from, $to) as $bucket) {
            $scores = $this->scoresAsAt($register, $bucket, $organizationId);

            $labels[] = $bucket['label'];
            // An empty month is 0, as it always has been — the chart draws a
            // continuous line and a null would break it. It is a count of
            // nothing, not an average of nothing.
            $values[] = $scores === [] ? 0.0 : round(array_sum($scores) / count($scores), 1);
        }

        return compact('labels', 'values');
    }

    /**
     * The rating band a score falls in, by the tenant's profile.
     *
     * Returns one of critical/high/medium/low — the four series the charts
     * draw. A profile whose bands are named differently maps by position, so a
     * five-band profile's top band is still `critical`; the charts have four
     * series and always have.
     */
    public function bandKeyFor(int|float|null $score, ?ScoringProfile $profile = null): string
    {
        $profile = $profile ?? $this->scoring->profileFor();
        $band = $profile->bandFor($score);

        if ($band === null) {
            return 'low';
        }

        $code = strtolower((string) ($band['code'] ?? ''));

        if (in_array($code, ['critical', 'high', 'medium', 'low'], true)) {
            return $code;
        }

        // Positional fallback for a renamed band set: highest band is
        // critical, lowest is low.
        $bands = array_values($profile->rating_bands ?? []);
        $index = array_search($band, $bands, true);
        $lastIndex = count($bands) - 1;

        return match (true) {
            $index === $lastIndex => 'critical',
            $index === 0 => 'low',
            $index >= $lastIndex - 1 => 'high',
            default => 'medium',
        };
    }

    /**
     * Treatment plans completed in each month, and plans running late at each
     * month end.
     *
     * WHAT THIS REPLACES. `buildTreatmentTrendData()` drew a chart titled
     * "Treatment Progress" out of the RISKS table and touched no treatment plan
     * at all. Its `completed` series counted risks whose status was
     * closed/retired and whose `updated_at` fell in the month — so a risk
     * closed in January and edited in June counted as completed in June, and
     * closing a risk is not completing a treatment. Its `overdue` series
     * counted active risks rated High or Critical created more than six months
     * ago; the code's own comment called that "simplified". Nothing in it was
     * a treatment, and nothing in it was progress.
     *
     * `treatment_plans` carries `completion_date`, `target_date` and a status
     * list, and `treatments:check-overdue` already maintains the overdue state
     * nightly. Both series are read from it now.
     *
     * @return array{labels: list<string>, completed: list<int>, overdue: list<int>}
     */
    public function monthlyTreatmentProgress(CarbonInterface $from, ?CarbonInterface $to = null, ?int $organizationId = null): array
    {
        $organizationId = $organizationId ?? TenantContext::organizationId();

        $plans = TreatmentPlan::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->get(['id', 'status', 'target_date', 'completion_date', 'created_at']);

        $labels = [];
        $completed = [];
        $overdue = [];

        foreach ($this->months($from, $to) as $bucket) {
            $monthStart = $bucket['boundary']->copy()->startOfMonth();
            $monthEnd = $bucket['boundary'];

            $labels[] = $bucket['label'];

            // Completed IN the month, by the date the completion was recorded.
            $completed[] = $plans->filter(fn (TreatmentPlan $plan) => $plan->completion_date !== null
                && $plan->completion_date >= $monthStart
                && $plan->completion_date <= $monthEnd)->count();

            // Running late AT the month end: past its target, and not finished
            // by then. A plan nobody has started is not late — the same rule
            // RUNNING_STATUSES states for the nightly sweep.
            $overdue[] = $plans->filter(function (TreatmentPlan $plan) use ($monthEnd) {
                if ($plan->target_date === null || $plan->target_date >= $monthEnd) {
                    return false;
                }

                if ($plan->completion_date !== null && $plan->completion_date <= $monthEnd) {
                    return false;
                }

                return in_array($plan->status, [...TreatmentPlan::RUNNING_STATUSES, 'overdue'], true);
            })->count();
        }

        return compact('labels', 'completed', 'overdue');
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  list<array{label: string, boundary: CarbonInterface, type: string}>  $buckets
     * @return array{labels: list<string>, critical: list<int>, high: list<int>, medium: list<int>, low: list<int>}
     */
    private function bandSeries(array $buckets, int $organizationId): array
    {
        $register = $this->register($organizationId);
        $profile = $this->scoring->profileFor(organizationId: $organizationId);

        $labels = [];
        $critical = [];
        $high = [];
        $medium = [];
        $low = [];

        foreach ($buckets as $bucket) {
            $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

            foreach ($this->scoresAsAt($register, $bucket, $organizationId) as $score) {
                $counts[$this->bandKeyFor($score, $profile)]++;
            }

            $labels[] = $bucket['label'];
            $critical[] = $counts['critical'];
            $high[] = $counts['high'];
            $medium[] = $counts['medium'];
            $low[] = $counts['low'];
        }

        return compact('labels', 'critical', 'high', 'medium', 'low');
    }

    /**
     * The active register, loaded ONCE.
     *
     * NOT node-scoped, deliberately, and unchanged from the controller: these
     * are counts and averages per bucket — roll-up calculations with no record
     * in them to disclose. See the class comment on AnalysisController.
     *
     * @return Collection<int, Risk>
     */
    private function register(int $organizationId): Collection
    {
        return Risk::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->get(['id', 'created_at', 'date_identified', 'inherent_score', 'inherent_likelihood', 'inherent_impact', 'inherent_rating', 'category_id']);
    }

    /**
     * The inherent score of every risk that existed at a bucket boundary, as it
     * stood THEN.
     *
     * A risk that existed but had not been assessed as at the boundary has no
     * measured value; it falls back to the row's own score rather than being
     * dropped, because a register entry with no assessment is still an entry
     * and dropping it would understate the bucket.
     *
     * @param  Collection<int, Risk>  $register
     * @param  array{label: string, boundary: CarbonInterface, type: string}  $bucket
     * @return list<int>
     */
    private function scoresAsAt(Collection $register, array $bucket, int $organizationId): array
    {
        $existing = $register->filter(
            fn (Risk $risk) => $this->existedBy($risk, $bucket['boundary'])
        );

        if ($existing->isEmpty()) {
            return [];
        }

        $values = $this->measuredScores($existing->pluck('id')->all(), $bucket, $organizationId);

        return $existing
            ->map(fn (Risk $risk) => $values[$risk->id] ?? $this->currentScoreOf($risk))
            ->values()
            ->all();
    }

    /**
     * riskId => inherent score as at the bucket's period, for those measured.
     *
     * @param  list<int>  $riskIds
     * @param  array{label: string, boundary: CarbonInterface, type: string}  $bucket
     * @return array<int, int>
     */
    private function measuredScores(array $riskIds, array $bucket, int $organizationId): array
    {
        $period = $this->periodFor($bucket, $organizationId);

        if ($period === null) {
            return [];
        }

        $scores = [];

        foreach ($this->risks->valuesAsOf($period, $riskIds, $organizationId) as $riskId => $measures) {
            $score = $measures[MeasureCatalog::RISK_INHERENT_SCORE]['value'] ?? null;

            if ($score === null) {
                $likelihood = $measures[MeasureCatalog::RISK_INHERENT_LIKELIHOOD]['value'] ?? null;
                $impact = $measures[MeasureCatalog::RISK_INHERENT_IMPACT]['value'] ?? null;

                $score = $likelihood !== null && $impact !== null ? $likelihood * $impact : null;
            }

            if ($score !== null) {
                $scores[(int) $riskId] = (int) round((float) $score);
            }
        }

        return $scores;
    }

    /**
     * The period a bucket sits in, or null when this organisation has no
     * calendar for it.
     *
     * resolve() will create the period if the calendar reaches that far, which
     * is right: asking "what did the register look like in Q1" should not
     * depend on whether anyone had opened Q1 in the UI. A tenant with no
     * calendar at all falls back to today's scores rather than an empty chart.
     */
    private function periodFor(array $bucket, int $organizationId): ?Period
    {
        try {
            return $this->periods->resolve(
                $bucket['boundary']->copy()->toImmutable(),
                $bucket['type'],
                $organizationId,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Did this risk exist by the boundary?
     *
     * `date_identified` is when the business says it was found and is the right
     * answer; it is nullable and optional on the create form, so the row's own
     * creation timestamp is the fallback. See the class comment on why
     * RiskRepository::asOf() cannot be used directly here.
     */
    private function existedBy(Risk $risk, CarbonInterface $boundary): bool
    {
        $identified = $risk->date_identified ?? $risk->created_at;

        return $identified !== null && $identified <= $boundary;
    }

    private function currentScoreOf(Risk $risk): int
    {
        return (int) ($risk->inherent_score ?? (($risk->inherent_likelihood ?? 0) * ($risk->inherent_impact ?? 0)));
    }

    /**
     * @return list<array{label: string, boundary: CarbonInterface, type: string}>
     */
    private function months(CarbonInterface $from, ?CarbonInterface $to = null): array
    {
        $current = $from->copy()->startOfMonth();
        $end = ($to ?? now())->copy()->endOfMonth();

        $buckets = [];

        while ($current <= $end) {
            $buckets[] = [
                'label' => $current->format('M Y'),
                'boundary' => $current->copy()->endOfMonth(),
                'type' => 'month',
            ];

            $current = $current->copy()->addMonth();
        }

        return $buckets;
    }
}

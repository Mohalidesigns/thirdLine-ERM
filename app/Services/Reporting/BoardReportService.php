<?php

namespace App\Services\Reporting;

use App\Models\ApprovalRequest;
use App\Models\Control;
use App\Models\IcaapAssessment;
use App\Models\Issue;
use App\Models\LossEvent;
use App\Models\Risk;
use App\Models\TreatmentPlan;
use App\Services\Quantification\IcaapService;
use App\Services\RiskAppetiteService;
use App\Support\Tenancy\TenantContext;

/**
 * The Board report's figures (migration Phase 5.4).
 *
 * Lifted out of ReportController::board(), which was 220 lines of reporting
 * arithmetic inside a controller. Nothing here is changed except the capital
 * adequacy ratio, whose defect is documented at the line that fixes it;
 * Characterisation/BoardReportCharacterisationTest pins every figure and was
 * written against the running Blade screen before the extraction.
 *
 * WHAT THIS REPORT ALREADY GETS RIGHT, and what those assertions defend. An
 * earlier work package found the appetite chart drawing a flat literal 3 as
 * though it were a Board-approved limit; "Appetite Utilization" computed from
 * risk ratings that no appetite statement mentions; a treatment column reading
 * `$risk->treatment_status`, a column that has never existed on `risks`, so
 * every critical risk was badged "In Progress"; and "3.2/5" printed as a risk
 * profile when nothing had been scored. The comments recording each of those
 * travel with the code, because they are why it is shaped as it is.
 */
class BoardReportService
{
    public function __construct(private readonly IcaapService $icaap) {}

    /**
     * @return array<string, mixed>
     */
    public function figures(?int $organizationId = null): array
    {
        $orgId = $organizationId ?? TenantContext::organizationId();

        // Risk appetite: one pass over the declared statements, reused for the
        // breach list, the appetite chart series and the utilization figure.
        // These three used to disagree with each other — breaches came from
        // RiskAppetiteService while the chart drew a flat 3 and "utilization"
        // was computed from risk ratings that no appetite statement mentions.
        $appetiteService = new RiskAppetiteService;
        $appetitePositions = $appetiteService->compareAgainstActual($orgId);
        $appetiteBreaches = $appetiteService->getBreaches($orgId);

        // Declared upper tolerance per category, keyed for the chart. Only
        // categories with a recorded tolerance appear; the rest stay absent so
        // the chart can leave a gap rather than draw a boundary nobody set.
        $declaredTolerance = [];
        foreach ($appetitePositions as $position) {
            if (($position['tolerance_upper'] ?? 0) > 0) {
                $declaredTolerance[$position['category_id']] = (float) $position['tolerance_upper'];
            }
        }

        // Critical risks for board attention. treatmentPlans is eager-loaded
        // because the board table's Treatment column is derived from the real
        // plans on the risk — it used to read `$risk->treatment_status`, a
        // column that has never existed on `risks`, so every critical risk in
        // the product was badged "In Progress" regardless of whether anyone
        // had opened a treatment plan for it.
        $criticalRisksForBoard = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->where('inherent_rating', 'Critical')
            ->with(['category', 'riskOwner', 'treatmentPlans'])
            ->orderByDesc('inherent_score')
            ->get();

        $criticalRisks = $criticalRisksForBoard->count();

        $this->applyTreatmentStatus($criticalRisksForBoard);

        $criticalRisksForBoard = $criticalRisksForBoard->map(fn (Risk $risk) => [
            'id' => $risk->id,
            'risk_code' => $risk->risk_code,
            'title' => $risk->title,
            'category' => $risk->getRelationValue('category')?->name,
            'inherent_rating' => $risk->inherent_rating,
            'inherent_score' => $risk->inherent_score,
            'owner' => $risk->getRelationValue('riskOwner')?->name,
            'derived_treatment_status' => $risk->getAttribute('derived_treatment_status'),
        ])->values();

        $totalActiveRisks = Risk::where('organization_id', $orgId)->where('status', 'active')->count();

        // Appetite utilization: current position against the declared upper
        // tolerance, averaged over the categories that declared one — which is
        // what the phrase means and what RiskAppetiteService already computes
        // per category. Before this it was the share of active risks NOT rated
        // Critical, a number with no relationship to any appetite statement,
        // printed on a tile captioned "Appetite Utilization". Null when no
        // category has a tolerance on record, so the tile can say so.
        $utilizations = array_values(array_filter(
            array_map(
                fn (array $p) => ($p['tolerance_upper'] ?? 0) > 0 ? (float) $p['utilization_pct'] : null,
                $appetitePositions
            ),
            fn (?float $v) => $v !== null
        ));
        $appetiteUtilization = $utilizations === []
            ? null
            : (int) round(array_sum($utilizations) / count($utilizations));
        $appetiteCategoriesWithTolerance = count($utilizations);

        // Control effectiveness. Null — not zero — when no control carries an
        // effectiveness rating, because "0%" and "nobody has tested anything"
        // are different statements and only one of them is true.
        $controlEffectiveness = $this->controlEffectivenessRate($orgId);

        // Average risk score for profile. Null when nothing is scored: a
        // literal "3.2/5" used to be printed in that case, and even the honest
        // "0/5" reads as a measured profile of zero rather than an absent one.
        $avgScore = Risk::where('organization_id', $orgId)->where('status', 'active')->avg('inherent_score');
        $riskProfileScore = $avgScore ? round($avgScore / 5, 1).'/5' : null;

        // Chart data: Risk Profile by Category (radar)
        $risksByCategory = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->selectRaw('category_id, AVG(inherent_score) as avg_inherent, AVG(residual_score) as avg_residual')
            ->groupBy('category_id')
            ->with('category')
            ->get();
        $profileChartData = [
            'labels' => $risksByCategory->map(fn ($r) => $r->category->name ?? 'Unknown')->values()->toArray(),
            'inherent' => $risksByCategory->pluck('avg_inherent')->map(fn ($v) => round($v / 5, 1))->toArray(),
            'residual' => $risksByCategory->pluck('avg_residual')->map(fn ($v) => round(($v ?? 0) / 5, 1))->toArray(),
        ];

        // Chart data: Risk Appetite vs Current Position.
        //
        // The appetite series is the declared upper tolerance for the category,
        // null where the organisation has not declared one, so Chart.js draws a
        // gap instead of a boundary. It was previously a constant 3 for every
        // category — a flat line across the whole chart that looked like a
        // Board-approved limit and was in fact a literal, two lines away from
        // the service that holds the real answer.
        //
        // Both series are on the raw inherent-score scale, which is the scale
        // RiskAppetiteService compares against. The current-position series
        // used to be divided by 5 while the appetite line was not, so the two
        // bars in each pair were never on the same axis to begin with.
        $appetiteChartData = [
            'labels' => $risksByCategory->map(fn ($r) => $r->category->name ?? 'Unknown')->values()->toArray(),
            'appetite' => $risksByCategory
                ->map(fn ($r) => $declaredTolerance[$r->category_id] ?? null)
                ->values()->toArray(),
            'current' => $risksByCategory->pluck('avg_inherent')->map(fn ($v) => round((float) $v, 1))->toArray(),
        ];

        // Capital adequacy, through the same service the ICAAP screen and all
        // four quantification reports use.
        //
        // THE DEFECT. This was `round((float) $latestIcaap->car_actual, 1)`.
        // `car_actual` is NULLABLE — a preparer records the balance sheet
        // before typing a ratio — and `(float) null` is `0.0`, so an
        // assessment on file without one rendered "0%" in the board pack. The
        // tile's own `unavailable` flag fired only when there was no
        // assessment at all, so it could not catch this. WP-08 named this
        // number exactly: 0% CAR is a specific, catastrophic claim about a
        // bank's solvency, and it is the one figure a screen must never
        // invent.
        //
        // Computed from stored capital and RWA, with the preparer's typed
        // figure as a fallback — which also stops the Board pack and the ICAAP
        // screen disagreeing about the bank's CAR, as they previously could.
        $latestIcaap = IcaapAssessment::where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->first();

        $computedCar = $this->icaap->capitalRatioPercent(
            $latestIcaap?->total_qualifying_capital_kobo,
            $latestIcaap?->total_rwa_kobo,
        );
        $reportedCar = ($latestIcaap !== null && $latestIcaap->car_actual !== null)
            ? round((float) $latestIcaap->car_actual, 1)
            : null;

        $capitalAdequacyRatio = $computedCar !== null ? round($computedCar, 1) : $reportedCar;

        // The regulatory minimum the tenant recorded on that assessment. The
        // Board tile used to caption itself "Min: 10%" unconditionally, which
        // is a regulatory threshold asserted by a Blade template rather than
        // read from the ICAAP row it sat next to. Null means no subtitle.
        $capitalAdequacyMinimum = $latestIcaap !== null && $latestIcaap->cbn_minimum_car !== null
            ? (float) $latestIcaap->cbn_minimum_car
            : null;

        // Data-driven executive summary
        $highRisks = Risk::where('organization_id', $orgId)->where('status', 'active')
            ->where('inherent_rating', 'High')->count();
        $openIssues = Issue::where('organization_id', $orgId)
            ->whereIn('issue_status', ['OPEN', 'IN_PROGRESS', 'OVERDUE'])->count();
        $ytdLosses = (float) LossEvent::where('organization_id', $orgId)
            ->whereYear('date_of_loss', now()->year)
            ->sum(LossEvent::netLossNairaSql());

        $ytdLossDisplay = $ytdLosses >= 1000000000
            ? '₦'.number_format($ytdLosses / 1000000000, 2).'bn'
            : '₦'.number_format($ytdLosses / 1000000, 1).'m';

        // Every clause below is only emitted when the figure behind it exists.
        // The appetite and control-effectiveness sentences used to be printed
        // unconditionally, which turned "no appetite statement on record" into
        // a confident percentage and "no control has been rated" into 0%.
        $executiveSummary = sprintf(
            'The organisation currently carries %d active risks, of which %d are rated Critical and %d High. ',
            $totalActiveRisks, $criticalRisks, $highRisks
        );
        $executiveSummary .= $appetiteUtilization !== null
            ? sprintf(
                'Current position against declared appetite stands at %d%% of upper tolerance, averaged across the %d categor%s with a tolerance on record. ',
                $appetiteUtilization,
                $appetiteCategoriesWithTolerance,
                $appetiteCategoriesWithTolerance === 1 ? 'y' : 'ies'
            )
            : 'No risk category has a declared appetite tolerance on record, so appetite utilization cannot be reported. ';
        $executiveSummary .= $controlEffectiveness !== null
            ? sprintf('Overall control effectiveness is %s%%. ', $controlEffectiveness)
            : 'No control in the library carries an effectiveness rating, so control effectiveness cannot be reported. ';
        $executiveSummary .= sprintf(
            'Year-to-date operational losses total %s across recorded loss events, and %d issues remain open or in progress. ',
            $ytdLossDisplay, $openIssues
        );
        // The above/below judgement is only made when the assessment records the
        // minimum it was measured against. This clause used to fall back to a
        // literal 10 for `cbn_minimum_car`, which both quoted a CBN threshold
        // the tenant had not recorded and decided the "above the regulatory
        // minimum" verdict against it.
        if ($capitalAdequacyRatio === null) {
            $executiveSummary .= 'No ICAAP assessment is on record for the current period. ';
        } elseif ($capitalAdequacyMinimum !== null) {
            $executiveSummary .= sprintf(
                'Capital adequacy is %s the recorded regulatory minimum, with a CAR of %s%% against a minimum of %s%% (period %s). ',
                $capitalAdequacyRatio >= $capitalAdequacyMinimum ? 'above' : 'below',
                $capitalAdequacyRatio,
                rtrim(rtrim(number_format($capitalAdequacyMinimum, 2), '0'), '.'),
                $latestIcaap->period
            );
        } else {
            $executiveSummary .= sprintf(
                'The latest ICAAP assessment (period %s) records a CAR of %s%%. No regulatory minimum is recorded against it. ',
                $latestIcaap->period,
                $capitalAdequacyRatio
            );
        }
        $executiveSummary .= $criticalRisks > 0
            ? sprintf('%d critical risk%s require%s Board-level attention.', $criticalRisks, $criticalRisks === 1 ? '' : 's', $criticalRisks === 1 ? 's' : '')
            : 'No critical risks currently require Board-level attention.';

        // Key decisions / actions required from the Board:
        // overdue treatment plans (real deadlines) plus pending approval requests.
        $overdueTreatments = TreatmentPlan::where('organization_id', $orgId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('target_date')
            ->where('target_date', '<', now())
            ->with('owner')
            ->orderBy('target_date')
            ->limit(3)
            ->get()
            ->map(fn (TreatmentPlan $p) => (object) [
                'title' => 'Overdue treatment plan: '.($p->title ?? $p->treatment_code),
                'description' => 'Progress at '.$p->progress.'% with the target date passed. Owner: '.($p->owner->name ?? 'Unassigned').'.',
                'priority' => ucfirst($p->priority ?? 'high'),
                'due_date' => $p->target_date?->format('d M Y'),
            ]);

        $pendingApprovals = ApprovalRequest::where('organization_id', $orgId)
            ->pending()
            ->orderBy('requested_at')
            ->limit(3)
            ->get()
            ->map(fn (ApprovalRequest $a) => (object) [
                'title' => 'Pending approval: '.ucwords(str_replace('_', ' ', $a->action)),
                // `requested_at` is NOT NULL with a database default, so the
                // nullsafe the controller carried here was never reachable.
                'description' => ucwords(str_replace('_', ' ', $a->entity_type)).' #'.$a->entity_id
                    .' has been awaiting a decision since '.$a->requested_at->format('d M Y').'.',
                'priority' => 'High',
                'due_date' => $a->requested_at->format('d M Y'),
            ]);

        $boardActions = $overdueTreatments->concat($pendingApprovals)->take(6)->values();

        return compact(
            'criticalRisksForBoard', 'criticalRisks', 'appetiteUtilization',
            'appetiteCategoriesWithTolerance',
            'controlEffectiveness', 'riskProfileScore',
            'profileChartData', 'appetiteChartData',
            'executiveSummary', 'capitalAdequacyRatio', 'capitalAdequacyMinimum',
            'boardActions'
        );
    }

    /**
     * The treatment status the board table shows, derived from the plans on
     * each risk rather than from a column that does not exist.
     *
     * Shared with the Executive report, which derives the same column on its
     * top-ten table.
     *
     * @param  \Illuminate\Support\Collection<int, Risk>  $risks
     */
    public function applyTreatmentStatus($risks): void
    {
        $isClosed = fn ($plan) => in_array($plan->status, ['completed', 'cancelled'], true);

        $risks->each(function (Risk $risk) use ($isClosed) {
            $plans = $risk->treatmentPlans;

            $risk->setAttribute('derived_treatment_status', match (true) {
                $plans->isEmpty() => 'Not started',
                $plans->contains(fn ($plan) => ! $isClosed($plan)
                    && $plan->target_date !== null
                    && $plan->target_date->isPast()) => 'Overdue',
                $plans->every($isClosed) => 'Completed',
                $plans->every(fn ($plan) => $plan->status === 'not_started') => 'Not started',
                default => 'In progress',
            });
        });
    }

    /**
     * Control effectiveness as a percentage, or null when nothing is rated.
     *
     * Null, not 0. An organisation that has never rated a control has an
     * UNKNOWN control effectiveness; this used to return 0, which the Board
     * report rendered as a hard "0%" in a coloured tile, indistinguishable
     * from a library that had been tested and failed.
     */
    public function controlEffectivenessRate(int $orgId): ?float
    {
        $totalControls = Control::where('organization_id', $orgId)
            ->whereNotNull('effectiveness_rating')
            ->count();

        if ($totalControls === 0) {
            return null;
        }

        $effectiveControls = Control::where('organization_id', $orgId)
            ->where('effectiveness_rating', 'effective')
            ->count();

        return round(($effectiveControls / $totalControls) * 100, 1);
    }

    /**
     * Treatment plan completion as a percentage, or null when none exist.
     *
     * Null, not 0: with no treatment plans on record there is no completion
     * rate to quote, and "0%" reads as a stalled programme.
     */
    public function treatmentCompletionRate(int $orgId): ?float
    {
        $totalPlans = TreatmentPlan::where('organization_id', $orgId)->count();

        if ($totalPlans === 0) {
            return null;
        }

        $completedPlans = TreatmentPlan::where('organization_id', $orgId)
            ->where('status', 'completed')
            ->count();

        return round(($completedPlans / $totalPlans) * 100, 1);
    }
}

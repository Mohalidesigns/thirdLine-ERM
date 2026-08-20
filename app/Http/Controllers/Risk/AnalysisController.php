<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\BusinessUnit;
use App\Models\Control;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\RiskCause;
use App\Models\RiskControlMapping;
use App\Services\RiskScoringService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Heat map, bow-tie, correlation and trend analysis.
 *
 * WP-00 NODE SCOPING, on the same line this codebase draws on the dashboard:
 * the queries that put INDIVIDUAL RISKS in front of a caller are scoped, the
 * ones that compute a TREND OVER TIME are not.
 *
 * Scoped: the heat map (one query feeds both the cell counts and the risks
 * listed in each cell, and the cell drill-through lands on RisksGrid, which is
 * scoped), the bow-tie's risk selector and its ?risk_id= lookup — a
 * caller-supplied id, reachable by URL exactly like a show() route — the
 * correlation scatter, and the movers list.
 *
 * NOT scoped, deliberately: buildRiskMovementData, buildRatingTrendData,
 * buildScoreTrendData, buildCategoryTrendData and buildTreatmentTrendData.
 * Those return counts and averages per month or quarter — roll-up calculations
 * with no record in them to disclose, and the kind of aggregate node scoping
 * is opt-in to avoid narrowing. Full-org roles are unaffected either way;
 * visibleTo() is a no-op for them.
 */
class AnalysisController extends Controller
{
    public function __construct(private RiskScoringService $scoring) {}

    /**
     * Risk heatmap view.
     */
    public function heatmap(Request $request)
    {
        $orgId = TenantContext::organizationId();

        // WP-00 node scoping. One query feeds both the cell counts and the
        // risks listed inside each cell, so scoping it keeps the map, its
        // counts and the register drill-through the cell links to (RisksGrid,
        // also scoped) all describing the same set of risks.
        $query = Risk::where('organization_id', $orgId)
            ->visibleTo()
            ->where('status', 'active')
            ->with(['category', 'riskOwner', 'businessUnit']);

        $viewType = $request->get('view_type', 'inherent');

        if ($request->filled('business_unit_id')) {
            $query->where('business_unit_id', $request->business_unit_id);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $risks = $query->get();

        // WP-05 TASK 3 — the grid is the shape the organisation's scoring
        // profile says it is, not a hardcoded 5×5, and the band boundaries come
        // from the same profile that RiskScoringService rates against. This
        // block previously carried its own copy of `>= 20 is Critical`, which
        // is how the summary counts and the rating column came to be able to
        // disagree with each other.
        $profile = $this->scoring->profileFor(organizationId: $orgId);
        $rows = $profile->matrix_rows;
        $cols = $profile->matrix_cols;

        $heatmapData = [];
        for ($likelihood = 1; $likelihood <= $rows; $likelihood++) {
            for ($impact = 1; $impact <= $cols; $impact++) {
                $heatmapData[$likelihood][$impact] = [];
            }
        }

        foreach ($risks as $risk) {
            if ($viewType === 'residual' && $risk->residual_likelihood && $risk->residual_impact) {
                $l = (int) $risk->residual_likelihood;
                $i = (int) $risk->residual_impact;
            } else {
                $l = (int) $risk->inherent_likelihood;
                $i = (int) $risk->inherent_impact;
            }

            if ($l < 1 || $i < 1) {
                continue;
            }

            // Clamped rather than dropped: after a move to a smaller matrix a
            // risk still carrying a 5 belongs in the top-right cell, not
            // missing from a heat map that claims to show every active risk.
            $heatmapData[min($l, $rows)][min($i, $cols)][] = $risk;
        }

        // Score helper honours the active view type so summary counts stay
        // in sync with what the grid displays.
        $scoreOf = function ($r) use ($viewType, $rows, $cols) {
            if ($viewType === 'residual' && $r->residual_likelihood && $r->residual_impact) {
                return $r->residual_score
                    ?? (min((int) $r->residual_likelihood, $rows) * min((int) $r->residual_impact, $cols));
            }

            return $r->inherent_score
                ?? (min((int) ($r->inherent_likelihood ?? 0), $rows) * min((int) ($r->inherent_impact ?? 0), $cols));
        };

        // One count per configured band, keyed by band code, so a profile with
        // three or six bands renders three or six summary tiles.
        $bandCounts = [];

        foreach ($profile->rating_bands ?? [] as $band) {
            $bandCounts[$band['code']] = [
                'label' => $band['label'] ?? $band['code'],
                'color' => $band['color'] ?? null,
                'count' => $risks->filter(function ($r) use ($scoreOf, $band) {
                    $score = $scoreOf($r);

                    return $score > 0 && $score >= ($band['min'] ?? 1) && $score <= ($band['max'] ?? PHP_INT_MAX);
                })->count(),
            ];
        }

        // The four named counters the existing view and its charts read by
        // name. A profile with different bands simply reports zero for the
        // ones it does not define; $bandCounts is the general form.
        $criticalCount = $bandCounts['critical']['count'] ?? 0;
        $highCount = $bandCounts['high']['count'] ?? 0;
        $mediumCount = $bandCounts['medium']['count'] ?? 0;
        $lowCount = $bandCounts['low']['count'] ?? 0;

        // Movement data for chart (quarterly trend)
        $movementData = $this->buildRiskMovementData($orgId);

        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();

        // Categories with id + name so the dropdown can filter by id.
        $categories = RiskCategory::where('organization_id', $orgId)
            ->orderBy('name')->get(['id', 'name']);

        // JS payload — click-to-show-risks resolves against this map without
        // extra round-trips, and respects the active view type.
        $risksForJs = $risks->map(fn ($r) => [
            'id' => $r->id,
            'code' => $r->risk_code,
            'title' => $r->title,
            'category' => $r->category?->name,
            'business_unit' => $r->businessUnit?->name,
            'owner' => $r->riskOwner?->name,
            'inherent_l' => $r->inherent_likelihood,
            'inherent_i' => $r->inherent_impact,
            'inherent_score' => $r->inherent_score,
            'inherent_rating' => $r->inherent_rating,
            'residual_l' => $r->residual_likelihood,
            'residual_i' => $r->residual_impact,
            'residual_score' => $r->residual_score,
            'residual_rating' => $r->residual_rating,
            'url' => route('risk.register.show', $r->id),
        ])->values();

        return view('risk.analysis.heatmap', compact(
            'risks', 'risksForJs', 'heatmapData', 'viewType', 'businessUnits', 'categories',
            'criticalCount', 'highCount', 'mediumCount', 'lowCount', 'movementData',
            'profile', 'bandCounts'
        ));
    }

    /**
     * Bowtie analysis view.
     * View expects $selectedRisk (not $risk), $mitigatingControls (not $detectiveControls),
     * causes/consequences as collections of objects with ->description, ->financial_impact.
     */
    public function bowtie(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $riskId = $request->get('risk_id');
        $selectedRisk = null;
        $causes = [];
        $consequences = [];
        $preventiveControls = [];
        $mitigatingControls = [];
        $controlEffData = ['labels' => ['Effective', 'Partially', 'Ineffective'], 'values' => [0, 0, 0]];

        if ($riskId) {
            // WP-00: ?risk_id= is a caller-supplied id, so the bow-tie is
            // reachable by URL exactly like a show() route and is scoped the
            // same way. An out-of-subtree id yields null, and the page renders
            // its "choose a risk" state rather than another branch's analysis.
            $selectedRisk = Risk::where('id', $riskId)
                ->visibleTo()
                ->where('organization_id', $orgId)
                ->with(['category', 'riskOwner', 'businessUnit', 'controlMappings'])
                ->first();

            if ($selectedRisk) {
                // Build causes from risk data
                $causes = $this->buildCauses($selectedRisk);

                // Build consequences from risk data
                $consequences = $this->buildConsequences($selectedRisk);

                // Categorize controls
                $effectiveCount = 0;
                $partialCount = 0;
                $ineffectiveCount = 0;
                $unratedCount = 0;

                foreach ($selectedRisk->controlMappings as $control) {
                    $eff = $this->classifyControlEffectiveness($control);
                    $ctrlObj = (object) [
                        'name' => $control->name ?? $control->control_id ?? 'Control',
                        'type' => $control->control_type ?? 'detective',
                        'effectiveness' => $eff,
                        'gaps' => match ($eff) {
                            'ineffective' => 'Requires improvement',
                            'partially' => 'Minor gaps identified',
                            'unrated' => 'Not yet rated',
                            default => 'None',
                        },
                    ];

                    if (in_array($control->control_type ?? '', ['preventive', 'directive'])) {
                        $preventiveControls[] = $ctrlObj;
                    } else {
                        $mitigatingControls[] = $ctrlObj;
                    }

                    match ($eff) {
                        'effective' => $effectiveCount++,
                        'partially' => $partialCount++,
                        'unrated' => $unratedCount++,
                        default => $ineffectiveCount++,
                    };
                }

                // Unrated is its own slice. Folding it into "Ineffective" would
                // report a control library nobody has tested as a control
                // library that failed.
                $controlEffData = [
                    'labels' => ['Effective', 'Partially', 'Ineffective', 'Unrated'],
                    'values' => [$effectiveCount, $partialCount, $ineffectiveCount, $unratedCount],
                ];
            }
        }

        $risks = Risk::where('organization_id', $orgId)
            ->visibleTo()
            ->where('status', 'active')
            ->orderBy('risk_code')
            ->get();

        return view('risk.analysis.bowtie', compact(
            'selectedRisk', 'risks', 'causes', 'consequences',
            'preventiveControls', 'mitigatingControls', 'controlEffData'
        ));
    }

    /**
     * Risk trends analysis.
     */
    public function trends(Request $request)
    {
        $orgId = TenantContext::organizationId();

        // Accept explicit from/to dates from the date picker. Fall back to the
        // last 12 months if nothing (or invalid input) is provided.
        try {
            $startDate = $request->filled('from')
                ? \Carbon\Carbon::parse($request->string('from')->toString())->startOfDay()
                : now()->subMonths(12)->startOfDay();
        } catch (\Throwable) {
            $startDate = now()->subMonths(12)->startOfDay();
        }
        try {
            $endDate = $request->filled('to')
                ? \Carbon\Carbon::parse($request->string('to')->toString())->endOfDay()
                : now()->endOfDay();
        } catch (\Throwable) {
            $endDate = now()->endOfDay();
        }
        // Swap if user inverted the range so the window is always valid.
        if ($startDate->gt($endDate)) {
            [$startDate, $endDate] = [$endDate->copy()->startOfDay(), $startDate->copy()->endOfDay()];
        }

        // KPI metrics
        $activeRisks = Risk::where('organization_id', $orgId)->where('status', 'active');
        $totalActiveRisks = $activeRisks->count();
        $avgRiskScore = $activeRisks->avg('inherent_score') ?? 0;

        $newRisks = Risk::where('organization_id', $orgId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $closedRisks = Risk::where('organization_id', $orgId)
            ->whereIn('status', ['closed', 'retired'])
            ->whereBetween('updated_at', [$startDate, $endDate])
            ->count();

        // Changes — compare current vs the mid-point of the selected range.
        $midpoint = $startDate->copy()->addSeconds((int) ($endDate->diffInSeconds($startDate) / 2));
        $activeRisksOld = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->where('created_at', '<', $midpoint)
            ->count();
        $activeRisksChange = $totalActiveRisks - $activeRisksOld;
        $activeRisksDirection = $activeRisksChange >= 0 ? 'up' : 'down';
        $activeRisksChange = ($activeRisksChange >= 0 ? '+' : '').$activeRisksChange;

        $avgScoreOld = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->where('created_at', '<', $midpoint)
            ->avg('inherent_score') ?? 0;
        $scoreChange = round($avgRiskScore - $avgScoreOld, 1);
        $avgScoreChange = ($scoreChange >= 0 ? '+' : '').$scoreChange;
        $avgScoreDirection = $scoreChange >= 0 ? 'up' : 'down';

        // Build trend charts over the selected window.
        $ratingTrendData = $this->buildRatingTrendData($orgId, $startDate, $endDate);
        $scoreTrendData = $this->buildScoreTrendData($orgId, $startDate, $endDate);
        $categoryTrendData = $this->buildCategoryTrendData($orgId, $startDate, $endDate);
        $treatmentTrendData = $this->buildTreatmentTrendData($orgId, $startDate, $endDate);

        // Risk movers
        $riskIncreasers = $this->buildRiskMovers($orgId, 'up');
        $riskDecreasers = $this->buildRiskMovers($orgId, 'down');

        // Echo the resolved window back to the view so the date picker stays in
        // sync with what was actually applied (handles defaults + swaps).
        $fromValue = $startDate->format('Y-m-d');
        $toValue = $endDate->format('Y-m-d');

        return view('risk.analysis.trends', compact(
            'totalActiveRisks', 'activeRisksChange', 'activeRisksDirection',
            'avgRiskScore', 'avgScoreChange', 'avgScoreDirection',
            'newRisks', 'closedRisks',
            'ratingTrendData', 'scoreTrendData', 'categoryTrendData', 'treatmentTrendData',
            'riskIncreasers', 'riskDecreasers',
            'fromValue', 'toValue'
        ));
    }

    /**
     * Shared-control analysis: how much of each risk's control set is also
     * relied on by another risk.
     *
     * This page was called "Risk Correlation Analysis" and printed a
     * "coefficient" to three decimal places with a "Significance" column beside
     * it. Nothing on it was a correlation. The positive column divided the
     * count of shared controls by the larger control count and called the
     * result a coefficient; the negative column was generated by
     * `buildNegativeCorrelations()`, which took any two risks in different
     * categories whose inherent scores differed by more than 8 and emitted
     * -(|s1 - s2| / 25) as a coefficient, with "high" significance above 0.5;
     * the matrix averaged the shared-control ratio with `1 - |s1 - s2| / 25`
     * and forced the diagonal to 1.0 so it looked like a correlation matrix.
     *
     * A correlation between two risks needs a time series of paired
     * observations — repeated measurements of both risks over the same periods
     * — and a coefficient reported with a p-value and an n. This product
     * collects none of those: there is no risk-level time series anywhere in
     * the schema, and no Pearson, Spearman or significance test anywhere in the
     * codebase. Presenting a shared-control ratio as r, to three decimals,
     * invites a bank to treat control overlap as statistical dependence in
     * capital or scenario work.
     *
     * What survives is the part that was always real and is a legitimate
     * concentration signal in its own right: two risks that lean on the same
     * controls fail together when those controls fail. That is what this page
     * now measures and what it now says it measures.
     */
    public function correlation(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $selectedCategoryId = $request->integer('category_id') ?: null;

        // Get active risks with scores, optionally narrowed to a single category.
        $risks = Risk::where('organization_id', $orgId)
            ->visibleTo()
            ->where('status', 'active')
            ->whereNotNull('inherent_score')
            ->when($selectedCategoryId, fn ($q) => $q->where('category_id', $selectedCategoryId))
            ->with(['category', 'businessUnit'])
            ->orderByDesc('inherent_score')
            ->get();

        // Categories for the dropdown — use models so we can key by id and
        // preserve the selected state on refresh.
        $categories = RiskCategory::where('organization_id', $orgId)
            ->orderBy('name')->get(['id', 'name']);

        // Control mappings for the visible risks. Every figure on this page is
        // a count of rows in this table — nothing is modelled or estimated.
        $visibleRiskIds = $risks->pluck('id');
        $controlMappings = RiskControlMapping::whereIn('risk_id', $visibleRiskIds)
            ->with(['risk', 'control'])
            ->get();

        $riskControls = $controlMappings->groupBy('risk_id');
        $riskIds = $riskControls->keys()->toArray();
        $pairs = [];

        for ($i = 0; $i < count($riskIds); $i++) {
            for ($j = $i + 1; $j < count($riskIds); $j++) {
                $risk1Controls = $riskControls[$riskIds[$i]]->pluck('control_id')->unique()->toArray();
                $risk2Controls = $riskControls[$riskIds[$j]]->pluck('control_id')->unique()->toArray();
                $sharedControls = array_intersect($risk1Controls, $risk2Controls);

                if (count($sharedControls) > 0) {
                    $risk1 = $riskControls[$riskIds[$i]]->first()->risk;
                    $risk2 = $riskControls[$riskIds[$j]]->first()->risk;

                    $pairs[] = [
                        'risk_a' => $risk1->risk_code ?? 'R-?',
                        'risk_b' => $risk2->risk_code ?? 'R-?',
                        'shared_controls' => count($sharedControls),
                        'controls_a' => count($risk1Controls),
                        'controls_b' => count($risk2Controls),
                        // Shared controls as a share of the larger of the two
                        // control sets: 100% means one risk's entire control
                        // set is also carrying the other risk.
                        'overlap_pct' => (int) round(
                            (count($sharedControls) / max(count($risk1Controls), count($risk2Controls))) * 100
                        ),
                    ];
                }
            }
        }

        usort(
            $pairs,
            fn ($a, $b) => [$b['overlap_pct'], $b['shared_controls']] <=> [$a['overlap_pct'], $a['shared_controls']]
        );

        $sharedControlPairs = collect(array_slice($pairs, 0, 10))
            ->map(fn ($p) => (object) $p);

        $overlapMatrix = $this->buildControlOverlapMatrix($risks, $riskControls);

        return view('risk.analysis.correlation', compact(
            'risks', 'categories', 'selectedCategoryId',
            'sharedControlPairs', 'overlapMatrix'
        ));
    }

    /* ------------------------------------------------------------------ */
    /*  Private helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Classify control effectiveness based on testing results.
     */
    private function classifyControlEffectiveness($control): string
    {
        $effectiveness = $control->effectiveness_rating ?? $control->operating_effectiveness ?? null;
        if ($effectiveness) {
            $eff = strtolower($effectiveness);
            if (in_array($eff, ['effective', 'strong', 'high'])) {
                return 'effective';
            }
            if (in_array($eff, ['partially', 'moderate', 'medium', 'partially_effective'])) {
                return 'partially';
            }

            return 'ineffective';
        }

        // An unrated control is unrated. This used to return a rating derived
        // from the control's id modulo 3, which put a fabricated effectiveness
        // on the bow-tie and into its doughnut counts — indistinguishable, on
        // screen, from a real test result.
        return 'unrated';
    }

    /**
     * The left-hand side of the bow-tie: the risk's recorded root causes.
     *
     * This used to read `$risk->risk_trigger ?? $risk->root_cause` — two columns
     * that have never existed on `risks`. The expression therefore always
     * evaluated to an empty string and every bow-tie in the product fell
     * through to the same three invented causes ("Human error or negligence by
     * staff", …), presented as though they were the organization's own
     * analysis. WP-10a gives causes a real home, so the diagram now draws what
     * the assessors actually recorded, and draws nothing when they recorded
     * nothing.
     */
    private function buildCauses(Risk $risk): array
    {
        return $risk->causes()
            ->with('category')
            ->get()
            ->map(fn (RiskCause $cause) => (object) [
                'id' => $cause->id,
                'description' => $cause->description,
                'category' => $cause->category?->name,
                'source' => $cause->source_label,
                'is_primary' => (bool) $cause->is_primary,
            ])
            ->all();
    }

    /**
     * The right-hand side of the bow-tie: the consequences the organisation
     * actually recorded against the risk.
     *
     * This used to read `$risk->risk_consequence ?? $risk->impact_description`
     * — neither column exists on `risks` (impact_description is a column on
     * `issues`), so the expression was always the empty string and the fallback
     * below it fired for every risk in the register, emitting the same three
     * invented consequences ("Financial loss and reduced profitability",
     * "Reputational damage and loss of customer confidence", "Regulatory
     * sanctions or penalties") as though they were the organisation's own
     * analysis. It also read `$risk->financial_exposure`, which does not exist
     * either — the column is `financial_exposure_ngn` — so the naira figure
     * attached to the first invented consequence was always absent.
     *
     * That is the same defect buildCauses() above documents having fixed on the
     * cause side, and it is fixed the same way: the wing is drawn from what the
     * assessors scored, and drawn empty when they scored nothing.
     *
     * The source is the risk's recorded impact-dimension ratings — the only
     * consequence analysis this product stores. Each dimension the assessment
     * actually rated becomes one consequence, carrying its recorded severity on
     * the organisation's own configured impact scale. There is no free-text
     * consequence register in the schema yet; when WP-10a's cause chain gains a
     * consequence counterpart this method should read that instead.
     */
    private function buildConsequences(Risk $risk): array
    {
        // The impact scale is whatever the scoring profile governing this risk
        // says it is, so the "3 of 5" reads correctly under a 4- or 6-point
        // profile instead of assuming the platform default.
        $impactScale = $this->scoring->profileForRisk($risk)->matrix_cols;

        $dimensions = [
            'Financial' => $risk->inherent_impact_financial,
            'Operational' => $risk->inherent_impact_operational,
            'Reputational' => $risk->inherent_impact_reputational,
            'Regulatory' => $risk->inherent_impact_regulatory,
        ];

        $consequences = [];

        foreach ($dimensions as $label => $rating) {
            if ($rating === null || (int) $rating < 1) {
                continue;
            }

            $consequences[] = (object) [
                'description' => $label.' impact, rated '.(int) $rating.' of '.$impactScale,
                // Only the financial dimension carries the recorded exposure,
                // and only when one was recorded.
                'financial_impact' => $label === 'Financial' && $risk->financial_exposure_ngn !== null
                    ? (float) $risk->financial_exposure_ngn
                    : null,
            ];
        }

        return $consequences;
    }

    /**
     * Build quarterly risk movement data for heatmap chart.
     */
    private function buildRiskMovementData(int $orgId): array
    {
        $labels = [];
        $critical = [];
        $high = [];
        $medium = [];
        $low = [];

        for ($q = 3; $q >= 0; $q--) {
            $start = now()->subQuarters($q)->startOfQuarter();
            $end = now()->subQuarters($q)->endOfQuarter();
            $label = 'Q'.$start->quarter.' '.$start->format('Y');
            $labels[] = $label;

            $risksInQuarter = Risk::where('organization_id', $orgId)
                ->where('status', 'active')
                ->where('created_at', '<=', $end)
                ->get();

            $critical[] = $risksInQuarter->filter(fn ($r) => ($r->inherent_score ?? (($r->inherent_likelihood ?? 0) * ($r->inherent_impact ?? 0))) >= 20)->count();
            $high[] = $risksInQuarter->filter(fn ($r) => ($s = $r->inherent_score ?? (($r->inherent_likelihood ?? 0) * ($r->inherent_impact ?? 0))) >= 12 && $s < 20)->count();
            $medium[] = $risksInQuarter->filter(fn ($r) => ($s = $r->inherent_score ?? (($r->inherent_likelihood ?? 0) * ($r->inherent_impact ?? 0))) >= 5 && $s < 12)->count();
            $low[] = $risksInQuarter->filter(fn ($r) => ($r->inherent_score ?? (($r->inherent_likelihood ?? 0) * ($r->inherent_impact ?? 0))) < 5)->count();
        }

        return compact('labels', 'critical', 'high', 'medium', 'low');
    }

    /**
     * Build rating trend data (monthly counts by rating).
     */
    private function buildRatingTrendData(int $orgId, $startDate, $endDate = null): array
    {
        $labels = [];
        $critical = [];
        $high = [];
        $medium = [];
        $low = [];

        $current = $startDate->copy()->startOfMonth();
        $end = ($endDate ?? now())->copy()->endOfMonth();

        while ($current <= $end) {
            $labels[] = $current->format('M Y');

            $risksAtMonth = Risk::where('organization_id', $orgId)
                ->where('status', 'active')
                ->where('created_at', '<=', $current->copy()->endOfMonth())
                ->get();

            $critical[] = $risksAtMonth->filter(fn ($r) => strtolower($r->inherent_rating ?? '') === 'critical')->count();
            $high[] = $risksAtMonth->filter(fn ($r) => strtolower($r->inherent_rating ?? '') === 'high')->count();
            $medium[] = $risksAtMonth->filter(fn ($r) => strtolower($r->inherent_rating ?? '') === 'medium')->count();
            $low[] = $risksAtMonth->filter(fn ($r) => strtolower($r->inherent_rating ?? '') === 'low')->count();

            $current->addMonth();
        }

        return compact('labels', 'critical', 'high', 'medium', 'low');
    }

    /**
     * Build avg score trend data (monthly).
     */
    private function buildScoreTrendData(int $orgId, $startDate, $endDate = null): array
    {
        $labels = [];
        $values = [];

        $current = $startDate->copy()->startOfMonth();
        $end = ($endDate ?? now())->copy()->endOfMonth();

        while ($current <= $end) {
            $labels[] = $current->format('M Y');

            $avg = Risk::where('organization_id', $orgId)
                ->where('status', 'active')
                ->where('created_at', '<=', $current->copy()->endOfMonth())
                ->avg('inherent_score') ?? 0;

            $values[] = round($avg, 1);
            $current->addMonth();
        }

        return compact('labels', 'values');
    }

    /**
     * Build category trend data for stacked bar chart.
     */
    private function buildCategoryTrendData(int $orgId, $startDate, $endDate = null): array
    {
        $categories = RiskCategory::where('organization_id', $orgId)->orderBy('name')->get();
        $labels = [];
        $datasets = [];

        $current = $startDate->copy()->startOfMonth();
        $end = ($endDate ?? now())->copy()->endOfMonth();

        while ($current <= $end) {
            $labels[] = $current->format('M Y');
            $current->addMonth();
        }

        foreach ($categories->take(5) as $cat) {
            $data = [];
            $current = $startDate->copy()->startOfMonth();
            while ($current <= $end) {
                $count = Risk::where('organization_id', $orgId)
                    ->where('category_id', $cat->id)
                    ->where('created_at', '<=', $current->copy()->endOfMonth())
                    ->where('status', 'active')
                    ->count();
                $data[] = $count;
                $current->addMonth();
            }
            $datasets[] = ['label' => $cat->name, 'data' => $data];
        }

        return compact('labels', 'datasets');
    }

    /**
     * Build treatment trend data.
     */
    private function buildTreatmentTrendData(int $orgId, $startDate, $endDate = null): array
    {
        $labels = [];
        $completed = [];
        $overdue = [];

        $current = $startDate->copy()->startOfMonth();
        $end = ($endDate ?? now())->copy()->endOfMonth();

        while ($current <= $end) {
            $labels[] = $current->format('M Y');
            $monthEnd = $current->copy()->endOfMonth();

            // Count risks with treatment actions completed in this month
            $closedInMonth = Risk::where('organization_id', $orgId)
                ->whereIn('status', ['closed', 'retired'])
                ->whereBetween('updated_at', [$current->copy()->startOfMonth(), $monthEnd])
                ->count();
            $completed[] = $closedInMonth;

            // Count risks overdue (simplified: active risks older than 6 months with high/critical rating)
            $overdueCount = Risk::where('organization_id', $orgId)
                ->where('status', 'active')
                ->whereIn('inherent_rating', ['Critical', 'High'])
                ->where('created_at', '<', $current->copy()->subMonths(6))
                ->where('created_at', '<=', $monthEnd)
                ->count();
            $overdue[] = $overdueCount;

            $current->addMonth();
        }

        return compact('labels', 'completed', 'overdue');
    }

    /**
     * Risks ranked by how far their controls move them: inherent -> residual.
     *
     * WHAT THIS USED TO CLAIM. The method was called buildRiskMovers(), its own
     * comment said "simulate movement", and the screen presented the output as
     * "Top Risk Increasers" and "Top Risk Decreasers" with a Previous / Current
     * / Change table — i.e. movement over time. Nothing in it looked at time.
     * The figure was `inherent_score - residual_score`, which is the effect of
     * the control environment as assessed today. Worse, the two ratings were
     * mapped backwards: the "up" branch labelled the RESIDUAL rating "previous"
     * and the INHERENT rating "current", so a well-controlled risk was
     * displayed as having deteriorated. And where a rating was missing the
     * fallbacks invented one ('low' then 'medium', 'high' then 'medium'), so a
     * completely unrated risk rendered as a confident Low -> Medium increase.
     *
     * WHAT IT REPORTS NOW. The same arithmetic, labelled as what it measures:
     * the gap between the inherent and residual positions, largest first, with
     * the inherent and residual ratings named as such and 'unrated' where the
     * organisation has not rated them (x-risk-badge renders anything it does
     * not recognise in neutral grey, so an unrated risk reads as unrated
     * rather than as Medium).
     *
     * A genuine period-over-period mover list is buildable — RiskRepository
     * ::asOf() reconstructs the register at a past period from the measure
     * engine — but it is a different query against measure_values, not this
     * one, and inventing it here is what produced the original defect.
     *
     * @param  string  $direction  'up' = controls reduce exposure, 'down' = residual exceeds inherent
     */
    private function buildRiskMovers(int $orgId, string $direction): array
    {
        $risks = Risk::where('organization_id', $orgId)
            ->visibleTo()
            ->where('status', 'active')
            ->whereNotNull('inherent_score')
            ->orderByDesc('inherent_score')
            ->limit(20)
            ->get();

        $movers = [];
        foreach ($risks as $risk) {
            $inherentScore = $risk->inherent_score ?? 0;
            $residualScore = ($risk->residual_likelihood ?? 0) * ($risk->residual_impact ?? 0);

            // No residual assessment means no gap to report — not a gap of zero.
            if ($residualScore <= 0) {
                continue;
            }

            $diff = $inherentScore - $residualScore;

            if (($direction === 'up' && $diff > 0) || ($direction === 'down' && $diff < 0)) {
                $movers[] = (object) [
                    'risk_code' => $risk->risk_code,
                    'inherent_rating' => $risk->inherent_rating ?? 'unrated',
                    'residual_rating' => $risk->residual_rating ?? 'unrated',
                    'score_change' => $diff,
                ];
            }
        }

        usort($movers, fn ($a, $b) => abs($b->score_change) <=> abs($a->score_change));

        return array_slice($movers, 0, 5);
    }

    /**
     * Pairwise shared-control overlap for the top risks, as percentages.
     *
     * Replaces buildCorrelationMatrix(), which averaged the shared-control
     * ratio with `1 - |score_a - score_b| / 25` — a score-similarity term with
     * no statistical meaning — labelled the result a correlation coefficient,
     * and forced the diagonal to 1.0 so the grid read as a correlation matrix.
     *
     * Cell [i][j] is simply: of the larger of the two risks' control sets, what
     * share of it is mapped to both risks. The diagonal is null rather than
     * 100: a risk trivially shares every control with itself, and drawing that
     * cell only ever existed to complete the look of a correlation matrix.
     *
     * @return array{labels: list<string>, data: list<list<int|null>>}
     */
    private function buildControlOverlapMatrix($risks, $riskControls): array
    {
        $topRisks = $risks->take(8)->values();
        $labels = $topRisks->pluck('risk_code')->toArray();
        $size = count($labels);
        $matrix = array_fill(0, $size, array_fill(0, $size, 0));

        for ($i = 0; $i < $size; $i++) {
            $matrix[$i][$i] = null;
        }

        for ($i = 0; $i < $size; $i++) {
            for ($j = $i + 1; $j < $size; $j++) {
                $ridA = $topRisks[$i]->id;
                $ridB = $topRisks[$j]->id;

                $controlsA = isset($riskControls[$ridA])
                    ? $riskControls[$ridA]->pluck('control_id')->unique()->toArray()
                    : [];
                $controlsB = isset($riskControls[$ridB])
                    ? $riskControls[$ridB]->pluck('control_id')->unique()->toArray()
                    : [];

                $shared = count(array_intersect($controlsA, $controlsB));
                $largest = max(count($controlsA), count($controlsB));

                $overlap = $largest === 0 ? 0 : (int) round(($shared / $largest) * 100);

                $matrix[$i][$j] = $overlap;
                $matrix[$j][$i] = $overlap;
            }
        }

        return ['labels' => $labels, 'data' => $matrix];
    }
}

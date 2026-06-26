<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\RiskAssessment;
use App\Models\Control;
use App\Models\RiskControlMapping;
use App\Models\LossEvent;
use App\Models\KeyRiskIndicator;
use App\Models\BusinessUnit;
use Illuminate\Http\Request;

class AnalysisController extends Controller
{
    /**
     * Risk heatmap view.
     */
    public function heatmap(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $query = Risk::where('organization_id', $orgId)
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

        // Build 5x5 heatmap matrix
        $heatmapData = [];
        for ($likelihood = 1; $likelihood <= 5; $likelihood++) {
            for ($impact = 1; $impact <= 5; $impact++) {
                $heatmapData[$likelihood][$impact] = [];
            }
        }

        foreach ($risks as $risk) {
            if ($viewType === 'residual' && $risk->residual_likelihood && $risk->residual_impact) {
                $l = $risk->residual_likelihood;
                $i = $risk->residual_impact;
            } else {
                $l = $risk->inherent_likelihood;
                $i = $risk->inherent_impact;
            }
            if ($l >= 1 && $l <= 5 && $i >= 1 && $i <= 5) {
                $heatmapData[$l][$i][] = $risk;
            }
        }

        // Score helper honours the active view type so summary counts stay
        // in sync with what the grid displays.
        $scoreOf = function ($r) use ($viewType) {
            if ($viewType === 'residual' && $r->residual_likelihood && $r->residual_impact) {
                return $r->residual_score ?? ($r->residual_likelihood * $r->residual_impact);
            }
            return $r->inherent_score ?? (($r->inherent_likelihood ?? 0) * ($r->inherent_impact ?? 0));
        };

        $criticalCount = $risks->filter(fn ($r) => $scoreOf($r) >= 20)->count();
        $highCount     = $risks->filter(fn ($r) => ($s = $scoreOf($r)) >= 12 && $s < 20)->count();
        $mediumCount   = $risks->filter(fn ($r) => ($s = $scoreOf($r)) >= 5 && $s < 12)->count();
        $lowCount      = $risks->filter(fn ($r) => $scoreOf($r) < 5 && $scoreOf($r) > 0)->count();

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
            'criticalCount', 'highCount', 'mediumCount', 'lowCount', 'movementData'
        ));
    }

    /**
     * Bowtie analysis view.
     * View expects $selectedRisk (not $risk), $mitigatingControls (not $detectiveControls),
     * causes/consequences as collections of objects with ->description, ->financial_impact.
     */
    public function bowtie(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $riskId = $request->get('risk_id');
        $selectedRisk = null;
        $causes = [];
        $consequences = [];
        $preventiveControls = [];
        $mitigatingControls = [];
        $controlEffData = ['labels' => ['Effective', 'Partially', 'Ineffective'], 'values' => [0, 0, 0]];

        if ($riskId) {
            $selectedRisk = Risk::where('id', $riskId)
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
                $partialCount   = 0;
                $ineffectiveCount = 0;

                foreach ($selectedRisk->controlMappings as $control) {
                    $eff = $this->classifyControlEffectiveness($control);
                    $ctrlObj = (object) [
                        'name'          => $control->name ?? $control->control_id ?? 'Control',
                        'type'          => $control->control_type ?? 'detective',
                        'effectiveness' => $eff,
                        'gaps'          => $eff === 'ineffective' ? 'Requires improvement' : ($eff === 'partially' ? 'Minor gaps identified' : 'None'),
                    ];

                    if (in_array($control->control_type ?? '', ['preventive', 'directive'])) {
                        $preventiveControls[] = $ctrlObj;
                    } else {
                        $mitigatingControls[] = $ctrlObj;
                    }

                    match ($eff) {
                        'effective' => $effectiveCount++,
                        'partially' => $partialCount++,
                        default     => $ineffectiveCount++,
                    };
                }

                $controlEffData = [
                    'labels' => ['Effective', 'Partially', 'Ineffective'],
                    'values' => [$effectiveCount, $partialCount, $ineffectiveCount],
                ];
            }
        }

        $risks = Risk::where('organization_id', $orgId)
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
        $orgId = auth()->user()->organization_id ?? 1;

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
        $activeRisksChange    = $totalActiveRisks - $activeRisksOld;
        $activeRisksDirection = $activeRisksChange >= 0 ? 'up' : 'down';
        $activeRisksChange    = ($activeRisksChange >= 0 ? '+' : '') . $activeRisksChange;

        $avgScoreOld = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->where('created_at', '<', $midpoint)
            ->avg('inherent_score') ?? 0;
        $scoreChange      = round($avgRiskScore - $avgScoreOld, 1);
        $avgScoreChange   = ($scoreChange >= 0 ? '+' : '') . $scoreChange;
        $avgScoreDirection = $scoreChange >= 0 ? 'up' : 'down';

        // Build trend charts over the selected window.
        $ratingTrendData    = $this->buildRatingTrendData($orgId, $startDate, $endDate);
        $scoreTrendData     = $this->buildScoreTrendData($orgId, $startDate, $endDate);
        $categoryTrendData  = $this->buildCategoryTrendData($orgId, $startDate, $endDate);
        $treatmentTrendData = $this->buildTreatmentTrendData($orgId, $startDate, $endDate);

        // Risk movers
        $riskIncreasers = $this->buildRiskMovers($orgId, 'up');
        $riskDecreasers = $this->buildRiskMovers($orgId, 'down');

        // Echo the resolved window back to the view so the date picker stays in
        // sync with what was actually applied (handles defaults + swaps).
        $fromValue = $startDate->format('Y-m-d');
        $toValue   = $endDate->format('Y-m-d');

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
     * Risk correlation analysis.
     */
    public function correlation(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $selectedCategoryId = $request->integer('category_id') ?: null;

        // Get active risks with scores, optionally narrowed to a single category.
        $risks = Risk::where('organization_id', $orgId)
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

        // Build correlation data from shared controls, scoped to the visible risks.
        $visibleRiskIds = $risks->pluck('id');
        $controlMappings = RiskControlMapping::whereIn('risk_id', $visibleRiskIds)
            ->with(['risk', 'control'])
            ->get();

        $riskControls = $controlMappings->groupBy('risk_id');
        $riskIds = $riskControls->keys()->toArray();
        $pairs   = [];

        for ($i = 0; $i < count($riskIds); $i++) {
            for ($j = $i + 1; $j < count($riskIds); $j++) {
                $risk1Controls = $riskControls[$riskIds[$i]]->pluck('control_id')->toArray();
                $risk2Controls = $riskControls[$riskIds[$j]]->pluck('control_id')->toArray();
                $sharedControls = array_intersect($risk1Controls, $risk2Controls);

                if (count($sharedControls) > 0) {
                    $strength = count($sharedControls) / max(count($risk1Controls), count($risk2Controls));
                    $risk1 = $riskControls[$riskIds[$i]]->first()->risk;
                    $risk2 = $riskControls[$riskIds[$j]]->first()->risk;

                    $pairs[] = [
                        'risk_a'      => $risk1->risk_code ?? 'R-?',
                        'risk_b'      => $risk2->risk_code ?? 'R-?',
                        'coefficient' => round($strength, 3),
                        'significance' => $strength >= 0.7 ? 'high' : ($strength >= 0.4 ? 'medium' : 'low'),
                    ];
                }
            }
        }

        // Sort by coefficient descending
        usort($pairs, fn($a, $b) => $b['coefficient'] <=> $a['coefficient']);

        // Split into positive and negative correlations (all are positive from shared controls)
        $positiveCorrelations = collect(array_slice($pairs, 0, 10))
            ->map(fn($p) => (object) $p);

        // Generate some synthetic negative correlations from inverse score relationships
        $negativeCorrelations = $this->buildNegativeCorrelations($risks);

        // Build correlation matrix
        $correlationMatrix = $this->buildCorrelationMatrix($risks, $riskControls);

        return view('risk.analysis.correlation', compact(
            'risks', 'categories', 'selectedCategoryId',
            'positiveCorrelations', 'negativeCorrelations', 'correlationMatrix'
        ));
    }

    /* ------------------------------------------------------------------ */
    /*  Private helpers                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Classify control effectiveness based on testing results.
     */
    private function classifyControlEffectiveness($control): string
    {
        $effectiveness = $control->effectiveness_rating ?? $control->operating_effectiveness ?? null;
        if ($effectiveness) {
            $eff = strtolower($effectiveness);
            if (in_array($eff, ['effective', 'strong', 'high'])) return 'effective';
            if (in_array($eff, ['partially', 'moderate', 'medium', 'partially_effective'])) return 'partially';
            return 'ineffective';
        }
        // Default: random-ish based on ID
        return match (($control->id ?? 0) % 3) {
            0 => 'effective',
            1 => 'partially',
            default => 'ineffective',
        };
    }

    /**
     * Build causes from risk data.
     */
    private function buildCauses(Risk $risk): array
    {
        $causes = [];

        // Try parsing from risk_trigger or description
        $triggerText = $risk->risk_trigger ?? $risk->root_cause ?? '';
        if ($triggerText) {
            $lines = array_filter(array_map('trim', preg_split('/[\n;,]+/', $triggerText)));
            foreach ($lines as $line) {
                if (strlen($line) > 3) {
                    $causes[] = (object) ['description' => $line];
                }
            }
        }

        // If no causes found, generate from risk type
        if (empty($causes)) {
            $defaultCauses = [
                (object) ['description' => 'Inadequate internal controls and procedures'],
                (object) ['description' => 'Human error or negligence by staff'],
                (object) ['description' => 'External threat actors or environmental factors'],
            ];
            $causes = $defaultCauses;
        }

        return $causes;
    }

    /**
     * Build consequences from risk data.
     */
    private function buildConsequences(Risk $risk): array
    {
        $consequences = [];

        $consequenceText = $risk->risk_consequence ?? $risk->impact_description ?? '';
        if ($consequenceText) {
            $lines = array_filter(array_map('trim', preg_split('/[\n;,]+/', $consequenceText)));
            foreach ($lines as $line) {
                if (strlen($line) > 3) {
                    $consequences[] = (object) [
                        'description'      => $line,
                        'financial_impact'  => null,
                    ];
                }
            }
        }

        // If no consequences found, generate defaults
        if (empty($consequences)) {
            $financialExposure = $risk->financial_exposure ?? 0;
            $consequences = [
                (object) ['description' => 'Financial loss and reduced profitability', 'financial_impact' => $financialExposure > 0 ? $financialExposure : null],
                (object) ['description' => 'Reputational damage and loss of customer confidence', 'financial_impact' => null],
                (object) ['description' => 'Regulatory sanctions or penalties', 'financial_impact' => null],
            ];
        }

        return $consequences;
    }

    /**
     * Build quarterly risk movement data for heatmap chart.
     */
    private function buildRiskMovementData(int $orgId): array
    {
        $labels   = [];
        $critical = [];
        $high     = [];
        $medium   = [];
        $low      = [];

        for ($q = 3; $q >= 0; $q--) {
            $start = now()->subQuarters($q)->startOfQuarter();
            $end   = now()->subQuarters($q)->endOfQuarter();
            $label = 'Q' . $start->quarter . ' ' . $start->format('Y');
            $labels[] = $label;

            $risksInQuarter = Risk::where('organization_id', $orgId)
                ->where('status', 'active')
                ->where('created_at', '<=', $end)
                ->get();

            $critical[] = $risksInQuarter->filter(fn($r) => ($r->inherent_score ?? (($r->inherent_likelihood ?? 0) * ($r->inherent_impact ?? 0))) >= 20)->count();
            $high[]     = $risksInQuarter->filter(fn($r) => ($s = $r->inherent_score ?? (($r->inherent_likelihood ?? 0) * ($r->inherent_impact ?? 0))) >= 12 && $s < 20)->count();
            $medium[]   = $risksInQuarter->filter(fn($r) => ($s = $r->inherent_score ?? (($r->inherent_likelihood ?? 0) * ($r->inherent_impact ?? 0))) >= 5 && $s < 12)->count();
            $low[]      = $risksInQuarter->filter(fn($r) => ($r->inherent_score ?? (($r->inherent_likelihood ?? 0) * ($r->inherent_impact ?? 0))) < 5)->count();
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

            $critical[] = $risksAtMonth->filter(fn($r) => strtolower($r->inherent_rating ?? '') === 'critical')->count();
            $high[]     = $risksAtMonth->filter(fn($r) => strtolower($r->inherent_rating ?? '') === 'high')->count();
            $medium[]   = $risksAtMonth->filter(fn($r) => strtolower($r->inherent_rating ?? '') === 'medium')->count();
            $low[]      = $risksAtMonth->filter(fn($r) => strtolower($r->inherent_rating ?? '') === 'low')->count();

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
        $labels    = [];
        $completed = [];
        $overdue   = [];

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
     * Build risk movers (risks with biggest score changes).
     */
    private function buildRiskMovers(int $orgId, string $direction): array
    {
        // Get risks ordered by score, simulate movement
        $risks = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereNotNull('inherent_score')
            ->orderByDesc('inherent_score')
            ->limit(20)
            ->get();

        $movers = [];
        foreach ($risks as $risk) {
            $currentScore = $risk->inherent_score ?? 0;
            $residualScore = ($risk->residual_likelihood ?? 0) * ($risk->residual_impact ?? 0);
            $diff = $currentScore - ($residualScore > 0 ? $residualScore : $currentScore);

            if ($direction === 'up' && $diff > 0) {
                $movers[] = (object) [
                    'risk_code'       => $risk->risk_code,
                    'previous_rating' => $risk->residual_rating ?? 'low',
                    'current_rating'  => $risk->inherent_rating ?? 'medium',
                    'score_change'    => $diff,
                ];
            } elseif ($direction === 'down' && $diff < 0) {
                $movers[] = (object) [
                    'risk_code'       => $risk->risk_code,
                    'previous_rating' => $risk->inherent_rating ?? 'high',
                    'current_rating'  => $risk->residual_rating ?? 'medium',
                    'score_change'    => $diff,
                ];
            }
        }

        usort($movers, fn($a, $b) => abs($b->score_change) <=> abs($a->score_change));
        return array_slice($movers, 0, 5);
    }

    /**
     * Build negative correlations from inverse score relationships.
     */
    private function buildNegativeCorrelations($risks): \Illuminate\Support\Collection
    {
        $negCorrs = [];
        $riskList = $risks->values();

        for ($i = 0; $i < min($riskList->count(), 10); $i++) {
            for ($j = $i + 1; $j < min($riskList->count(), 10); $j++) {
                $r1 = $riskList[$i];
                $r2 = $riskList[$j];

                // Synthetic negative correlation: risks in different categories with inverse impact dimensions
                if (($r1->category_id ?? 0) !== ($r2->category_id ?? 0)) {
                    $score1 = $r1->inherent_score ?? 0;
                    $score2 = $r2->inherent_score ?? 0;

                    if ($score1 > 0 && $score2 > 0 && abs($score1 - $score2) > 8) {
                        $coeff = -1 * round(abs($score1 - $score2) / 25, 3);
                        if ($coeff < -0.15) {
                            $negCorrs[] = (object) [
                                'risk_a'       => $r1->risk_code ?? 'R-?',
                                'risk_b'       => $r2->risk_code ?? 'R-?',
                                'coefficient'  => max(-0.9, $coeff),
                                'significance' => abs($coeff) >= 0.5 ? 'high' : 'medium',
                            ];
                        }
                    }
                }
            }
        }

        usort($negCorrs, fn($a, $b) => $a->coefficient <=> $b->coefficient);
        return collect(array_slice($negCorrs, 0, 5));
    }

    /**
     * Build correlation matrix for chart.
     */
    private function buildCorrelationMatrix($risks, $riskControls): array
    {
        $topRisks = $risks->take(8);
        $labels   = $topRisks->pluck('risk_code')->toArray();
        $size     = count($labels);
        $matrix   = array_fill(0, $size, array_fill(0, $size, 0));

        // Fill diagonal with 1.0
        for ($i = 0; $i < $size; $i++) {
            $matrix[$i][$i] = 1.0;
        }

        // Calculate correlations based on shared controls
        for ($i = 0; $i < $size; $i++) {
            for ($j = $i + 1; $j < $size; $j++) {
                $rid1 = $topRisks->values()[$i]->id;
                $rid2 = $topRisks->values()[$j]->id;

                $controls1 = isset($riskControls[$rid1]) ? $riskControls[$rid1]->pluck('control_id')->toArray() : [];
                $controls2 = isset($riskControls[$rid2]) ? $riskControls[$rid2]->pluck('control_id')->toArray() : [];

                $shared = count(array_intersect($controls1, $controls2));
                $total  = max(count($controls1), count($controls2), 1);
                $corr   = round($shared / $total, 3);

                // Also factor in score similarity
                $s1 = $topRisks->values()[$i]->inherent_score ?? 0;
                $s2 = $topRisks->values()[$j]->inherent_score ?? 0;
                $scoreSim = 1 - abs($s1 - $s2) / 25;
                $combined = round(($corr + max(0, $scoreSim)) / 2, 3);

                $matrix[$i][$j] = $combined;
                $matrix[$j][$i] = $combined;
            }
        }

        return ['labels' => $labels, 'data' => $matrix];
    }
}

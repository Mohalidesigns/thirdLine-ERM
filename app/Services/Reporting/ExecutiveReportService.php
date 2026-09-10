<?php

namespace App\Services\Reporting;

use App\Models\KeyRiskIndicator;
use App\Models\LossEvent;
use App\Models\Risk;
use App\Services\RiskAppetiteService;
use Illuminate\Support\Carbon;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Executive report's figures (migration Phase 5.4).
 *
 * Lifted out of ReportController::executive(). Two figures are corrected and
 * both are documented at the line that fixes them:
 *
 *  - the amber KRI tile counted a band value nothing writes;
 *  - the twelve-month trend described the current register projected
 *    backwards rather than the register as it stood.
 *
 * Characterisation/ExecutiveReportCharacterisationTest pins both.
 *
 * The appetite tile is already right and its comment travels with it: it used
 * to read "Within" whenever at least 70% of active risks were not rated
 * Critical — a threshold invented in the method, tested against risk ratings
 * rather than against any tolerance the Board had set.
 */
class ExecutiveReportService
{
    /**
     * Both spellings of the amber band.
     *
     * `KriMeasureMigrator` writes 'amber'; 'yellow' is the legacy value still
     * present in older rows, and MeasureThreshold::bands() lists both. Every
     * other consumer counts both, and so does this one.
     *
     * @var list<string>
     */
    private const AMBER_BANDS = ['amber', 'yellow'];

    /** How many months the trend chart covers. */
    private const TREND_MONTHS = 12;

    public function __construct(private readonly BoardReportService $board) {}

    /**
     * @return array<string, mixed>
     */
    public function figures(string $period = 'quarter', ?int $organizationId = null): array
    {
        $orgId = $organizationId ?? TenantContext::organizationId();
        $dateRange = $this->dateRange($period);

        // KPI values as individual variables for the view
        $totalRisks = Risk::where('organization_id', $orgId)->where('status', 'active')->count();
        $criticalRisks = Risk::where('organization_id', $orgId)->where('status', 'active')
            ->whereIn('inherent_rating', ['Critical', 'High'])->count();
        $financialExposure = LossEvent::where('organization_id', $orgId)
            ->whereBetween('date_of_loss', [$dateRange['start'], $dateRange['end']])
            ->sum(LossEvent::netLossNairaSql());
        $treatmentCompletion = $this->board->treatmentCompletionRate($orgId);
        $kriBreaches = KeyRiskIndicator::where('organization_id', $orgId)
            ->where('current_status', 'red')->count();
        $kriGreen = KeyRiskIndicator::where('organization_id', $orgId)->where('current_status', 'green')->count();
        // THE DEFECT. This counted `current_status = 'yellow'` ALONE. The band
        // vocabulary the measure engine writes is red / amber / green —
        // KriMeasureMigrator emits 'amber' — and every other consumer counts
        // both spellings (BoardPackAssembler: whereIn(['amber','yellow'])).
        // Counting only the legacy spelling meant every KRI the current engine
        // bands as amber was missing from the executive pack's amber tile and
        // from its KRI status chart.
        $kriAmber = KeyRiskIndicator::where('organization_id', $orgId)
            ->whereIn('current_status', self::AMBER_BANDS)->count();
        $kriRed = $kriBreaches;

        // Appetite status from the declared appetite statements, via the same
        // service the Board report and the regulatory return use. This used to
        // read "Within" whenever at least 70% of active risks were not rated
        // Critical — a threshold invented in this method, tested against risk
        // ratings rather than against any tolerance the Board had set, and
        // shown on a tile captioned "Risk Appetite Status". With no appetite
        // statements on record the tile now says so instead of reading green.
        $appetitePositions = (new RiskAppetiteService)->compareAgainstActual($orgId);
        $appetiteStatus = $appetitePositions === []
            ? null
            : (count(array_filter(
                $appetitePositions,
                fn (array $p) => in_array($p['status'], ['exceeds_tolerance', 'exceeds_capacity'], true)
            )) > 0 ? 'Exceeded' : 'Within');

        // Top 10 risks. treatmentPlans is eager-loaded so the Treatment
        // Status column can be derived from real plans — see
        // applyTreatmentStatus().
        $topRisks = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->orderByDesc('inherent_score')
            ->limit(10)
            ->with(['category', 'riskOwner', 'treatmentPlans'])
            ->get();

        $this->board->applyTreatmentStatus($topRisks);

        // Presented for the page. `owner` is an alias relation for
        // `riskOwner`; reading it off the model row by row cost a query each,
        // which the eager load above was already paying for under the other
        // name.
        $topRisks = $topRisks->map(fn (Risk $risk) => [
            'id' => $risk->id,
            'risk_code' => $risk->risk_code,
            'title' => $risk->title,
            'category' => $risk->getRelationValue('category')?->name,
            'residual_rating' => $risk->residual_rating,
            'owner' => $risk->getRelationValue('riskOwner')?->name,
            'derived_treatment_status' => $risk->getAttribute('derived_treatment_status'),
        ])->values();

        // Chart data: Risk by Category
        $categoryRisks = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->selectRaw('category_id, COUNT(*) as count')
            ->groupBy('category_id')
            ->with('category')
            ->get();
        $categoryChartData = [
            'labels' => $categoryRisks->map(fn ($r) => $r->category->name ?? 'Unknown')->values()->toArray(),
            'values' => $categoryRisks->pluck('count')->toArray(),
        ];

        // Chart data: Risk Rating Distribution
        $riskDistribution = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->selectRaw('inherent_rating, COUNT(*) as count')
            ->groupBy('inherent_rating')
            ->get()
            ->keyBy('inherent_rating');
        $ratingChartData = [
            'labels' => ['Critical', 'High', 'Medium', 'Low'],
            'values' => [
                $riskDistribution->get('Critical')->count ?? 0,
                $riskDistribution->get('High')->count ?? 0,
                $riskDistribution->get('Medium')->count ?? 0,
                $riskDistribution->get('Low')->count ?? 0,
            ],
        ];

        $trendChartData = $this->registerTrend($orgId);

        // Chart data: Financial Exposure by Category
        $exposureByCat = LossEvent::where('organization_id', $orgId)
            ->selectRaw('cbn_risk_category, SUM(COALESCE(gross_loss_amount_kobo, 0) - COALESCE(insurance_recovery_kobo, 0) - COALESCE(other_recovery_kobo, 0)) / 100 as total')
            ->groupBy('cbn_risk_category')
            ->get();
        $exposureChartData = [
            'labels' => $exposureByCat->pluck('cbn_risk_category')->map(fn ($v) => $v ?? 'Unclassified')->toArray(),
            'values' => $exposureByCat->pluck('total')->toArray(),
        ];

        // Chart data: KRI Status
        $kriStatusChartData = [
            'labels' => ['Green', 'Amber', 'Red'],
            'values' => [$kriGreen, $kriAmber, $kriRed],
        ];

        return compact(
            'totalRisks', 'criticalRisks', 'financialExposure', 'treatmentCompletion',
            'kriBreaches', 'appetiteStatus', 'kriGreen', 'kriAmber', 'kriRed',
            'topRisks', 'period',
            'categoryChartData', 'ratingChartData', 'trendChartData',
            'exposureChartData', 'kriStatusChartData'
        );
    }

    /**
     * How many risks were on the register at the end of each of the last
     * twelve months.
     *
     * THE DEFECT. This was twelve separate COUNT queries, each filtering
     * `status = 'active'` — the risk's status TODAY — against
     * `created_at <= end of month`. A risk opened in January and archived in
     * June was therefore absent from January's bucket as well, so the series
     * described the current register projected backwards and could only ever
     * slope upward. That is 5.1's finding exactly: a chart labelled "trend"
     * reading today's number for every historical bucket.
     *
     * `risks` carries no status history, so "was it active that month" is not
     * knowable. What IS knowable is when a risk came onto the register, and
     * that is what this counts — using `date_identified` where the register
     * records it and falling back to `created_at`, which is the rule 5.1
     * established for per-bucket existence.
     *
     * One query, bucketed in PHP: grouping by month in SQL needs
     * driver-specific date functions, and this codebase has been bitten by
     * MySQL-only SQL before.
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    private function registerTrend(int $orgId): array
    {
        $start = now()->subMonths(self::TREND_MONTHS - 1)->startOfMonth();

        $existenceDates = Risk::where('organization_id', $orgId)
            ->get(['date_identified', 'created_at'])
            ->map(fn (Risk $risk) => $risk->date_identified ?? $risk->created_at)
            ->filter()
            ->map(fn ($date) => Carbon::parse($date));

        $labels = [];
        $values = [];

        for ($i = 0; $i < self::TREND_MONTHS; $i++) {
            $month = $start->copy()->addMonths($i);
            $end = $month->copy()->endOfMonth();

            $labels[] = $month->format('M Y');
            $values[] = $existenceDates->filter(fn (Carbon $date) => $date->lessThanOrEqualTo($end))->count();
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * The window a period name covers. Carried across unchanged.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    private function dateRange(string $period): array
    {
        return match ($period) {
            'month' => ['start' => now()->startOfMonth(), 'end' => now()->endOfMonth()],
            'year' => ['start' => now()->startOfYear(), 'end' => now()->endOfYear()],
            default => ['start' => now()->startOfQuarter(), 'end' => now()->endOfQuarter()],
        };
    }
}

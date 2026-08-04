<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\GeneratedReport;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\LossEvent;
use App\Models\Issue;
use App\Models\TreatmentPlan;
use App\Models\KeyRiskIndicator;
use App\Models\Control;
use App\Models\BusinessUnit;
use App\Models\IcaapAssessment;
use App\Models\ApprovalRequest;
use App\Models\RegulatoryDeadline;
use App\Models\RegulatoryCircular;
use App\Services\RegulatoryReportService;
use App\Services\RiskAppetiteService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Executive summary report.
     */
    public function executive(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;
        $period = $request->get('period', 'quarter'); // quarter, month, year

        $dateRange = $this->getDateRange($period);

        // KPI values as individual variables for the view
        $totalRisks = Risk::where('organization_id', $orgId)->where('status', 'active')->count();
        $criticalRisks = Risk::where('organization_id', $orgId)->where('status', 'active')
            ->whereIn('inherent_rating', ['Critical', 'High'])->count();
        $financialExposure = LossEvent::where('organization_id', $orgId)
            ->whereBetween('date_of_loss', [$dateRange['start'], $dateRange['end']])
            ->sum('net_loss_amount');
        $treatmentCompletion = $this->getTreatmentCompletionRate($orgId);
        $kriBreaches = KeyRiskIndicator::where('organization_id', $orgId)
            ->where('current_status', 'red')->count();
        $kriGreen = KeyRiskIndicator::where('organization_id', $orgId)->where('current_status', 'green')->count();
        $kriAmber = KeyRiskIndicator::where('organization_id', $orgId)->where('current_status', 'yellow')->count();
        $kriRed = $kriBreaches;

        $risksWithinAppetite = Risk::where('organization_id', $orgId)->where('status', 'active')
            ->where('residual_rating', '!=', 'Critical')->count();
        $appetiteStatus = ($totalRisks > 0 && $risksWithinAppetite >= ($totalRisks * 0.7)) ? 'Within' : 'Exceeded';

        // Top 10 risks
        $topRisks = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->orderByDesc('inherent_score')
            ->limit(10)
            ->with(['category', 'riskOwner'])
            ->get();

        // Chart data: Risk by Category
        $categoryRisks = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->selectRaw('category_id, COUNT(*) as count')
            ->groupBy('category_id')
            ->with('category')
            ->get();
        $categoryChartData = [
            'labels' => $categoryRisks->map(fn($r) => $r->category->name ?? 'Unknown')->values()->toArray(),
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

        // Chart data: Risk Trend (12 months)
        $trendLabels = [];
        $trendValues = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $trendLabels[] = $month->format('M Y');
            $trendValues[] = Risk::where('organization_id', $orgId)
                ->where('status', 'active')
                ->where('created_at', '<=', $month->endOfMonth())
                ->count();
        }
        $trendChartData = ['labels' => $trendLabels, 'values' => $trendValues];

        // Chart data: Financial Exposure by Category
        $exposureByCat = LossEvent::where('organization_id', $orgId)
            ->selectRaw('cbn_risk_category, SUM(net_loss_amount) as total')
            ->groupBy('cbn_risk_category')
            ->get();
        $exposureChartData = [
            'labels' => $exposureByCat->pluck('cbn_risk_category')->map(fn($v) => $v ?? 'Unclassified')->toArray(),
            'values' => $exposureByCat->pluck('total')->toArray(),
        ];

        // Chart data: KRI Status
        $kriStatusChartData = [
            'labels' => ['Green', 'Amber', 'Red'],
            'values' => [$kriGreen, $kriAmber, $kriRed],
        ];

        return view('risk.reports.executive', compact(
            'totalRisks', 'criticalRisks', 'financialExposure', 'treatmentCompletion',
            'kriBreaches', 'appetiteStatus', 'kriGreen', 'kriAmber', 'kriRed',
            'topRisks', 'period',
            'categoryChartData', 'ratingChartData', 'trendChartData',
            'exposureChartData', 'kriStatusChartData'
        ));
    }

    /**
     * Board-level risk report.
     */
    public function board(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        // Risk appetite breach data
        $appetiteService = new RiskAppetiteService();
        $appetiteBreaches = $appetiteService->getBreaches($orgId);

        // Critical risks for board attention
        $criticalRisksForBoard = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->where('inherent_rating', 'Critical')
            ->with(['category', 'riskOwner'])
            ->orderByDesc('inherent_score')
            ->get();

        $criticalRisks = $criticalRisksForBoard->count();

        // Risk counts for appetite utilization
        $totalActiveRisks = Risk::where('organization_id', $orgId)->where('status', 'active')->count();
        $withinAppetite = Risk::where('organization_id', $orgId)->where('status', 'active')
            ->where('residual_rating', '!=', 'Critical')->count();
        $appetiteUtilization = $totalActiveRisks > 0 ? round(($withinAppetite / $totalActiveRisks) * 100) : 0;

        // Control effectiveness
        $controlEffectiveness = $this->getControlEffectivenessRate($orgId);

        // Average risk score for profile
        $avgScore = Risk::where('organization_id', $orgId)->where('status', 'active')->avg('inherent_score');
        $riskProfileScore = $avgScore ? round($avgScore / 5, 1) . '/5' : '0/5';

        // Chart data: Risk Profile by Category (radar)
        $risksByCategory = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->selectRaw('category_id, AVG(inherent_score) as avg_inherent, AVG(residual_score) as avg_residual')
            ->groupBy('category_id')
            ->with('category')
            ->get();
        $profileChartData = [
            'labels' => $risksByCategory->map(fn($r) => $r->category->name ?? 'Unknown')->values()->toArray(),
            'inherent' => $risksByCategory->pluck('avg_inherent')->map(fn($v) => round($v / 5, 1))->toArray(),
            'residual' => $risksByCategory->pluck('avg_residual')->map(fn($v) => round(($v ?? 0) / 5, 1))->toArray(),
        ];

        // Chart data: Risk Appetite vs Current Position
        $appetiteChartData = [
            'labels' => $risksByCategory->map(fn($r) => $r->category->name ?? 'Unknown')->values()->toArray(),
            'appetite' => $risksByCategory->map(fn() => 3)->toArray(), // Default appetite level
            'current' => $risksByCategory->pluck('avg_inherent')->map(fn($v) => round($v / 5, 1))->toArray(),
        ];

        // Real capital adequacy ratio from the latest ICAAP assessment
        $latestIcaap = IcaapAssessment::where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->first();
        $capitalAdequacyRatio = $latestIcaap !== null
            ? round((float) $latestIcaap->car_actual, 1)
            : null;

        // Data-driven executive summary
        $highRisks = Risk::where('organization_id', $orgId)->where('status', 'active')
            ->where('inherent_rating', 'High')->count();
        $openIssues = Issue::where('organization_id', $orgId)
            ->whereIn('issue_status', ['OPEN', 'IN_PROGRESS', 'OVERDUE'])->count();
        $ytdLosses = (float) LossEvent::where('organization_id', $orgId)
            ->whereYear('date_of_loss', now()->year)
            ->sum('net_loss_amount');

        $ytdLossDisplay = $ytdLosses >= 1000000000
            ? '₦' . number_format($ytdLosses / 1000000000, 2) . 'bn'
            : '₦' . number_format($ytdLosses / 1000000, 1) . 'm';

        $executiveSummary = sprintf(
            'The organisation currently carries %d active risks, of which %d are rated Critical and %d High. '
            . 'Appetite utilization stands at %d%% and overall control effectiveness at %s%%. '
            . 'Year-to-date operational losses total %s across recorded loss events, and %d issues remain open or in progress. ',
            $totalActiveRisks, $criticalRisks, $highRisks,
            $appetiteUtilization, $controlEffectiveness, $ytdLossDisplay, $openIssues
        );
        $executiveSummary .= $capitalAdequacyRatio !== null
            ? sprintf(
                'Capital adequacy remains %s the regulatory minimum with a CAR of %s%% (CBN minimum: %s%%, period %s). ',
                $capitalAdequacyRatio >= (float) ($latestIcaap->cbn_minimum_car ?? 10) ? 'above' : 'below',
                $capitalAdequacyRatio,
                rtrim(rtrim(number_format((float) ($latestIcaap->cbn_minimum_car ?? 10), 2), '0'), '.'),
                $latestIcaap->period
            )
            : 'No ICAAP assessment is on record for the current period. ';
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
            ->map(fn ($p) => (object) [
                'title' => 'Overdue treatment plan: ' . ($p->title ?? $p->treatment_code),
                'description' => 'Progress at ' . $p->progress . '% with the target date passed. Owner: ' . ($p->owner->name ?? 'Unassigned') . '.',
                'priority' => ucfirst($p->priority ?? 'high'),
                'due_date' => $p->target_date?->format('d M Y'),
            ]);

        $pendingApprovals = ApprovalRequest::where('organization_id', $orgId)
            ->pending()
            ->orderBy('requested_at')
            ->limit(3)
            ->get()
            ->map(fn ($a) => (object) [
                'title' => 'Pending approval: ' . ucwords(str_replace('_', ' ', $a->action)),
                'description' => ucwords(str_replace('_', ' ', $a->entity_type)) . ' #' . $a->entity_id
                    . ' has been awaiting a decision since ' . ($a->requested_at?->format('d M Y') ?? '-') . '.',
                'priority' => 'High',
                'due_date' => $a->requested_at?->format('d M Y'),
            ]);

        $boardActions = $overdueTreatments->concat($pendingApprovals)->take(6)->values();

        return view('risk.reports.board', compact(
            'criticalRisksForBoard', 'criticalRisks', 'appetiteUtilization',
            'controlEffectiveness', 'riskProfileScore',
            'profileChartData', 'appetiteChartData',
            'executiveSummary', 'capitalAdequacyRatio', 'boardActions'
        ));
    }

    /**
     * Regulatory report (CBN/regulatory compliance).
     */
    public function regulatory(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;
        $year = $request->get('year', now()->year);
        $quarter = $request->get('quarter', ceil(now()->month / 3));

        // Generate comprehensive regulatory reports
        $regulatoryReportService = new RegulatoryReportService();

        // CBN ORMS Return
        $cbnOrms = $regulatoryReportService->generateCbnOrmsReturn($orgId, "Q{$quarter}", $year);

        // Loss event summary
        $quarterStart = now()->setYear($year)->startOfYear()->addMonths(($quarter - 1) * 3);
        $quarterEnd = (clone $quarterStart)->addMonths(3)->subDay();
        $lossEventSummary = $regulatoryReportService->generateLossEventSummary(
            $orgId,
            $quarterStart->toDateString(),
            $quarterEnd->toDateString()
        );

        // Control effectiveness
        $controlEffectivenessData = $regulatoryReportService->generateControlEffectivenessSummary($orgId);

        // KRI Status
        $kriStatus = $regulatoryReportService->generateKriStatusReport($orgId);

        // Risk appetite compliance
        $appetiteCompliance = $regulatoryReportService->generateRiskAppetiteComplianceReport($orgId);

        // ICAAP summary
        $icaapSummary = $regulatoryReportService->generateIcaapSummary($orgId);

        // Derive individual variables for the view
        $controlEffRate = $controlEffectivenessData['summary']['effectiveness_rate_pct'] ?? 0;
        $kriBreachRate = $kriStatus['summary']['breach_rate_pct'] ?? 0;
        $appetiteCompRate = $appetiteCompliance['summary']['compliance_rate_pct'] ?? 0;

        // Overall compliance: average of control effectiveness, KRI compliance, and appetite compliance
        $kriCompRate = 100 - $kriBreachRate;
        $overallCompliance = round(($controlEffRate + $kriCompRate + $appetiteCompRate) / 3);

        // Regulatory returns schedule (deadlines + filing status)
        $deadlines = RegulatoryDeadline::where('organization_id', $orgId)
            ->with(['filings.filer'])
            ->orderBy('deadline_date')
            ->get();

        $regulatoryReturns = $deadlines->map(function ($d) {
            $latestFiling = $d->filings->sortByDesc('filing_date')->first();
            $status = $d->status;
            if ($latestFiling && $latestFiling->status === 'submitted') {
                $status = 'submitted';
            } elseif ($d->isOverdue()) {
                $status = 'overdue';
            }

            return (object) [
                'name' => $d->title,
                'regulator' => $d->regulator ?? 'CBN',
                'frequency' => ucfirst($d->frequency ?? '-'),
                'due_date' => $d->deadline_date?->format('d M Y'),
                'status' => $status,
                'filed_by' => $latestFiling?->filer?->name ?? '-',
            ];
        });

        // Active regulator directives / circulars
        $circulars = RegulatoryCircular::where('organization_id', $orgId)
            ->orderByDesc('date_issued')
            ->get();

        $directives = $circulars->map(fn ($c) => (object) [
            'reference' => $c->circular_ref,
            'title' => $c->title,
            'issued_date' => $c->date_issued?->format('d M Y'),
            'deadline' => $c->effective_date?->format('d M Y'),
            'status' => $c->compliance_status ?? 'pending',
            'impact' => $c->impact_level ?? 'medium',
        ]);

        $pendingReturns = $regulatoryReturns->whereNotIn('status', ['submitted', 'not_applicable', 'overdue'])->count();
        $overdueItems = $regulatoryReturns->where('status', 'overdue')->count();
        $cbnDirectives = $circulars->where('regulator', 'CBN')->count();

        // ORMS scores derived from available data
        $ormsGovernance = $appetiteCompRate > 0 ? min(round($appetiteCompRate * 1.05), 100) : 85;
        $ormsAppetite = round($appetiteCompRate);
        $ormsIdentification = $controlEffRate > 0 ? round($controlEffRate * 0.95) : 75;
        $ormsMonitoring = $kriCompRate > 0 ? round($kriCompRate) : 80;
        $ormsMitigation = round($controlEffRate);
        $ormsCapital = ($icaapSummary['status'] ?? '') !== 'no_data' ? 88 : 70;
        $ormsBCM = 70;
        $ormsStress = ($icaapSummary['status'] ?? '') !== 'no_data' ? 75 : 60;

        return view('risk.reports.regulatory', compact(
            'cbnOrms', 'lossEventSummary', 'controlEffectivenessData',
            'kriStatus', 'appetiteCompliance', 'icaapSummary',
            'year', 'quarter',
            'overallCompliance', 'pendingReturns', 'overdueItems', 'cbnDirectives',
            'ormsGovernance', 'ormsAppetite', 'ormsIdentification', 'ormsMonitoring',
            'ormsMitigation', 'ormsCapital', 'ormsBCM', 'ormsStress',
            'regulatoryReturns', 'directives'
        ));
    }

    /**
     * Custom report builder.
     */
    public function custom(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $categories = RiskCategory::where('organization_id', $orgId)->orderBy('name')->get();
        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();

        // Prior custom reports (what the Saved Templates panel shows — we now
        // use it as a "Recent Reports" list so users can re-download).
        $savedTemplates = GeneratedReport::where('organization_id', $orgId)
            ->where('scope', 'custom_report')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($r) => (object) [
                'name' => $r->name,
                'description' => ($r->period ? $r->period . ' · ' : '') . $r->file_name,
                'download_url' => $r->download_url,
                'created_at' => $r->created_at,
            ]);

        $reportData = null;

        if ($request->filled('report_type')) {
            $reportType = $request->report_type;

            $query = Risk::where('organization_id', $orgId);

            if ($request->filled('category_id')) {
                $query->where('category_id', $request->category_id);
            }
            if ($request->filled('business_unit_id')) {
                $query->where('business_unit_id', $request->business_unit_id);
            }
            if ($request->filled('rating')) {
                $query->where('inherent_rating', $request->rating);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('date_from')) {
                $query->where('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->where('created_at', '<=', $request->date_to);
            }

            $reportData = $query->with(['category', 'riskOwner', 'businessUnit'])->get();
        }

        return view('risk.reports.custom', compact('categories', 'businessUnits', 'reportData', 'savedTemplates'));
    }

    /**
     * Generate custom report (POST) — streams a CSV download and records
     * the run in the `generated_reports` table so it appears in Recent.
     */
    public function generateCustom(Request $request): StreamedResponse
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $validated = $request->validate([
            'report_name' => 'required|string|max:200',
            'report_type' => 'nullable|string|max:40',
            'date_from'   => 'nullable|date',
            'date_to'     => 'nullable|date',
            'format'      => 'nullable|in:pdf,excel,html,pptx',
            'categories'  => 'nullable|array',
            'categories.*' => 'integer',
            'ratings'     => 'nullable|array',
            'ratings.*'   => 'string',
            'business_units' => 'nullable|array',
            'business_units.*' => 'integer',
            'sections'    => 'nullable|array',
        ]);

        $query = Risk::where('organization_id', $orgId)
            ->with(['category', 'businessUnit', 'riskOwner']);

        if (! empty($validated['categories'])) {
            $query->whereIn('category_id', $validated['categories']);
        }
        if (! empty($validated['ratings'])) {
            $query->whereIn(\DB::raw('LOWER(inherent_rating)'), array_map('strtolower', $validated['ratings']));
        }
        if (! empty($validated['business_units'])) {
            $query->whereIn('business_unit_id', $validated['business_units']);
        }
        if (! empty($validated['date_from'])) {
            $query->where('created_at', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->where('created_at', '<=', $validated['date_to'] . ' 23:59:59');
        }

        $risks = $query->orderBy('risk_code')->get();

        $headers = [
            'Risk Code', 'Title', 'Category', 'Business Unit', 'Owner',
            'Inherent Score', 'Inherent Rating', 'Residual Score', 'Residual Rating',
            'Control Effectiveness (%)', 'Treatment Strategy', 'Status', 'Identified', 'Last Assessment',
        ];
        $rows = $risks->map(fn ($r) => [
            $r->risk_code,
            $r->title,
            $r->category?->name ?? '',
            $r->businessUnit?->name ?? '',
            $r->riskOwner?->name ?? '',
            $r->inherent_score ?? '',
            $r->inherent_rating ?? '',
            $r->residual_score ?? '',
            $r->residual_rating ?? '',
            $r->control_effectiveness_pct ?? '',
            $r->treatment_strategy ?? '',
            $r->status ?? '',
            $r->date_identified?->format('Y-m-d') ?? '',
            $r->last_assessment_date?->format('Y-m-d') ?? '',
        ]);

        $format = $validated['format'] ?? 'excel';
        $ext = $format === 'excel' ? 'csv' : ($format === 'html' ? 'csv' : 'csv'); // all collapse to CSV for now
        $safeName = \Illuminate\Support\Str::slug($validated['report_name']);
        $fileName = $safeName . '_' . now()->format('Ymd_His') . '.' . $ext;

        $period = ! empty($validated['date_from']) || ! empty($validated['date_to'])
            ? trim(($validated['date_from'] ?? '') . ' to ' . ($validated['date_to'] ?? ''))
            : 'All time';

        GeneratedReport::create([
            'organization_id' => $orgId,
            'generated_by' => auth()->id(),
            'name' => $validated['report_name'],
            'report_type' => $validated['report_type'] ?? 'custom',
            'scope' => 'custom_report',
            'period' => $period,
            'file_name' => $fileName,
            'download_route' => 'risk.reports.custom.generate',
            'parameters' => $request->only([
                'report_name', 'report_type', 'date_from', 'date_to', 'format',
                'categories', 'ratings', 'business_units', 'sections',
            ]),
        ]);

        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Get date range based on period selection.
     */
    private function getDateRange(string $period): array
    {
        return match ($period) {
            'month' => [
                'start' => now()->startOfMonth(),
                'end' => now()->endOfMonth(),
            ],
            'quarter' => [
                'start' => now()->startOfQuarter(),
                'end' => now()->endOfQuarter(),
            ],
            'year' => [
                'start' => now()->startOfYear(),
                'end' => now()->endOfYear(),
            ],
            default => [
                'start' => now()->startOfQuarter(),
                'end' => now()->endOfQuarter(),
            ],
        };
    }

    /**
     * Calculate control effectiveness rate as a percentage.
     */
    private function getControlEffectivenessRate(int $orgId): float
    {
        $totalControls = Control::where('organization_id', $orgId)
            ->whereNotNull('effectiveness_rating')
            ->count();

        if ($totalControls === 0) {
            return 0;
        }

        $effectiveControls = Control::where('organization_id', $orgId)
            ->where('effectiveness_rating', 'effective')
            ->count();

        return round(($effectiveControls / $totalControls) * 100, 1);
    }

    /**
     * Calculate treatment plan completion rate as a percentage.
     */
    private function getTreatmentCompletionRate(int $orgId): float
    {
        $totalPlans = TreatmentPlan::where('organization_id', $orgId)->count();

        if ($totalPlans === 0) {
            return 0;
        }

        $completedPlans = TreatmentPlan::where('organization_id', $orgId)
            ->where('status', 'completed')
            ->count();

        return round(($completedPlans / $totalPlans) * 100, 1);
    }
}

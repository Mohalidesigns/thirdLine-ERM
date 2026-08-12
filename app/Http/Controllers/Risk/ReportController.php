<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportJob;
use App\Models\ApprovalRequest;
use App\Models\BusinessUnit;
use App\Models\Control;
use App\Models\GeneratedReport;
use App\Models\IcaapAssessment;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\LossEvent;
use App\Models\Organization;
use App\Models\RegulatoryCircular;
use App\Models\RegulatoryDeadline;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\TreatmentPlan;
use App\Services\BoardPackAssembler;
use App\Services\DocumentRenderer;
use App\Services\RegulatoryReportService;
use App\Services\ReportDataService;
use App\Services\RiskAppetiteService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    /**
     * Executive summary report.
     *
     * Renders on screen by default and produces a document when `download` is
     * present. The document format defaults to a branded, paginated PDF, with
     * xlsx and csv as alternates — before this, the only file output anywhere
     * in the reporting module was CSV, and these three reports had no document
     * output at all.
     */
    public function executive(Request $request)
    {
        if ($request->has('download')) {
            return $this->documentResponse($request, 'executive');
        }

        $orgId = TenantContext::organizationId();
        $period = $request->get('period', 'quarter'); // quarter, month, year

        $dateRange = $this->getDateRange($period);

        // KPI values as individual variables for the view
        $totalRisks = Risk::where('organization_id', $orgId)->where('status', 'active')->count();
        $criticalRisks = Risk::where('organization_id', $orgId)->where('status', 'active')
            ->whereIn('inherent_rating', ['Critical', 'High'])->count();
        $financialExposure = LossEvent::where('organization_id', $orgId)
            ->whereBetween('date_of_loss', [$dateRange['start'], $dateRange['end']])
            ->sum(LossEvent::netLossNairaSql());
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
     *
     * With `download` present this returns the assembled board pack — the full
     * ordered section set configured for the organisation — as a single PDF.
     */
    public function board(Request $request)
    {
        if ($request->has('download')) {
            return $this->boardPackResponse($request);
        }

        $orgId = TenantContext::organizationId();

        // Risk appetite breach data
        $appetiteService = new RiskAppetiteService;
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
        $riskProfileScore = $avgScore ? round($avgScore / 5, 1).'/5' : '0/5';

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

        // Chart data: Risk Appetite vs Current Position
        $appetiteChartData = [
            'labels' => $risksByCategory->map(fn ($r) => $r->category->name ?? 'Unknown')->values()->toArray(),
            'appetite' => $risksByCategory->map(fn () => 3)->toArray(), // Default appetite level
            'current' => $risksByCategory->pluck('avg_inherent')->map(fn ($v) => round($v / 5, 1))->toArray(),
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
            ->sum(LossEvent::netLossNairaSql());

        $ytdLossDisplay = $ytdLosses >= 1000000000
            ? '₦'.number_format($ytdLosses / 1000000000, 2).'bn'
            : '₦'.number_format($ytdLosses / 1000000, 1).'m';

        $executiveSummary = sprintf(
            'The organisation currently carries %d active risks, of which %d are rated Critical and %d High. '
            .'Appetite utilization stands at %d%% and overall control effectiveness at %s%%. '
            .'Year-to-date operational losses total %s across recorded loss events, and %d issues remain open or in progress. ',
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
            ->map(fn ($a) => (object) [
                'title' => 'Pending approval: '.ucwords(str_replace('_', ' ', $a->action)),
                'description' => ucwords(str_replace('_', ' ', $a->entity_type)).' #'.$a->entity_id
                    .' has been awaiting a decision since '.($a->requested_at?->format('d M Y') ?? '-').'.',
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
        if ($request->has('download')) {
            return $this->documentResponse($request, 'regulatory');
        }

        $orgId = TenantContext::organizationId();
        $year = $request->get('year', now()->year);
        $quarter = $request->get('quarter', ceil(now()->month / 3));

        // Generate comprehensive regulatory reports
        $regulatoryReportService = new RegulatoryReportService;

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
        $orgId = TenantContext::organizationId();

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
                'description' => ($r->period ? $r->period.' · ' : '').$r->file_name,
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
     * Generate a custom report and return the document.
     *
     * This method used to validate `format in:pdf,excel,html,pptx` and then
     * write a CSV for every one of them — the product offered four formats and
     * shipped one, silently. The accepted set is now exactly what
     * DocumentRenderer produces, and each one returns a genuinely different
     * document: a paginated branded PDF, a styled workbook, or a CSV.
     */
    public function generateCustom(Request $request, DocumentRenderer $renderer, ReportDataService $reportData): Response
    {
        $orgId = TenantContext::organizationId();

        $validated = $request->validate([
            'report_name' => 'required|string|max:200',
            'report_type' => 'nullable|string|max:40',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            // 'excel' is accepted as an alias for xlsx because the existing
            // form posts it; html and pptx are gone rather than silently
            // downgraded.
            'format' => 'nullable|in:pdf,xlsx,excel,csv',
            'categories' => 'nullable|array',
            'categories.*' => 'integer',
            'ratings' => 'nullable|array',
            'ratings.*' => 'string',
            'business_units' => 'nullable|array',
            'business_units.*' => 'integer',
            'sections' => 'nullable|array',
        ]);

        $format = $renderer->normalise($validated['format'] ?? 'pdf');
        $organization = Organization::findOrFail($orgId);
        $asAt = now()->toImmutable();

        $parameters = [
            'report_name' => $validated['report_name'],
            'categories' => $validated['categories'] ?? [],
            'business_units' => $validated['business_units'] ?? [],
            'ratings' => $validated['ratings'] ?? [],
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
        ];

        $payload = $reportData->payload('risk_register', $organization, $asAt, $parameters);
        $payload['organization'] = $organization;
        $payload['generatedBy'] = auth()->user()?->name;
        $payload['generatedAt'] = $asAt;
        $payload['periodAsAt'] = $asAt;

        $rendered = $renderer->render($payload['view'], $payload, $format);

        $fileName = Str::slug($validated['report_name']).'_'.now()->format('Ymd_His').'.'.$rendered['extension'];

        // The bytes are filed so the Recent list serves this exact document
        // later rather than re-running the query against moved data.
        $disk = config('filesystems.default', 'local');
        $path = sprintf('reports/%d/%s', $orgId, $fileName);
        Storage::disk($disk)->put($path, $rendered['content']);

        GeneratedReport::create([
            'organization_id' => $orgId,
            'generated_by' => auth()->id(),
            'name' => $validated['report_name'],
            'report_type' => $validated['report_type'] ?? 'risk_register',
            'scope' => 'custom_report',
            'period' => $payload['periodLabel'],
            'period_as_at' => $asAt->toDateString(),
            'status' => 'completed',
            'progress_pct' => 100,
            'file_name' => $fileName,
            'format' => $rendered['extension'],
            'disk' => $disk,
            'file_path' => $path,
            'mime_type' => $rendered['mime'],
            'size_bytes' => strlen($rendered['content']),
            'completed_at' => now(),
            'parameters' => $parameters + ['format' => $format],
        ]);

        return response($rendered['content'], 200, [
            'Content-Type' => $rendered['mime'],
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Document generation */
    /* ------------------------------------------------------------------ */

    /**
     * Render one of the standard reports as a document and stream it back.
     *
     * Small enough to run inline — these are single-report renders rather than
     * a full board pack. Anything heavier goes through queue() and the job.
     */
    private function documentResponse(Request $request, string $reportType): Response
    {
        /** @var DocumentRenderer $renderer */
        $renderer = app(DocumentRenderer::class);
        /** @var ReportDataService $reportData */
        $reportData = app(ReportDataService::class);

        // PDF is the default: the reason this method exists is that these
        // reports previously had no document output at all, and the only file
        // the module could produce was a CSV.
        $format = $renderer->normalise($request->get('format', 'pdf'));

        $organization = Organization::findOrFail(TenantContext::organizationId());
        $asAt = $request->filled('as_at')
            ? Carbon::parse($request->get('as_at'))->toImmutable()
            : now()->toImmutable();

        $payload = $reportData->payload($reportType, $organization, $asAt);
        $payload['organization'] = $organization;
        $payload['generatedBy'] = auth()->user()?->name;
        $payload['generatedAt'] = now()->toImmutable();
        $payload['periodAsAt'] = $asAt;

        $rendered = $renderer->render($payload['view'], $payload, $format);

        $fileName = sprintf(
            '%s-%s.%s',
            Str::slug($payload['title']),
            $asAt->format('Ymd'),
            $rendered['extension']
        );

        return $this->fileResponse($rendered, $fileName);
    }

    /**
     * The board report as a full assembled pack.
     */
    private function boardPackResponse(Request $request): Response
    {
        /** @var BoardPackAssembler $assembler */
        $assembler = app(BoardPackAssembler::class);

        $organization = Organization::findOrFail(TenantContext::organizationId());
        $asAt = $request->filled('as_at')
            ? Carbon::parse($request->get('as_at'))->toImmutable()
            : now()->toImmutable();

        $built = $assembler->build($organization, $asAt, auth()->user(), $assembler->nextVersion($organization->id));

        return $this->fileResponse($built, sprintf(
            'board-risk-report-%s.pdf',
            $asAt->format('Ymd')
        ));
    }

    /**
     * @param  array{content: string, mime: string, extension: string}  $rendered
     */
    private function fileResponse(array $rendered, string $fileName): Response
    {
        return response($rendered['content'], 200, [
            'Content-Type' => $rendered['mime'],
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }

    /**
     * Queue a report for rendering and return the caller to the status page.
     *
     * Report generation moved off the request cycle because a board pack walks
     * the entire register — every risk, control, indicator, loss event, issue,
     * treatment plan and filing deadline — and then paginates that into a PDF.
     * On a real register that exceeds a web request's execution limit, and the
     * user gets a blank page rather than a document.
     */
    public function queue(Request $request, BoardPackAssembler $assembler)
    {
        $orgId = TenantContext::organizationId();

        $validated = $request->validate([
            'report_type' => 'required|in:'.implode(',', GenerateReportJob::TYPES),
            'name' => 'nullable|string|max:200',
            // Only formats the renderer actually produces. The old validation
            // accepted pdf, excel, html and pptx and wrote a CSV for all four.
            'format' => 'nullable|in:'.implode(',', DocumentRenderer::SUPPORTED).',excel',
            'as_at' => 'nullable|date',
        ]);

        $type = $validated['report_type'];
        $asAt = isset($validated['as_at']) ? Carbon::parse($validated['as_at']) : now();

        // Board packs are versioned per organization so a superseded pack stays
        // retrievable next to the one that replaced it.
        $version = $type === 'board_pack' ? $assembler->nextVersion($orgId) : 1;

        $report = GeneratedReport::create([
            'organization_id' => $orgId,
            'generated_by' => auth()->id(),
            'name' => $validated['name'] ?? $this->defaultReportName($type, $asAt),
            'report_type' => $type,
            'scope' => $type,
            'period' => $asAt->format('F Y'),
            'period_as_at' => $asAt->toDateString(),
            'status' => 'queued',
            'progress_pct' => 0,
            'version' => $version,
            'parameters' => [
                'format' => $type === 'board_pack' ? 'pdf' : ($validated['format'] ?? 'pdf'),
                'as_at' => $asAt->toDateString(),
            ],
        ]);

        GenerateReportJob::dispatch($report->id);

        return redirect()
            ->route('risk.reports.status', $report)
            ->with('success', 'Report queued. This page refreshes until it is ready.');
    }

    /**
     * Progress page for a queued report.
     */
    public function status(GeneratedReport $report)
    {
        $this->assertSameTenant($report);

        return view('risk.reports.status', compact('report'));
    }

    /**
     * JSON progress, polled by the status page.
     */
    public function statusJson(GeneratedReport $report)
    {
        $this->assertSameTenant($report);

        return response()->json([
            'status' => $report->status,
            'progress_pct' => $report->progress_pct,
            'error_message' => $report->error_message,
            'download_url' => $report->hasStoredFile() ? route('risk.reports.download', $report) : null,
        ]);
    }

    /**
     * Serve the stored artifact.
     *
     * The bytes written when the report was generated — not a re-run. A pack
     * the board has seen has to keep being the pack the board has seen.
     */
    public function download(GeneratedReport $report)
    {
        $this->assertSameTenant($report);

        abort_unless($report->hasStoredFile(), 404, 'This report has no stored document.');

        $disk = Storage::disk($report->disk ?? config('filesystems.default'));

        abort_unless($disk->exists($report->file_path), 404, 'The stored document is no longer available.');

        return $disk->download(
            $report->file_path,
            $report->file_name,
            ['Content-Type' => $report->mime_type ?? 'application/octet-stream']
        );
    }

    /**
     * List of generated reports, newest first.
     */
    /**
     * WP-09: the library listing is the shared data grid
     * (App\Grids\Definitions\ReportsLibraryGrid). What remains is the
     * generate-a-report form above it, which needs the report type list.
     */
    public function library()
    {
        return view('risk.reports.library', [
            'types' => GenerateReportJob::TYPES,
        ]);
    }

    /**
     * Board pack section configuration — which sections a pack contains and in
     * what order. This is what makes the pack configurable per organization
     * rather than a fixed template.
     */
    public function boardPackSections(BoardPackAssembler $assembler)
    {
        $organization = Organization::findOrFail(TenantContext::organizationId());

        return view('risk.reports.board-pack-sections', [
            'available' => BoardPackAssembler::SECTIONS,
            'selected' => $assembler->sectionsFor($organization),
        ]);
    }

    public function updateBoardPackSections(Request $request, BoardPackAssembler $assembler)
    {
        $validated = $request->validate([
            'sections' => 'required|array|min:1',
            'sections.*' => 'string|in:'.implode(',', array_keys(BoardPackAssembler::SECTIONS)),
        ]);

        $organization = Organization::findOrFail(TenantContext::organizationId());

        $assembler->configureSections($organization, $validated['sections']);

        return redirect()->route('risk.reports.board-pack.sections')
            ->with('success', 'Board pack sections updated. The next pack generated will use this order.');
    }

    private function defaultReportName(string $type, Carbon $asAt): string
    {
        return match ($type) {
            'board_pack' => 'Board Risk Report — '.$asAt->format('F Y'),
            'executive' => 'Executive Risk Report — '.$asAt->format('F Y'),
            'regulatory' => 'Regulatory Compliance Report — '.$asAt->format('F Y'),
            'risk_register' => 'Risk Register Extract — '.$asAt->format('d M Y'),
            default => ucfirst($type),
        };
    }

    /**
     * Route-model binding already resolves through the tenant scope; this keeps
     * the guarantee if that scope is ever bypassed upstream.
     */
    private function assertSameTenant(GeneratedReport $report): void
    {
        abort_unless(
            (int) $report->organization_id === TenantContext::organizationId(),
            403
        );
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

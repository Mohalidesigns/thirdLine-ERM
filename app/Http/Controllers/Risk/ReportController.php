<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\GenerateCustomReportRequest;
use App\Http\Requests\Reports\QueueReportRequest;
use App\Http\Requests\Reports\UpdateBoardPackSectionsRequest;
use App\Jobs\GenerateReportJob;
use App\Models\BusinessUnit;
use App\Models\Control;
use App\Models\GeneratedReport;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\RegulatoryCircular;
use App\Models\RegulatoryDeadline;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Presenters\GridPresenter;
use App\Services\BoardPackAssembler;
use App\Services\RegulatoryReportService;
use App\Services\ReportDataService;
use App\Services\Reporting\BoardReportService;
use App\Services\Reporting\ExecutiveReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;
use ThirdLine\Reporting\DocumentRenderer;

class ReportController extends Controller
{
    public function __construct(
        private readonly BoardReportService $boardReports,
        private readonly ExecutiveReportService $executiveReports,
    ) {}

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

        return Inertia::render('Reports/Executive', $this->executiveReports->figures(
            $request->string('period', 'quarter')->toString(),
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

        return Inertia::render('Reports/Board', $this->boardReports->figures());
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

        // Derive individual variables for the view. Each rate is null unless
        // something was actually measured — the underlying summaries report a
        // rate of 0 (or, for KRIs, a breach rate of 0 that inverts to a
        // flattering 100%) for an organisation that has recorded nothing at
        // all, and this report presented those as compliance percentages.
        $controlEffRate = ($controlEffectivenessData['summary']['total_controls'] ?? 0) > 0
            ? (float) ($controlEffectivenessData['summary']['effectiveness_rate_pct'] ?? 0)
            : null;
        $kriCompRate = ($kriStatus['summary']['total_kris'] ?? 0) > 0
            ? 100 - (float) ($kriStatus['summary']['breach_rate_pct'] ?? 0)
            : null;
        $appetiteCompRate = ($appetiteCompliance['summary']['total_categories'] ?? 0) > 0
            ? (float) ($appetiteCompliance['summary']['compliance_rate_pct'] ?? 0)
            : null;

        // Overall compliance: the mean of whichever of the three measures the
        // organisation has data for. Averaging in a zero for a measure nobody
        // has populated reported a real deficiency where there was only an
        // empty module; averaging in a 100 did the opposite.
        $measuredRates = array_values(array_filter(
            [$controlEffRate, $kriCompRate, $appetiteCompRate],
            fn (?float $v) => $v !== null
        ));
        $overallCompliance = $measuredRates === []
            ? null
            : (int) round(array_sum($measuredRates) / count($measuredRates));

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
            // Unrated, not Medium: a circular whose impact nobody has
            // assessed does not have a medium impact.
            'impact' => $c->impact_level ?? 'Unrated',
        ]);

        $pendingReturns = $regulatoryReturns->whereNotIn('status', ['submitted', 'not_applicable', 'overdue'])->count();
        $overdueItems = $regulatoryReturns->where('status', 'overdue')->count();
        $cbnDirectives = $circulars->where('regulator', 'CBN')->count();

        // CBN ORMS framework pillars.
        //
        // Only the three pillars this product actually measures carry a score.
        // The rest are reported as not assessed, with the reason stated, and
        // the view renders them as an explicit gap rather than a progress bar.
        //
        // What was here before: Governance was the appetite compliance rate
        // multiplied by 1.05 (an uplift with no basis) or the literal 85;
        // Identification was control effectiveness × 0.95 or the literal 75;
        // Capital Adequacy was 88 or 70 depending only on whether ANY completed
        // simulation existed; Business Continuity was the constant 70 for every
        // tenant on the platform, and the product holds no BCM data of any
        // kind; Stress Testing was 75 or 60 on the same simulation flag. Eight
        // progress bars on a report headed "CBN Regulatory Compliance" of which
        // five were decoration and three were distorted.
        //
        // Each entry is [label, score (0-100 or null), basis shown to the user].
        $ormsPillars = [
            [
                'Risk Governance & Culture',
                null,
                'No governance maturity assessment is captured by this product.',
            ],
            [
                'Risk Appetite & Strategy',
                $appetiteCompRate === null ? null : (int) round($appetiteCompRate),
                $appetiteCompRate === null
                    ? 'No risk appetite statement is on record.'
                    : 'Share of risk categories currently within their declared appetite.',
            ],
            [
                'Risk Identification & Assessment',
                null,
                'No coverage measure for identification and assessment is computed yet.',
            ],
            [
                'Risk Monitoring & Reporting',
                $kriCompRate === null ? null : (int) round($kriCompRate),
                $kriCompRate === null
                    ? 'No key risk indicators are defined.'
                    : 'Share of key risk indicators not currently in breach.',
            ],
            [
                'Risk Mitigation & Control',
                $controlEffRate === null ? null : (int) round($controlEffRate),
                $controlEffRate === null
                    ? 'No control carries an effectiveness rating.'
                    : 'Share of controls rated Effective or Mostly Effective.',
            ],
            [
                'Capital Adequacy (ICAAP)',
                null,
                ($icaapSummary['status'] ?? '') === 'no_data'
                    ? 'No completed quantification run to draw an ICAAP position from.'
                    : 'A quantification run exists, but no ICAAP maturity score is computed from it.',
            ],
            [
                'Business Continuity Management',
                null,
                'Business continuity is not tracked in this product.',
            ],
            [
                'Stress Testing',
                null,
                'No stress testing programme is tracked in this product.',
            ],
        ];

        return Inertia::render('Reports/Regulatory', compact(
            'cbnOrms', 'lossEventSummary', 'controlEffectivenessData',
            'kriStatus', 'appetiteCompliance', 'icaapSummary',
            'year', 'quarter',
            'overallCompliance', 'pendingReturns', 'overdueItems', 'cbnDirectives',
            'ormsPillars',
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

        return Inertia::render('Reports/Custom', [
            'categories' => $categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'businessUnits' => collect($businessUnits)->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'savedTemplates' => collect($savedTemplates)->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
            // The lists the form offers come from the class that validates
            // them; the Blade template held its own copies as literals.
            'sections' => GenerateCustomReportRequest::SECTIONS,
            'reportTypes' => GenerateCustomReportRequest::REPORT_TYPES,
            'ratings' => GenerateCustomReportRequest::RATINGS,
            'formats' => GenerateCustomReportRequest::FORMATS,
            'defaults' => [
                'date_from' => now()->subMonths(3)->format('Y-m-d'),
                'date_to' => now()->format('Y-m-d'),
                'sections' => GenerateCustomReportRequest::DEFAULT_SECTIONS,
            ],
        ]);
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
    public function generateCustom(GenerateCustomReportRequest $request, DocumentRenderer $renderer, ReportDataService $reportData): Response
    {
        $orgId = TenantContext::organizationId();

        $validated = $request->validated();

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
    public function queue(QueueReportRequest $request, BoardPackAssembler $assembler)
    {
        $orgId = TenantContext::organizationId();

        $validated = $request->validated();

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

        return Inertia::render('Reports/Status', [
            'report' => array_merge($report->only([
                'id', 'name', 'report_type', 'status', 'progress_pct', 'error_message',
                'file_name', 'period_as_at', 'version',
            ]), ['size_for_humans' => $report->size_for_humans]),
        ]);
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
    public function library(Request $request, GridPresenter $presenter)
    {
        return Inertia::render('Reports/Library', [
            'types' => GenerateReportJob::TYPES,
            'today' => now()->format('Y-m-d'),
            'grid' => fn () => $presenter->present(GridRegistry::resolve('reports_library'), $request, $request->user()),
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

        return Inertia::render('Reports/BoardPackSections', [
            'available' => BoardPackAssembler::SECTIONS,
            'selected' => array_values($assembler->sectionsFor($organization)),
        ]);
    }

    public function updateBoardPackSections(UpdateBoardPackSectionsRequest $request, BoardPackAssembler $assembler)
    {
        $validated = $request->validated();

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
     *
     * The check itself is GeneratedReportPolicy's now — one place, asked the
     * same way from a screen, a form request or a console command.
     */
    private function assertSameTenant(GeneratedReport $report): void
    {
        Gate::authorize('view', $report);
    }
}

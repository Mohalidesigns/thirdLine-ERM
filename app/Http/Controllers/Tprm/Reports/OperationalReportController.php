<?php

namespace App\Http\Controllers\Tprm\Reports;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Tprm\Reporting\Operational\OperationalReport;
use App\Services\Tprm\Reporting\Operational\OperationalReportRegistry;
use App\Services\Tprm\Reporting\ReportProvenance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * The standard operational reports — FR-RPT-07, FR-RPT-09.
 *
 * TWO PERMISSIONS ARE CHECKED, NOT ONE. `tprm.report.view` opens the hub;
 * each report's own permission decides whether that user may see its data.
 * A relationship owner who was never given `tprm.screening.view` does not get
 * the screening log by walking in through the reports page, and the
 * `abort_unless` below is what stops a guessed URL doing it either.
 *
 * EVERY REPORT EXPORTS IN ALL THREE FORMATS. FR-RPT-09 asks for xlsx, csv and
 * branded PDF on every report, and because a report is a contract rather than
 * a bespoke query, that is one route rather than eight.
 */
class OperationalReportController extends Controller
{
    public function __construct(private readonly OperationalReportRegistry $registry) {}

    public function index(Request $request)
    {
        Gate::authorize('tprm.report.view');

        $reports = $this->registry->forUser($request->user());

        return Inertia::render('Tprm/Reports/Operational', [
            'reports' => array_map(fn (OperationalReport $report) => [
                'key' => $report->key(),
                'title' => $report->title(),
                'description' => $report->description(),
                'permission' => $report->permission(),
                'url' => route('tprm.reports.operational.show', $report->key()),
            ], $reports),
            // Named so the hub can say what is missing rather than silently
            // showing a shorter list than the next person sees.
            'withheld' => array_values(array_map(
                fn (OperationalReport $report) => $report->title(),
                array_filter(
                    $this->registry->all(),
                    fn (OperationalReport $report) => ! $request->user()->can($report->permission()),
                ),
            )),
            'can' => [
                'export' => $request->user()->can('tprm.report.export'),
            ],
        ]);
    }

    public function show(Request $request, string $report)
    {
        Gate::authorize('tprm.report.view');

        $definition = $this->registry->find($report);
        $this->authorizeData($request, $definition);

        $rows = $definition->rows();

        return Inertia::render('Tprm/Reports/OperationalReport', [
            'report' => [
                'key' => $definition->key(),
                'title' => $definition->title(),
                'description' => $definition->description(),
                'headers' => $definition->headers(),
                // The screen shows the first 200 rows and says so. A screening
                // log with 40,000 rows must not be shipped to a browser.
                'rows' => array_slice($rows, 0, 200),
                'row_count' => count($rows),
                'preview_limit' => 200,
            ],
            'provenance' => $this->provenance($request, $definition, count($rows))->toArray(),
            'can' => [
                'export' => $request->user()->can('tprm.report.export'),
            ],
        ]);
    }

    public function export(Request $request, string $report, DocumentRenderer $renderer)
    {
        Gate::authorize('tprm.report.export');

        $definition = $this->registry->find($report);
        $this->authorizeData($request, $definition);

        $format = $renderer->normalise((string) $request->query('format', 'xlsx'));
        $rows = $definition->rows();
        $provenance = $this->provenance($request, $definition, count($rows));

        $document = $format === DocumentRenderer::FORMAT_PDF
            ? [
                'content' => $renderer->pdf('reports.pdf.tprm-operational-report', [
                    'title' => $definition->title(),
                    'subtitle' => $definition->description(),
                    'organization' => $request->user()->organization,
                    'periodAsAt' => $provenance->asAt,
                    'generatedBy' => $request->user()->name,
                    'preparedBy' => $provenance->preparedBy,
                    'provenance' => $provenance->filterProvenance(),
                    'headers' => $definition->headers(),
                    'rows' => $rows,
                    'paper' => 'a3',
                    'orientation' => 'landscape',
                ]),
                'extension' => 'pdf',
                'mime' => 'application/pdf',
            ]
            : $renderer->render('reports.pdf.tprm-operational-report', [
                'headers' => $definition->headers(),
                'rows' => $rows,
                'sheet_name' => $definition->title(),
                'meta' => $provenance->toMeta(),
            ], $format);

        $filename = 'tprm-'.$definition->key().'-'.$provenance->asAt->format('Y-m-d').'.'.$document['extension'];

        return response($document['content'], 200, [
            'Content-Type' => $document['mime'],
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * The report's own permission, on top of the hub's.
     *
     * 403 rather than 404: the report exists, and pretending otherwise would
     * mean a user who later gains the permission cannot tell whether the URL
     * they were given was wrong.
     */
    private function authorizeData(Request $request, OperationalReport $report): void
    {
        abort_unless(
            $request->user()->can($report->permission()),
            403,
            'This report reads data behind '.$report->permission().'.',
        );
    }

    private function provenance(Request $request, OperationalReport $report, int $rowCount): ReportProvenance
    {
        return new ReportProvenance(
            title: $report->title(),
            asAt: CarbonImmutable::now(),
            preparedBy: $request->user()->name,
            reviewedBy: $this->reviewerName($request),
            filters: $report->notes(),
            rowCount: $rowCount,
            versions: ['Scoring engine version' => (string) config('tprm.engine_version')],
            authority: 'FR-RPT-07 — '.$report->description(),
        );
    }

    private function reviewerName(Request $request): ?string
    {
        $id = $request->query('reviewer_id');

        return is_numeric($id) ? User::query()->whereKey((int) $id)->value('name') : null;
    }
}

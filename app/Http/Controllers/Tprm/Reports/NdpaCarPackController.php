<?php

namespace App\Http\Controllers\Tprm\Reports;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Tprm\Reporting\NdpaCarPackBuilder;
use App\Services\Tprm\Reporting\PackExporter;
use App\Services\Tprm\Reporting\ReportProvenance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * The NDPA Compliance Audit Return evidence pack — FR-RPT-03.
 *
 * THE COUNTDOWN IS ON THE SCREEN BECAUSE THE DEADLINE IS THE PRODUCT. GAID
 * Art. 10(7)–(10) sets 31 March and a late-filing penalty of 50% of the filing
 * fee; a pack that can be generated but that nobody remembers to generate is
 * the same as no pack.
 *
 * NOTHING IS FILED FROM HERE. The pack is evidence a data protection officer
 * takes into the return, and the screen says so — the module's rule that no
 * regulator is ever written to holds here as it does for incident drafts.
 */
class NdpaCarPackController extends Controller
{
    public function __construct(
        private readonly NdpaCarPackBuilder $builder,
        private readonly PackExporter $exporter,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('tprm.report.view');

        $sections = $this->builder->sections();

        return Inertia::render('Tprm/Reports/NdpaCarPack', [
            'sections' => array_map(fn (array $section) => [
                'code' => $section['code'],
                'title' => $section['title'],
                'citation' => $section['citation'],
                'coverage' => $section['coverage'],
                'note' => $section['note'],
                'headers' => $section['headers'],
                'row_count' => count($section['rows']),
                'preview' => array_slice($section['rows'], 0, 10),
            ], $sections),
            'countdown' => $this->builder->filingCountdown(),
            'provenance' => $this->provenance($request, $sections)->toArray(),
            'can' => [
                'export' => $request->user()->can('tprm.report.export'),
            ],
        ]);
    }

    public function export(Request $request, DocumentRenderer $renderer)
    {
        Gate::authorize('tprm.report.export');

        $format = $renderer->normalise((string) $request->query('format', 'xlsx'));
        $sections = $this->builder->sections();
        $provenance = $this->provenance($request, $sections);
        $countdown = $this->builder->filingCountdown();

        $document = $this->exporter->export(
            $request,
            $sections,
            $provenance,
            $format,
            'NDPA Compliance Audit Return — third-party evidence pack',
            'GAID Art. 10(7)–(10); filing due 31 March '.($countdown['filing_year'] + 1),
        );

        $filename = 'ndpa-car-evidence-pack-'.$provenance->asAt->format('Y-m-d').'.'.$document['extension'];

        return response($document['content'], 200, [
            'Content-Type' => $document['mime'],
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function provenance(Request $request, array $sections): ReportProvenance
    {
        $countdown = $this->builder->filingCountdown();
        $partial = collect($sections)->where('coverage', 'partial')->pluck('code');

        return new ReportProvenance(
            title: 'NDPA Compliance Audit Return — third-party evidence pack',
            asAt: CarbonImmutable::now(),
            preparedBy: $request->user()->name,
            reviewedBy: $this->reviewerName($request),
            filters: [
                'Population' => 'Engagements recorded as processing personal data',
                'Filing period' => (string) $countdown['filing_year'],
                'Filing deadline' => $countdown['deadline'].' ('.$countdown['days_remaining'].' days)',
                'Sections with gaps' => $partial->isEmpty() ? 'None' : $partial->implode(', '),
            ],
            rowCount: collect($sections)->sum(fn (array $section) => count($section['rows'])),
            versions: [
                'Scoring engine version' => (string) config('tprm.engine_version'),
            ],
            authority: 'NDPA 2023 and GAID 2025, Art. 10(7)–(10). Evidence for the return; not the return itself, '
                .'and nothing here is submitted to the NDPC.',
        );
    }

    private function reviewerName(Request $request): ?string
    {
        $id = $request->query('reviewer_id');

        return is_numeric($id) ? User::query()->whereKey((int) $id)->value('name') : null;
    }
}

<?php

namespace App\Http\Controllers\Tprm\Reports;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Tprm\Reporting\DoraRegisterBuilder;
use App\Services\Tprm\Reporting\ReportProvenance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * The Register of Information — FR-RPT-02.
 *
 * THE XLSX IS THE ARTEFACT. DORA's register is fourteen related tables and a
 * supervisor reads them as one workbook; the screen exists so somebody can see
 * what is in it and what is missing before they send it, not as the primary
 * output.
 *
 * CSV TAKES ONE TABLE AT A TIME, BY NAME. A fourteen-table register flattened
 * into one CSV is not the register, and silently exporting only the largest
 * table would be worse. The parameter is required rather than defaulted for
 * the same reason: a caller who does not say which table they want has not
 * decided, and guessing for them produces a file they will not check.
 */
class DoraRegisterController extends Controller
{
    public function __construct(private readonly DoraRegisterBuilder $builder) {}

    public function index(Request $request)
    {
        Gate::authorize('tprm.report.view');

        $tables = $this->builder->tables();

        return Inertia::render('Tprm/Reports/DoraRegister', [
            'tables' => array_map(fn (array $table) => [
                'code' => $table['code'],
                'title' => $table['title'],
                'coverage' => $table['coverage'],
                'note' => $table['note'],
                'headers' => $table['headers'],
                'row_count' => count($table['rows']),
                // The first ten rows only. A register with four thousand
                // sub-processor edges must not be shipped to the browser to
                // let somebody check the shape of it.
                'preview' => array_slice($table['rows'], 0, 10),
            ], $tables),
            'provenance' => $this->provenance($request, $tables)->toArray(),
            'caveat' => 'Field cardinality has not been reconciled against Commission Implementing Regulation '
                .'(EU) 2024/2956 Annexes I/II; the ESAs issued field changes in JC 2024 79. Verify before an EU '
                .'deployment relies on this as a submission.',
            'can' => [
                'export' => $request->user()->can('tprm.report.export'),
            ],
        ]);
    }

    public function export(Request $request, DocumentRenderer $renderer)
    {
        Gate::authorize('tprm.report.export');

        $format = $renderer->normalise((string) $request->query('format', 'xlsx'));
        $tables = $this->builder->tables();
        $provenance = $this->provenance($request, $tables);

        $document = match ($format) {
            DocumentRenderer::FORMAT_XLSX => [
                'content' => $renderer->workbook($this->builder->sheets($tables, $provenance)),
                'extension' => 'xlsx',
                'mime' => $renderer->mimeFor(DocumentRenderer::FORMAT_XLSX),
            ],
            DocumentRenderer::FORMAT_CSV => $this->singleTableCsv($renderer, $tables, $provenance, $request),
            DocumentRenderer::FORMAT_PDF => [
                'content' => $renderer->pdf('reports.pdf.tprm-dora-register', [
                    'title' => 'Register of Information',
                    'subtitle' => 'DORA Article 28(3) — templates RT.01.01 to RT.07.01',
                    'organization' => $request->user()->organization,
                    'periodAsAt' => $provenance->asAt,
                    'generatedBy' => $request->user()->name,
                    'preparedBy' => $provenance->preparedBy,
                    'reviewedBy' => $provenance->reviewedBy,
                    'reviewRequired' => true,
                    'provenance' => $provenance->filterProvenance(),
                    'tables' => $tables,
                    'paper' => 'a3',
                    'orientation' => 'landscape',
                ]),
                'extension' => 'pdf',
                'mime' => 'application/pdf',
            ],
            // `normalise()` has already refused anything outside the supported
            // set, so this arm is unreachable. It is spelt out rather than
            // folded into the PDF branch, because rendering the wrong format
            // for an unrecognised one is the defect DocumentRenderer exists to
            // stop.
            default => throw new \InvalidArgumentException("Unsupported register format [{$format}]."),
        };

        $filename = 'register-of-information-'.$provenance->asAt->format('Y-m-d').'.'.$document['extension'];

        return response($document['content'], 200, [
            'Content-Type' => $document['mime'],
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  list<array<string, mixed>>  $tables
     * @return array{content: string, extension: string, mime: string}
     */
    private function singleTableCsv(DocumentRenderer $renderer, array $tables, ReportProvenance $provenance, Request $request): array
    {
        $code = (string) $request->query('table', '');
        $table = collect($tables)->firstWhere('code', $code);

        abort_if($table === null, 422, 'Name the template to export, for example table=RT.02.02. '
            .'A register of information is fourteen tables and a CSV holds one.');

        return [
            'content' => $renderer->csv(
                $table['headers'],
                $table['rows'],
                $provenance->toMeta() + [
                    'Template' => $table['code'].' — '.$table['title'],
                    'Coverage' => ucfirst($table['coverage']),
                    'Note' => (string) $table['note'],
                ],
            ),
            'extension' => 'csv',
            'mime' => $renderer->mimeFor(DocumentRenderer::FORMAT_CSV),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tables
     */
    private function provenance(Request $request, array $tables): ReportProvenance
    {
        $partial = collect($tables)->where('coverage', DoraRegisterBuilder::COVERAGE_PARTIAL)->pluck('code');

        return new ReportProvenance(
            title: 'Register of Information',
            asAt: CarbonImmutable::now(),
            preparedBy: $request->user()->name,
            reviewedBy: $this->reviewerName($request),
            filters: [
                'Population' => 'ICT services, outsourcing and intra-group arrangements',
                'Tables' => count($tables).' templates, RT.01.01 to RT.07.01',
                'Partial coverage' => $partial->isEmpty() ? 'None' : $partial->implode(', '),
            ],
            rowCount: collect($tables)->sum(fn (array $table) => count($table['rows'])),
            versions: [
                'Scoring engine version' => (string) config('tprm.engine_version'),
            ],
            authority: 'DORA (EU) 2022/2554 Article 28(3); used as the internal register structure for '
                .'Nigerian deployments, exceeding CBN Cyber Appendix II §1.4',
        );
    }

    private function reviewerName(Request $request): ?string
    {
        $id = $request->query('reviewer_id');

        return is_numeric($id) ? User::query()->whereKey((int) $id)->value('name') : null;
    }
}

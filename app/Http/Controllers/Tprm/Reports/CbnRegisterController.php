<?php

namespace App\Http\Controllers\Tprm\Reports;

use App\Http\Controllers\Controller;
use App\Models\BusinessUnit;
use App\Models\User;
use App\Services\Tprm\Reporting\CbnRegisterBuilder;
use App\Services\Tprm\Reporting\RegisterFilters;
use App\Services\Tprm\Reporting\ReportProvenance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * The Third-Party Register return — FR-RPT-01, AC-13.
 *
 * THE SCREEN AND THE EXPORT ARE ONE QUERY WITH ONE ARGUMENT. AC-13 requires
 * the export to reconcile row for row to the filtered view, and the only
 * durable way to hold that is for neither to own a query of its own:
 * `RegisterFilters::fromRequest()` parses the same request in both actions and
 * `CbnRegisterBuilder` answers both. `CbnRegisterReconcilesTest` asserts it,
 * because the failure mode here is silent — an export that is subtly wider
 * than the screen looks complete and is unfalsifiable without a second source.
 *
 * THE REVIEWER IS A NAMED USER, NOT A TYPED STRING. A supervisory return
 * claiming review by "Risk Committee" attests nothing. An unnamed reviewer is
 * printed as "Not reviewed" rather than left off the cover.
 */
class CbnRegisterController extends Controller
{
    public function __construct(private readonly CbnRegisterBuilder $builder) {}

    public function index(Request $request)
    {
        Gate::authorize('tprm.report.view');

        $filters = RegisterFilters::fromRequest($request);
        $rows = $this->builder->rows($filters);

        return Inertia::render('Tprm/Reports/CbnRegister', [
            'columns' => $this->builder->columns(),
            'rows' => $rows,
            'summary' => $this->builder->summary($rows),
            'provenance' => $this->provenance($request, $filters, $rows->count())->toArray(),
            'filters' => $filters->toQuery(),
            'options' => [
                'business_units' => BusinessUnit::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (BusinessUnit $unit) => ['value' => $unit->getKey(), 'label' => $unit->name]),
                'reviewers' => $this->reviewerOptions(),
            ],
            'can' => [
                'export' => $request->user()->can('tprm.report.export'),
            ],
        ]);
    }

    public function export(Request $request, DocumentRenderer $renderer)
    {
        Gate::authorize('tprm.report.export');

        $format = $renderer->normalise((string) $request->query('format', 'xlsx'));

        $filters = RegisterFilters::fromRequest($request);
        $rows = $this->builder->rows($filters);
        $columns = $this->builder->columns();
        $provenance = $this->provenance($request, $filters, $rows->count());

        $headers = array_column($columns, 'label');
        $tabular = $rows->map(fn (array $row) => array_map(
            // A null residual is written as an empty cell rather than as 0.
            // Zero is a score; "not scored yet" is not, and a spreadsheet that
            // conflates them averages a fiction.
            fn (array $column) => $row[$column['key']] ?? null,
            $columns
        ))->all();

        $document = $format === DocumentRenderer::FORMAT_PDF
            ? [
                'content' => $renderer->pdf('reports.pdf.tprm-cbn-register', [
                    'title' => $provenance->title,
                    'subtitle' => $provenance->authority,
                    'organization' => $request->user()->organization,
                    'periodAsAt' => $provenance->asAt,
                    'generatedBy' => $request->user()->name,
                    'preparedBy' => $provenance->preparedBy,
                    'reviewedBy' => $provenance->reviewedBy,
                    'reviewRequired' => true,
                    'provenance' => $provenance->filterProvenance(),
                    'columns' => $columns,
                    'rows' => $rows,
                    'summary' => $this->builder->summary($rows),
                    'paper' => 'a3',
                    'orientation' => 'landscape',
                ]),
                'extension' => 'pdf',
                'mime' => 'application/pdf',
            ]
            : $renderer->render('reports.pdf.tprm-cbn-register', [
                'headers' => $headers,
                'rows' => $tabular,
                'sheet_name' => 'ICT third-party register',
                'meta' => $provenance->toMeta(),
            ], $format);

        $filename = 'cbn-ict-third-party-register-'.$provenance->asAt->format('Y-m-d').'.'.$document['extension'];

        return response($document['content'], 200, [
            'Content-Type' => $document['mime'],
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function provenance(Request $request, RegisterFilters $filters, int $rowCount): ReportProvenance
    {
        return new ReportProvenance(
            title: 'ICT third-party and cloud service provider register',
            asAt: CarbonImmutable::now(),
            preparedBy: $request->user()->name,
            reviewedBy: $this->reviewerName($request),
            filters: $filters->provenance(),
            rowCount: $rowCount,
            versions: [
                'Scoring engine version' => (string) config('tprm.engine_version'),
            ],
            authority: 'CBN Risk-Based Cybersecurity Framework, Appendix II §1.4',
        );
    }

    private function reviewerName(Request $request): ?string
    {
        $id = $request->query('reviewer_id');

        if (! is_numeric($id)) {
            return null;
        }

        // Tenant-scoped by the global scope on User, so a reviewer id from
        // another organisation resolves to null rather than to a name.
        return User::query()->whereKey((int) $id)->value('name');
    }

    /**
     * Who may be named as reviewer: people who can see this return at all.
     *
     * @return array<int, array{value: int, label: string}>
     */
    private function reviewerOptions(): array
    {
        return User::query()
            ->permission('tprm.report.view')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['value' => $user->getKey(), 'label' => $user->name])
            ->all();
    }
}

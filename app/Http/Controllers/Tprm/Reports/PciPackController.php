<?php

namespace App\Http\Controllers\Tprm\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tprm\Engagement;
use App\Models\User;
use App\Services\Tprm\Reporting\PackExporter;
use App\Services\Tprm\Reporting\PciPackBuilder;
use App\Services\Tprm\Reporting\ReportProvenance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * The PCI DSS 12.8 pack — FR-RPT-04.
 *
 * THE SUMMARY LEADS WITH THE FAILURES, not the count of providers. A QSA is
 * not asking how many TPSPs there are; they are asking which ones have no
 * agreement, no current attestation and no confirmed responsibility matrix.
 * A screen that opened with "6 service providers" would be answering the
 * easier question.
 *
 * IT ALSO COUNTS WHAT IS OUTSIDE ITS OWN SCOPE. `pci_in_scope` is a decision
 * somebody made per engagement, and "we have four TPSPs" is only true if
 * somebody looked at the rest. The unscoped count is on the screen for that
 * reason, marked as not a PCI figure.
 */
class PciPackController extends Controller
{
    public function __construct(
        private readonly PciPackBuilder $builder,
        private readonly PackExporter $exporter,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('tprm.report.view');

        $sections = $this->builder->sections();

        return Inertia::render('Tprm/Reports/PciPack', [
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
            'summary' => $this->builder->summary($this->scopedEngagements()),
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

        $document = $this->exporter->export(
            $request,
            $sections,
            $provenance,
            $format,
            'PCI DSS requirement 12.8 pack',
            'PCI DSS v4.0.1 requirements 12.8.1 to 12.8.5',
        );

        $filename = 'pci-dss-12-8-pack-'.$provenance->asAt->format('Y-m-d').'.'.$document['extension'];

        return response($document['content'], 200, [
            'Content-Type' => $document['mime'],
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Engagement>
     */
    private function scopedEngagements()
    {
        return Engagement::query()->where('pci_in_scope', true)->get();
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function provenance(Request $request, array $sections): ReportProvenance
    {
        $partial = collect($sections)->where('coverage', 'partial')->pluck('code');

        return new ReportProvenance(
            title: 'PCI DSS requirement 12.8 pack',
            asAt: CarbonImmutable::now(),
            preparedBy: $request->user()->name,
            reviewedBy: $this->reviewerName($request),
            filters: [
                'Population' => 'Engagements scoped in for PCI DSS',
                'Currency test' => 'Attestations of Compliance issued within twelve months (12.8.4)',
                'Sections with gaps' => $partial->isEmpty() ? 'None' : $partial->implode(', '),
            ],
            rowCount: collect($sections)->sum(fn (array $section) => count($section['rows'])),
            versions: [
                'Scoring engine version' => (string) config('tprm.engine_version'),
            ],
            authority: 'PCI DSS v4.0.1 requirement 12.8',
        );
    }

    private function reviewerName(Request $request): ?string
    {
        $id = $request->query('reviewer_id');

        return is_numeric($id) ? User::query()->whereKey((int) $id)->value('name') : null;
    }
}

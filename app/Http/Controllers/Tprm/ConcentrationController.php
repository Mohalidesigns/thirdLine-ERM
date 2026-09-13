<?php

namespace App\Http\Controllers\Tprm;

use App\Http\Controllers\Controller;
use App\Models\Tprm\Document;
use App\Models\Tprm\NthPartyEdge;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\Graph\ConcentrationAnalyzer;
use App\Services\Tprm\Graph\ConcentrationService;
use App\Services\Tprm\Graph\NthPartyGraph;
use App\Services\Tprm\Graph\NthPartyService;
use App\Services\Tprm\Graph\SubprocessorDiscoverer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The supply-chain screen — FR-NTH-02 through FR-NTH-05.
 *
 * THE SCREEN LEADS WITH THE TABLE, NOT THE GRAPH. A force-directed diagram of
 * forty vendors is the demo everybody asks for and the artefact nobody makes a
 * decision from; the ranked single-points-of-failure list is what goes in a
 * board pack. The graph is offered beside it, and the table is not a fallback
 * for it — both are always rendered, which is also what makes the screen
 * usable from a keyboard and a screen reader without a parallel "accessible
 * version" nobody maintains.
 *
 * `analysis` IS READ FROM THE STORED SNAPSHOT WHERE ONE EXISTS. Recomputing on
 * every page view would mean two people looking at the same screen five
 * minutes apart could see different HHIs and neither could tell why. Running
 * is an explicit action, and the screen says when the figures are from.
 */
class ConcentrationController extends Controller
{
    public function __construct(
        private readonly ConcentrationService $concentration,
        private readonly ConcentrationAnalyzer $analyzer,
        private readonly NthPartyGraph $graph,
        private readonly NthPartyService $edges,
        private readonly SubprocessorDiscoverer $discoverer,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('tprm.graph.view');

        $organizationId = (int) $request->user()->organization_id;
        $dimension = $request->string('dimension')->toString() ?: 'provider_group';

        if (! array_key_exists($dimension, ConcentrationAnalyzer::DIMENSIONS)) {
            $dimension = 'provider_group';
        }

        $trend = $this->concentration->trend($organizationId, $dimension);
        $latest = $trend['current'];

        return Inertia::render('Tprm/Concentration/Index', [
            'dimension' => $dimension,
            'dimensions' => collect(ConcentrationAnalyzer::DIMENSIONS)
                ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])
                ->values(),
            'analysis' => $latest === null ? null : [
                'run_at' => $latest->run_at?->toDayDateTimeString(),
                'hhi' => (float) $latest->hhi,
                'band' => $this->analyzer->band((float) $latest->hhi),
                'band_label' => $this->analyzer->bandLabel((float) $latest->hhi),
                'clusters' => $latest->results['clusters'] ?? $latest->results,
                'spof' => $latest->spof_list ?? [],
                'breaches' => $latest->threshold_breaches ?? [],
                'movement' => $trend['movement'],
            ],
            'bandEdges' => $this->analyzer->bandEdges(),
            'thresholds' => [
                'max_critical_functions_per_group' => config('tprm.scoring.concentration.max_critical_functions_per_group'),
                'max_spend_share_per_group' => config('tprm.scoring.concentration.max_spend_share_per_group'),
            ],
            'graph' => fn () => $this->graphPayload($organizationId, $request),
            'proposals' => fn () => $this->proposals($organizationId),
            'can' => [
                'manage' => $request->user()->can('tprm.graph.manage'),
            ],
        ]);
    }

    /**
     * The discovery proposals awaiting a person.
     *
     * @return list<array<string, mixed>>
     */
    private function proposals(int $organizationId): array
    {
        return $this->discoverer->pending($organizationId)
            ->map(fn (NthPartyEdge $edge): array => [
                'id' => $edge->getKey(),
                'parent' => $edge->parent->legal_name ?? null,
                'child' => $edge->displayName(),
                'matched' => $edge->child_third_party_id !== null,
                'source' => $edge->disclosure_source->value,
                'source_label' => $edge->disclosure_source->label(),
                'service_description' => $edge->service_description,
            ])
            ->values()
            ->all();
    }

    /**
     * The graph, rooted at every third party we hold a direct engagement with.
     *
     * @return array<string, mixed>
     */
    private function graphPayload(int $organizationId, Request $request): array
    {
        $depth = (int) $request->integer('depth', $this->graph->defaultDepth());

        $roots = ThirdParty::query()
            ->where('organization_id', $organizationId)
            ->whereHas('engagements', fn ($q) => $q->whereNotIn('status', ['draft', 'terminated', 'archived']))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return $this->graph->descendants($roots, $depth) + ['depth' => $depth];
    }

    /** Run the analysis now — FR-NTH-03. */
    public function run(Request $request)
    {
        Gate::authorize('tprm.graph.manage');

        $dimension = $request->string('dimension')->toString() ?: 'provider_group';

        if (! array_key_exists($dimension, ConcentrationAnalyzer::DIMENSIONS)) {
            $dimension = 'provider_group';
        }

        $analysis = $this->concentration->run((int) $request->user()->organization_id, $dimension);

        $breaches = count($analysis->threshold_breaches ?? []);

        return back()->with('success', $breaches === 0
            ? sprintf('Concentration analysed. Index %s — no thresholds breached.', $analysis->hhi)
            : sprintf(
                'Concentration analysed. Index %s, with %d threshold %s breached.',
                $analysis->hhi,
                $breaches,
                $breaches === 1 ? '' : 's',
            ));
    }

    /** One vendor's chain, for the drill-down — FR-NTH-01. */
    public function chain(Request $request, ThirdParty $thirdParty)
    {
        Gate::authorize('tprm.graph.view');

        return response()->json([
            'chain' => $this->graph->chainTable(
                $thirdParty->getKey(),
                (int) $request->integer('depth', $this->graph->defaultDepth()),
            ),
            'undeclared' => $this->graph->undeclared($thirdParty->getKey())->values(),
        ]);
    }

    /**
     * Read a document for sub-processors — FR-NTH-06.
     *
     * TWO ACTS, AND THE SECOND IS THE INTERESTING ONE. Discovery proposes
     * edges; the undeclared sweep then asks which entities appear in evidence
     * and in NOTHING the vendor declared, and raises a finding for each. That
     * second question is the one a bank cannot answer today, and it is
     * answerable here only because both facts are rows rather than prose.
     */
    public function discover(Request $request, Document $document)
    {
        Gate::authorize('tprm.graph.manage');

        $result = $this->discoverer->discoverFromDocument($document, $request->user()->id);

        if ($result['unavailable'] !== null) {
            return back()->with('error', $result['unavailable']);
        }

        $parent = $document->owner_type === Document::OWNER_THIRD_PARTY
            ? ThirdParty::query()->find($document->owner_id)
            : null;

        $findings = $parent === null
            ? collect()
            : $this->edges->raiseUndeclaredFindings($parent, $request->user()->id);

        return back()->with('success', sprintf(
            '%d sub-processor(s) proposed from %d line(s) of text; %d line(s) matched nothing in the known-provider '
            .'list.%s',
            count($result['proposed']),
            $result['scanned_lines'],
            $result['unmatched_lines'],
            $findings->isEmpty() ? '' : sprintf(' %d undeclared sub-processor finding(s) raised.', $findings->count()),
        ));
    }

    public function confirmEdge(Request $request, NthPartyEdge $edge)
    {
        Gate::authorize('tprm.graph.manage');

        $result = $this->edges->confirm($edge, $request->user()->id);

        if (! $result['confirmed']) {
            return back()->with('error', $result['reason']);
        }

        return back()->with('success', sprintf('%s confirmed as a sub-processor.', $edge->displayName()));
    }

    public function rejectEdge(Request $request, NthPartyEdge $edge)
    {
        Gate::authorize('tprm.graph.manage');

        $this->edges->reject($edge, $request->user()->id);

        return back()->with('success', sprintf('%s rejected.', $edge->displayName()));
    }
}

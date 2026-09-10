<?php

namespace App\Http\Controllers\Tprm;

use App\Enums\Tprm\ClausePresence;
use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Models\Tprm\ClauseLibraryEntry;
use App\Models\Tprm\Contract;
use App\Models\Tprm\ContractClause;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Waiver;
use App\Presenters\GridPresenter;
use App\Services\Tprm\Contracts\ActivationGuard;
use App\Services\Tprm\Contracts\ClauseAnalyzer;
use App\Services\Tprm\Contracts\ClauseResolver;
use App\Services\Tprm\Contracts\ContractService;
use App\Services\Tprm\Contracts\ObligationExtractor;
use App\Services\Tprm\Extraction\LlmClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * The contract register and the contract workspace — TRD §8.6, §11.
 *
 * The workspace answers three questions in the order a reviewer asks them:
 * what does this contract commit us to, what is missing, and can the
 * engagement go live. The third is a consequence of the second and the screen
 * says so — an activation banner naming the clauses rather than a disabled
 * button with a tooltip.
 */
class ContractController extends Controller
{
    public function __construct(
        private readonly ContractService $contracts,
        private readonly ClauseResolver $resolver,
        private readonly ClauseAnalyzer $analyzer,
    ) {}

    public function index(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('viewAny', Contract::class);

        return Inertia::render('Tprm/Contracts/Index', [
            'summary' => fn () => $this->summary(),
            'grid' => fn () => $presenter->present(
                GridRegistry::resolve('tprm_contracts'),
                $request,
                $request->user()
            ),
            'can' => [
                'manage' => $request->user()->can('tprm.contract.manage'),
            ],
        ]);
    }

    public function show(Request $request, Contract $contract)
    {
        Gate::authorize('view', $contract);

        $contract->load([
            'engagement.thirdParty:id,legal_name,slug,uuid',
            'engagement.relationshipOwner:id,name',
            'parent:id,reference,title',
            'children:id,parent_contract_id,reference,title,contract_type,effective_date,status',
            'document:id,uuid,title',
            'internalSignatory:id,name',
        ]);

        $resolution = $this->resolver->resolve($contract->engagement, $contract);

        return Inertia::render('Tprm/Contracts/Show', [
            'contract' => $this->payload($contract),
            'family' => $contract->family()->map(fn (Contract $row) => [
                'id' => $row->getKey(),
                'reference' => $row->reference,
                'title' => $row->title,
                'type' => $row->contract_type,
                'status' => $row->status,
                'effective_date' => $row->effective_date?->toDateString(),
                'is_current' => $row->is($contract),
                'url' => route('tprm.contracts.show', $row),
            ])->values(),
            'clauses' => $resolution->toArray(),
            // The activation verdict, computed live rather than read from the
            // denormalised count — the count colours a badge on a list, and
            // nothing gates on it.
            'activation' => app(ActivationGuard::class)->check($contract->engagement)->toArray(),
            'obligations' => $contract->obligations()
                ->with('owner:id,name')
                ->orderBy('next_due_date')
                ->get()
                ->map(fn ($obligation) => [
                    'id' => $obligation->getKey(),
                    'title' => $obligation->title,
                    'obligor' => $obligation->obligor,
                    'frequency' => $obligation->frequency,
                    'next_due_date' => $obligation->next_due_date?->toDateString(),
                    'status' => $obligation->status->value,
                    'owner' => $obligation->owner?->name,
                    'breach_count' => $obligation->breach_count,
                ])->values(),
            'capabilities' => [
                'ai_clause_analysis' => app(LlmClient::class)->enabled(LlmClient::CLAUSE_ANALYSIS),
            ],
            'can' => [
                'manage' => $request->user()->can('tprm.contract.manage'),
                'waive' => $request->user()->can('tprm.waiver.approve'),
            ],
        ]);
    }

    public function store(Request $request, Engagement $engagement)
    {
        Gate::authorize('create', Contract::class);

        $validated = $request->validate([
            'contract_type' => 'required|in:'.implode(',', Contract::TYPES),
            'title' => 'required|string|max:255',
            'parent_contract_id' => 'nullable|integer',
            'counterparty_signatory' => 'nullable|string|max:200',
            'internal_signatory_id' => 'nullable|integer',
            'effective_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after_or_equal:effective_date',
            'renewal_type' => 'required|in:'.implode(',', Contract::RENEWAL_TYPES),
            'renewal_term_months' => 'nullable|integer|min:1|max:120',
            'notice_period_days_entity' => 'nullable|integer|min:0|max:730',
            'notice_period_days_provider' => 'nullable|integer|min:0|max:730',
            'governing_law_country' => 'nullable|string|size:2',
            'dispute_forum' => 'nullable|string|max:200',
            'value_minor' => 'nullable|integer|min:0',
            'currency' => 'nullable|string|size:3',
            'document_id' => 'nullable|integer',
            'status' => 'required|in:draft,in_negotiation,executed',
        ]);

        $contract = $this->contracts->create($engagement, $validated, $request->user()->id);

        return redirect()
            ->route('tprm.contracts.show', $contract)
            ->with('success', 'The contract was recorded. Run the clause analysis to see what it commits both sides to.');
    }

    public function update(Request $request, Contract $contract)
    {
        Gate::authorize('update', $contract);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'parent_contract_id' => 'sometimes|nullable|integer',
            'contract_type' => 'sometimes|in:'.implode(',', Contract::TYPES),
            'counterparty_signatory' => 'nullable|string|max:200',
            'internal_signatory_id' => 'nullable|integer',
            'effective_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after_or_equal:effective_date',
            'renewal_type' => 'sometimes|in:'.implode(',', Contract::RENEWAL_TYPES),
            'renewal_term_months' => 'nullable|integer|min:1|max:120',
            'notice_period_days_entity' => 'nullable|integer|min:0|max:730',
            'notice_period_days_provider' => 'nullable|integer|min:0|max:730',
            'governing_law_country' => 'nullable|string|size:2',
            'dispute_forum' => 'nullable|string|max:200',
            'value_minor' => 'nullable|integer|min:0',
            'currency' => 'nullable|string|size:3',
            'document_id' => 'nullable|integer',
            'status' => 'sometimes|in:draft,in_negotiation,executed,expired,terminated',
        ]);

        $this->contracts->update($contract, $validated, $request->user()->id);

        return back()->with('success', 'The contract was updated.');
    }

    /** Run the clause analysis, or list the clauses for manual determination. */
    public function analyse(Request $request, Contract $contract)
    {
        Gate::authorize('update', $contract);

        $outcome = $this->analyzer->analyse($contract, $request->user()->id);

        return $outcome->succeeded()
            ? back()->with('success', sprintf(
                'The contract was read and %d of %d applicable clause(s) have a proposed determination. Each '
                .'needs accepting before it counts — an unreviewed detection does not open the activation gate.',
                $outcome->detected,
                $outcome->applicable,
            ))
            // Not an error flash. AI off, a scanned PDF or no document attached
            // are the module working as configured, and the clause list is on
            // the page either way.
            : back()->with('info', $outcome->message);
    }

    /** Record a determination by hand, or accept/correct a machine one. */
    public function determineClause(Request $request, Contract $contract, ClauseLibraryEntry $clause)
    {
        Gate::authorize('update', $contract);

        $validated = $request->validate([
            'presence' => 'required|in:'.implode(',', array_column(ClausePresence::cases(), 'value')),
            'located_text' => 'nullable|string',
            'page_reference' => 'nullable|string|max:60',
        ]);

        $this->analyzer->record(
            $contract,
            $clause,
            ClausePresence::from($validated['presence']),
            $validated['located_text'] ?? null,
            $validated['page_reference'] ?? null,
            $request->user()->id,
        );

        return back()->with('success', 'The determination was recorded.');
    }

    public function reviewClause(Request $request, Contract $contract, ContractClause $contractClause)
    {
        Gate::authorize('update', $contract);
        abort_unless($contractClause->contract_id === $contract->getKey(), 404);

        $validated = $request->validate([
            'accept' => 'required|boolean',
            'corrected_to' => 'nullable|in:'.implode(',', array_column(ClausePresence::cases(), 'value')),
        ]);

        $this->analyzer->review(
            $contractClause,
            (bool) $validated['accept'],
            isset($validated['corrected_to']) ? ClausePresence::from($validated['corrected_to']) : null,
            $request->user()->id,
        );

        return back()->with('success', 'The clause was reviewed.');
    }

    /**
     * Waive a blocking clause — FR-CTR-05.
     *
     * The rationale and the expiry are required, not optional. A waiver with
     * no expiry is a deletion with extra steps, and one with no rationale is
     * unreviewable by the committee it is reported to.
     */
    public function waiveClause(Request $request, Contract $contract, ContractClause $contractClause)
    {
        Gate::authorize('tprm.waiver.approve');
        abort_unless($contractClause->contract_id === $contract->getKey(), 404);

        $validated = $request->validate([
            'rationale' => 'required|string|min:20|max:2000',
            'compensating_controls' => 'nullable|string|max:2000',
            'expires_at' => 'required|date|after:today',
            'approver_role' => 'nullable|string|max:120',
        ]);

        $waiver = Waiver::create([
            'organization_id' => $contract->organization_id,
            'waivable_type' => Waiver::TYPE_BLOCKING_CLAUSE,
            'waivable_id' => $contractClause->getKey(),
            'engagement_id' => $contract->engagement_id,
            'rationale' => $validated['rationale'],
            'compensating_controls' => $validated['compensating_controls'] ?? null,
            'requested_by' => $request->user()->id,
            'requested_at' => now(),
            'approver_id' => $request->user()->id,
            'approver_role' => $validated['approver_role'] ?? null,
            'approved_at' => now(),
            'expires_at' => $validated['expires_at'],
            'status' => Waiver::STATUS_APPROVED,
        ]);

        $contractClause->forceFill(['waiver_id' => $waiver->getKey()])->save();
        $this->contracts->refreshBlockingGapCount($contract);

        return back()->with('success', sprintf(
            'The clause was waived until %s. It remains a gap on the report and appears on the override '
            .'register, which is reported to the risk committee.',
            $waiver->expires_at?->toFormattedDateString(),
        ));
    }

    /** Generate the obligations the contract's accepted clauses create. */
    public function generateObligations(Request $request, Contract $contract, ObligationExtractor $extractor)
    {
        Gate::authorize('update', $contract);

        $result = $extractor->generate($contract, $request->user()->id);

        return back()->with('success', sprintf(
            '%d obligation(s) created, %d already on the register. %d were not created because their clause is '
            .'still a gap — a duty nobody agreed to is not a duty.',
            $result['created'],
            $result['existing'],
            $result['skipped_gaps'],
        ));
    }

    /**
     * The Clause Gap Report — the artefact that goes to the vendor.
     */
    public function gapReport(Request $request, Contract $contract, DocumentRenderer $renderer)
    {
        Gate::authorize('view', $contract);

        $contract->loadMissing(['engagement.thirdParty', 'organization']);
        $resolution = $this->resolver->resolve($contract->engagement, $contract)->toArray();

        $gaps = collect($resolution['clauses'])
            ->reject(fn (array $row) => $row['satisfied'])
            ->values()
            ->all();

        $pdf = $renderer->pdf('reports.pdf.tprm-clause-gap', [
            'title' => 'Contract clause gap report — '.$contract->reference,
            'organization' => $contract->getRelationValue('organization'),
            'engagement' => $contract->engagement,
            'thirdParty' => $contract->engagement?->thirdParty,
            'contract' => $contract,
            'clauses' => $gaps,
            'unresolvable' => $resolution['unresolvable'],
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="clause-gap-'.$contract->reference.'.pdf"',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Contract $contract): array
    {
        return [
            'id' => $contract->getKey(),
            'reference' => $contract->reference,
            'title' => $contract->title,
            'type' => $contract->contract_type,
            'status' => $contract->status,
            'engagement' => [
                'id' => $contract->engagement?->getKey(),
                'reference' => $contract->engagement?->reference,
                'name' => $contract->engagement?->name,
                'status' => $contract->engagement?->status?->value,
                'url' => $contract->engagement ? route('tprm.engagements.show', $contract->engagement) : null,
            ],
            'third_party' => $contract->engagement?->thirdParty?->legal_name,
            'counterparty_signatory' => $contract->counterparty_signatory,
            'internal_signatory' => $contract->internalSignatory?->name,
            'effective_date' => $contract->effective_date?->toDateString(),
            'expiry_date' => $contract->expiry_date?->toDateString(),
            'days_until_expiry' => $contract->daysUntilExpiry(),
            'renewal_type' => $contract->renewal_type,
            'renewal_term_months' => $contract->renewal_term_months,
            'notice_period_days_entity' => $contract->notice_period_days_entity,
            'notice_period_days_provider' => $contract->notice_period_days_provider,
            // The two numbers FR-CTR-02 exists for.
            'notice_deadline' => $contract->noticeDeadline()?->toDateString(),
            'days_until_notice' => $contract->daysUntilNotice(),
            'notice_window_missed' => $contract->noticeWindowMissed(),
            'renews_automatically' => $contract->renewsAutomatically(),
            'governing_law_country' => $contract->governing_law_country,
            'dispute_forum' => $contract->dispute_forum,
            'value_minor' => $contract->value_minor,
            'currency' => $contract->currency,
            'clause_analysis_status' => $contract->clause_analysis_status,
            'blocking_gaps_count' => $contract->blocking_gaps_count,
            'document' => $contract->document === null ? null : [
                'uuid' => $contract->document->uuid,
                'title' => $contract->document->title,
                'url' => route('tprm.documents.show', $contract->document),
            ],
            'parent' => $contract->parent === null ? null : [
                'id' => $contract->parent->getKey(),
                'reference' => $contract->parent->reference,
                'title' => $contract->parent->title,
                'url' => route('tprm.contracts.show', $contract->parent),
            ],
        ];
    }

    /** @return array<string, int> */
    private function summary(): array
    {
        $base = fn () => Contract::query();

        return [
            'total' => $base()->count(),
            'in_force' => $base()->inForce()->count(),
            // The tile FR-CTR-02 is about: what has to be decided in the next
            // ninety days, counted from the notice deadline rather than the
            // expiry.
            'notice_due_90' => $base()->inForce()->noticeDueWithin(90)->count(),
            'blocking_gaps' => $base()->where('blocking_gaps_count', '>', 0)->count(),
            'unanalysed' => $base()->inForce()->where('clause_analysis_status', 'not_started')->count(),
        ];
    }
}

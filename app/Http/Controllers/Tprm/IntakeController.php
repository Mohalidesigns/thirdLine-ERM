<?php

namespace App\Http\Controllers\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\EngagementType;
use App\Exceptions\Tprm\ProhibitedOutsourcingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tprm\SubmitIntakeRequest;
use App\Models\BusinessUnit;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Category;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\IntakeService;
use App\Support\Tprm\DefaultRuleset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The Intake Wizard and the Intake Queue (TRD §11).
 *
 * The wizard's steps are Service → Function → Data → Access → Commercial →
 * Inherent questionnaire → Live tier preview → Duplicate check → Submit. The
 * preview and the submission run the SAME service methods over the SAME
 * ruleset, which is why `preview` exists on `TieringService` rather than the
 * page computing an approximation client-side: a preview that disagrees with
 * what gets persisted is worse than no preview.
 *
 * The prohibited-function block renders inline with its citation. It arrives
 * here as an exception from the service rather than as a validation error,
 * because the rule lives on the write path where every caller meets it —
 * see IntakeService.
 */
class IntakeController extends Controller
{
    public function __construct(private readonly IntakeService $intake) {}

    /** The approver's queue (FR-INT-04). */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Engagement::class);

        $queue = Engagement::query()
            ->with(['thirdParty:id,legal_name', 'businessUnit:id,name', 'relationshipOwner:id,name'])
            ->where('status', EngagementStatus::IntakeSubmitted->value)
            ->orderByRaw(
                "CASE effective_tier WHEN 'critical' THEN 4 WHEN 'high' THEN 3 "
                ."WHEN 'moderate' THEN 2 WHEN 'low' THEN 1 ELSE 0 END DESC"
            )
            ->orderBy('created_at')
            ->paginate(25)
            ->withQueryString();

        $queue->through(fn (Engagement $engagement) => [
            'id' => $engagement->getKey(),
            'reference' => $engagement->reference,
            'name' => $engagement->name,
            'third_party' => $engagement->thirdParty?->legal_name,
            'business_unit' => $engagement->businessUnit?->name,
            'requester' => $engagement->relationshipOwner?->name,
            'tier' => $engagement->effective_tier?->value,
            'tier_label' => $engagement->effective_tier?->label(),
            'inherent_score' => $engagement->inherent_score,
            'supports_critical_function' => (bool) $engagement->supports_critical_function,
            'has_sponsor' => $engagement->executive_sponsor_id !== null,
            'approval_chain' => $this->intake->approvalChainFor($engagement),
            'submitted_at' => $engagement->created_at?->toDayDateTimeString(),
            'url' => route('tprm.engagements.show', $engagement),
        ]);

        return Inertia::render('Tprm/Intake/Index', [
            'queue' => $queue,
            'can' => [
                'approve' => $request->user()->can('tprm.intake.approve'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', Engagement::class);

        return Inertia::render('Tprm/Intake/Create', [
            'options' => $this->wizardOptions(),
            'questionnaire' => $this->questionnaire(),
            'preselectedThirdParty' => $request->integer('third_party') ?: null,
        ]);
    }

    /**
     * The live tier preview (FR-INT-02).
     *
     * Computes and persists nothing. Returns the same derivation structure the
     * engagement workspace renders, so the requester sees at intake exactly
     * what the reviewer will see afterwards.
     */
    public function preview(Request $request)
    {
        Gate::authorize('create', Engagement::class);

        $validated = $request->validate([
            'business_function_ids' => ['array'],
            'answers' => ['array'],
        ]);

        $outcome = $this->intake->previewTier(
            $request->only([
                'third_party_id', 'engagement_type', 'processes_personal_data',
                'cross_border', 'transfer_basis', 'pci_in_scope', 'substitutability',
            ]),
            array_map('intval', $validated['business_function_ids'] ?? []),
            $validated['answers'] ?? [],
        );

        return response()->json([
            'score' => round($outcome->inherent->score, 1),
            'tier_from_score' => $outcome->inherent->tier->value,
            'effective_tier' => $outcome->effectiveTier->value,
            'effective_tier_label' => $outcome->effectiveTier->label(),
            'raised_by_knockout' => $outcome->tierRaisedByKnockout(),
            'knockouts' => $outcome->knockouts->toArray(),
            'factors' => array_map(
                fn ($factor) => $factor->toArray(),
                $outcome->inherent->factors
            ),
            'warnings' => $outcome->inherent->warnings,
        ]);
    }

    public function store(SubmitIntakeRequest $request)
    {
        try {
            $result = $this->intake->submit(
                $request->safe()->except(['business_function_ids', 'answers']),
                array_map('intval', $request->input('business_function_ids', [])),
                $request->input('answers', []),
                $request->user()->id,
            );
        } catch (ProhibitedOutsourcingException $exception) {
            // AC-01: the citation renders inline. `back()` rather than a 403,
            // because the user is permitted to raise intakes — what is refused
            // is this particular arrangement, and the difference matters to
            // whoever reads the message.
            return back()
                ->withInput()
                ->with('prohibitedFunctions', $exception->details())
                ->with('error', $exception->getMessage());
        }

        $message = "Intake {$result->engagement->reference} was submitted and tiered "
            .($result->outcome->effectiveTier->label()).'.';

        return redirect()
            ->route('tprm.engagements.show', $result->engagement)
            ->with('success', $message)
            // FR-INT-05: advisory, shown on arrival rather than blocking.
            ->with('similarEngagements', $result->similarEngagements->map(fn (Engagement $e) => [
                'reference' => $e->reference,
                'name' => $e->name,
                'third_party' => $e->thirdParty?->legal_name,
                'url' => route('tprm.engagements.show', $e),
            ])->values()->all());
    }

    public function approve(Request $request, Engagement $engagement)
    {
        Gate::authorize('approveIntake', $engagement);

        $result = $this->intake->approve($engagement, $request->user()->id);

        return $result['approved']
            ? back()->with('success', "{$engagement->reference} was approved.")
            : back()->with('error', $result['reason']);
    }

    public function reject(Request $request, Engagement $engagement)
    {
        Gate::authorize('approveIntake', $engagement);

        // FR-INT-07: a reason code is mandatory, and the record is retained.
        $validated = $request->validate([
            'reason_code' => ['required', 'string', 'max:60'],
            'rationale' => ['required', 'string', 'max:2000'],
        ]);

        $rejected = $this->intake->reject(
            $engagement,
            $validated['reason_code'],
            $validated['rationale'],
            $request->user()->id,
        );

        return $rejected
            ? back()->with('success', "{$engagement->reference} was returned to the requester.")
            : back()->with('error', 'This intake is not awaiting a decision.');
    }

    /* ------------------------------------------------------------------ */

    /**
     * The Appendix A questionnaire, built from the ruleset that will score it.
     *
     * One list, shared by the form, the validator and the calculator — so an
     * answer the form offers cannot be one the calculator does not recognise.
     *
     * @return array<string, mixed>
     */
    private function questionnaire(): array
    {
        $factors = DefaultRuleset::factors();

        $options = fn (string $code) => array_map(
            fn (array $option) => ['value' => $option['value'], 'label' => $option['label']],
            $factors[$code]['options'] ?? []
        );

        $bands = fn (array $map, array $labels) => array_map(
            fn (string $key) => ['value' => $key, 'label' => $labels[$key] ?? $key],
            array_keys($map)
        );

        return [
            'A1' => ['question' => 'What is the highest classification of data the third party will access, process, store or transmit?', 'factor' => 'DATA', 'type' => 'select', 'options' => $options('DATA')],
            'A2' => ['question' => 'Approximately how many data subjects are involved?', 'factor' => 'DATA', 'type' => 'select', 'options' => $bands(DefaultRuleset::dataVolumeBands(), [
                'none' => 'No personal data', 'under_1k' => 'Fewer than 1,000', '1k_100k' => '1,000 – 100,000',
                '100k_1m' => '100,001 – 1,000,000', 'over_1m' => 'More than 1,000,000',
            ])],
            'A3' => ['question' => 'Will personal data be transferred or accessed outside Nigeria?', 'factor' => 'GEO', 'type' => 'select', 'options' => $options('GEO')],
            'A4' => ['question' => 'Where will data be stored at rest and processed?', 'factor' => 'GEO', 'type' => 'country'],
            'A5' => ['question' => 'What level of access to our systems is required?', 'factor' => 'ACCESS', 'type' => 'select', 'options' => $options('ACCESS')],
            'A8' => ['question' => 'Longest tolerable outage before material impact', 'factor' => 'CRIT', 'type' => 'select', 'options' => $bands(DefaultRuleset::rtoBands(), [
                'under_4h' => '4 hours or less', 'under_24h' => 'Up to 24 hours',
                'under_72h' => 'Up to 72 hours', 'over_72h' => 'More than 72 hours',
            ])],
            'A9' => ['question' => 'Would failure of this service be visible to customers?', 'factor' => null, 'type' => 'select', 'options' => [
                ['value' => 'no', 'label' => 'No'], ['value' => 'indirectly', 'label' => 'Indirectly'], ['value' => 'directly', 'label' => 'Directly'],
            ]],
            'A10' => ['question' => 'Which regulatory regimes apply to this service?', 'factor' => 'REG', 'type' => 'multiselect', 'options' => $options('REG')],
            'A11' => ['question' => 'Is a regulated activity being performed on our behalf?', 'factor' => null, 'type' => 'boolean', 'knockout' => 'KO-REGACT'],
            'A12' => ['question' => 'How readily could this provider be replaced?', 'factor' => 'SUB', 'type' => 'select', 'options' => $options('SUB')],
            'A13' => ['question' => 'Estimated time to transition to an alternative', 'factor' => 'SUB', 'type' => 'select', 'options' => [
                ['value' => 'under_1m', 'label' => 'Under 1 month'], ['value' => '1m_3m', 'label' => '1 – 3 months'],
                ['value' => '3m_6m', 'label' => '3 – 6 months'], ['value' => 'over_6m', 'label' => 'More than 6 months'],
            ]],
            'A14' => ['question' => 'Estimated annual spend', 'factor' => 'FIN', 'type' => 'select', 'options' => $options('FIN')],
            'A15' => ['question' => 'Will the provider sub-contract any part of this service?', 'factor' => null, 'type' => 'select', 'options' => [
                ['value' => 'no', 'label' => 'No'], ['value' => 'disclosed', 'label' => 'Yes, disclosed'],
                ['value' => 'undisclosed', 'label' => 'Yes, undisclosed'], ['value' => 'unknown', 'label' => 'Unknown'],
            ]],
            'A16' => ['question' => 'Will the provider have physical access to our premises or data centres?', 'factor' => null, 'type' => 'select', 'options' => [
                ['value' => 'no', 'label' => 'No'], ['value' => 'escorted', 'label' => 'Yes, escorted'], ['value' => 'unescorted', 'label' => 'Yes, unescorted'],
            ]],
            'A17' => ['question' => 'Is cardholder data in scope?', 'factor' => null, 'type' => 'boolean', 'knockout' => 'KO-CHD'],
            'A18' => ['question' => 'Is this an intra-group arrangement?', 'factor' => null, 'type' => 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private function wizardOptions(): array
    {
        return [
            'thirdParties' => ThirdParty::query()
                ->orderBy('legal_name')->get(['id', 'legal_name', 'status'])->values(),
            'categories' => Category::query()
                ->where('is_active', true)->orderBy('sort_order')->get(['id', 'name'])->values(),
            'businessUnits' => BusinessUnit::query()->orderBy('name')->get(['id', 'name'])->values(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->values(),
            // The prohibition flag travels to the client so the wizard can warn
            // as the function is selected — but the BLOCK is server-side, and
            // the flag here is a courtesy rather than the control.
            'businessFunctions' => BusinessFunction::query()
                ->where('is_active', true)->orderBy('function_code')
                ->get(['id', 'function_code', 'name', 'criticality', 'rto_hours', 'is_prohibited_outsourcing', 'prohibition_citation'])
                ->values(),
            'engagementTypes' => collect(EngagementType::cases())
                ->map(fn (EngagementType $t) => ['value' => $t->value, 'label' => $t->label()])->values(),
            'transferBases' => collect(Engagement::TRANSFER_BASES)
                ->map(fn (string $b) => ['value' => $b, 'label' => ucwords(str_replace('_', ' ', $b))])->values(),
            'cloudModels' => collect(Engagement::CLOUD_MODELS)
                ->map(fn (string $m) => ['value' => $m, 'label' => strtoupper($m)])->values(),
            'substitutability' => collect(Engagement::SUBSTITUTABILITY)
                ->map(fn (string $s) => ['value' => $s, 'label' => ucfirst($s)])->values(),
        ];
    }
}

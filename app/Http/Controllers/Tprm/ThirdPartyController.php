<?php

namespace App\Http\Controllers\Tprm;

use App\Enums\Tprm\ThirdPartyStatus;
use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tprm\StoreThirdPartyRequest;
use App\Http\Requests\Tprm\UpdateThirdPartyRequest;
use App\Models\Tprm\Category;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\Evidence\CertificateRegister;
use App\Models\User;
use App\Presenters\GridPresenter;
use App\Services\Tprm\ThirdPartyDeduplicator;
use App\Services\Tprm\ThirdPartyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The Third-Party Register — TRD §11, and the CBN Cyber Framework
 * Appendix II §1.4 artefact.
 *
 * Thin, as every controller in this product is (standard §1): the list is a
 * GridDefinition, the rules are in Form Requests, the writes are in
 * ThirdPartyService and the authority is in ThirdPartyPolicy. What is here is
 * the shape of the page's props.
 */
class ThirdPartyController extends Controller
{
    public function __construct(
        private readonly ThirdPartyService $thirdParties,
        private readonly ThirdPartyDeduplicator $deduplicator,
    ) {}

    public function index(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('viewAny', ThirdParty::class);

        return Inertia::render('Tprm/ThirdParties/Index', [
            // Header counters the grid does not own. Each is a count over the
            // same tenant-scoped model the grid queries, so the tiles and the
            // list can never disagree about the population.
            'summary' => fn () => $this->summary(),
            'grid' => fn () => $presenter->present(
                GridRegistry::resolve('tprm_third_parties'),
                $request,
                $request->user()
            ),
            'can' => [
                'create' => $request->user()->can('create', ThirdParty::class),
                'import' => $request->user()->can('tprm.create'),
                'export' => $request->user()->can('tprm.report.export'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', ThirdParty::class);

        return Inertia::render('Tprm/ThirdParties/Form', [
            'thirdParty' => null,
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StoreThirdPartyRequest $request)
    {
        $result = $this->thirdParties->create(
            $request->safe()->except('accept_duplicate'),
            $request->user()->id,
            (bool) $request->boolean('accept_duplicate'),
        );

        // FR-TPR-02: candidates are PRESENTED, not blocked. The form comes
        // back with the merge list and a confirm button rather than a
        // validation error, because a near-match is frequently a real second
        // company and refusing it teaches people to misspell names to get in.
        if ($result['third_party'] === null) {
            return back()
                ->withInput()
                ->with('duplicateCandidates', $this->candidatePayload($result['candidates']));
        }

        return redirect()
            ->route('tprm.third-parties.show', $result['third_party'])
            ->with('success', "{$result['third_party']->legal_name} was added to the register.");
    }

    public function show(Request $request, ThirdParty $thirdParty)
    {
        Gate::authorize('view', $thirdParty);

        $thirdParty->load([
            'category:id,name',
            'relationshipOwner:id,name',
            'oversightOwner:id,name',
            'ultimateParent:id,legal_name,slug,uuid',
            'contacts', 'locations', 'ownership',
            'engagements' => fn ($query) => $query
                ->with('businessUnit:id,name')
                ->orderByDesc('effective_tier')
                ->orderBy('reference'),
        ]);

        return Inertia::render('Tprm/ThirdParties/Show', [
            'thirdParty' => $this->profilePayload($thirdParty),
            'engagements' => $thirdParty->engagements->map(fn ($engagement) => [
                'id' => $engagement->getKey(),
                'uuid' => $engagement->uuid,
                'reference' => $engagement->reference,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'status_label' => $engagement->status->label(),
                'tier' => $engagement->effective_tier?->value,
                'tier_label' => $engagement->effective_tier?->label(),
                'inherent_score' => $engagement->inherent_score,
                'residual_score' => $engagement->residual_score,
                'business_unit' => $engagement->businessUnit?->name,
                'url' => route('tprm.engagements.show', $engagement),
            ])->values(),
            // FR-DDL-07. Deferred, because a profile is often opened to read
            // the engagement list and the register runs a scope comparison per
            // certificate per engagement.
            'certificates' => fn () => $request->user()->can('tprm.evidence.view')
                ? app(CertificateRegister::class)->for($thirdParty)
                : null,
            'can' => [
                'edit' => $request->user()->can('update', $thirdParty),
                'delete' => $request->user()->can('delete', $thirdParty),
                'raiseIntake' => $request->user()->can('tprm.create'),
                'viewEvidence' => $request->user()->can('tprm.evidence.view'),
            ],
        ]);
    }

    public function edit(Request $request, ThirdParty $thirdParty)
    {
        Gate::authorize('update', $thirdParty);

        return Inertia::render('Tprm/ThirdParties/Form', [
            'thirdParty' => $thirdParty->only([
                'id', 'uuid', 'legal_name', 'trading_name', 'registration_number', 'tax_id', 'lei',
                'entity_type', 'ownership_type', 'country_of_incorporation', 'country_of_hq',
                'website', 'year_established', 'employee_band', 'ultimate_parent_id',
                'is_intra_group', 'category_id', 'relationship_owner_id', 'oversight_owner_id',
                'status', 'notes',
            ]),
            'options' => $this->formOptions($thirdParty),
        ]);
    }

    public function update(UpdateThirdPartyRequest $request, ThirdParty $thirdParty)
    {
        $this->thirdParties->update($thirdParty, $request->validated(), $request->user()->id);

        return redirect()
            ->route('tprm.third-parties.show', $thirdParty)
            ->with('success', 'The register entry was updated.');
    }

    /**
     * Change the entity's lifecycle status.
     *
     * A wrong-state answer is a flash message rather than a 403 (standard §3):
     * the user is permitted to change the status, and what is wrong is the
     * record's readiness — a 403 would tell them they lack an authority they
     * actually hold.
     */
    public function changeStatus(Request $request, ThirdParty $thirdParty)
    {
        Gate::authorize('update', $thirdParty);

        $validated = $request->validate([
            'status' => ['required', \Illuminate\Validation\Rule::in(ThirdPartyStatus::values())],
        ]);

        $result = $this->thirdParties->changeStatus(
            $thirdParty,
            ThirdPartyStatus::from($validated['status']),
            $request->user()->id,
        );

        return $result['changed']
            ? back()->with('success', 'Status updated.')
            : back()->with('error', $result['reason']);
    }

    /**
     * Live duplicate check for the create form (FR-TPR-02), so candidates
     * appear as the user types rather than after they submit.
     */
    public function duplicateCheck(Request $request)
    {
        Gate::authorize('create', ThirdParty::class);

        $candidates = $this->deduplicator->candidatesFor([
            'legal_name' => $request->string('legal_name')->toString(),
            'registration_number' => $request->string('registration_number')->toString(),
            'tax_id' => $request->string('tax_id')->toString(),
            'lei' => $request->string('lei')->toString(),
        ]);

        return response()->json(['candidates' => $this->candidatePayload($candidates)]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  \Illuminate\Support\Collection<int, array{third_party: \App\Models\Tprm\ThirdParty, kind: string, on: string, confidence: float}>  $candidates
     * @return list<array<string, mixed>>
     */
    private function candidatePayload(\Illuminate\Support\Collection $candidates): array
    {
        return $candidates->map(fn (array $candidate) => [
            'id' => $candidate['third_party']->getKey(),
            'legal_name' => $candidate['third_party']->legal_name,
            'registration_number' => $candidate['third_party']->registration_number,
            'status' => $candidate['third_party']->status?->label(),
            'kind' => $candidate['kind'],
            'on' => $candidate['on'],
            'confidence' => $candidate['confidence'],
            'url' => route('tprm.third-parties.show', $candidate['third_party']),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function profilePayload(ThirdParty $thirdParty): array
    {
        return [
            'id' => $thirdParty->getKey(),
            'uuid' => $thirdParty->uuid,
            'legal_name' => $thirdParty->legal_name,
            'trading_name' => $thirdParty->trading_name,
            'registration_number' => $thirdParty->registration_number,
            'tax_id' => $thirdParty->tax_id,
            'lei' => $thirdParty->lei,
            'entity_type' => $thirdParty->entity_type,
            'country_of_incorporation' => $thirdParty->country_of_incorporation,
            'country_of_hq' => $thirdParty->country_of_hq,
            'website' => $thirdParty->website,
            'status' => $thirdParty->status?->value,
            'status_label' => $thirdParty->status?->label(),
            'status_colour' => $thirdParty->status?->color(),
            'category' => $thirdParty->category?->name,
            'relationship_owner' => $thirdParty->relationshipOwner?->name,
            'oversight_owner' => $thirdParty->oversightOwner?->name,
            'ultimate_parent' => $thirdParty->ultimateParent?->legal_name,
            'is_intra_group' => (bool) $thirdParty->is_intra_group,
            'notes' => $thirdParty->notes,
            // Absent, not zero. A vendor nobody has scored has no aggregate
            // residual, and printing 0.0 would read as "no risk".
            'aggregate_residual' => $thirdParty->aggregate_residual,
            'data_confidence' => $thirdParty->data_confidence,
            'last_screened_at' => $thirdParty->last_screened_at?->toDateString(),
            'contacts' => $thirdParty->contacts->map(fn ($c) => [
                'id' => $c->getKey(), 'name' => $c->name, 'role_type' => $c->role_type,
                'email' => $c->email, 'phone' => $c->phone,
            ])->values(),
            'locations' => $thirdParty->locations->map(fn ($l) => [
                'id' => $l->getKey(), 'role' => $l->role, 'city' => $l->city,
                'country' => $l->country, 'is_data_processing_location' => (bool) $l->is_data_processing_location,
            ])->values(),
            'ownership' => $thirdParty->ownership->map(fn ($o) => [
                'id' => $o->getKey(), 'holder_name' => $o->holder_name,
                'relationship' => $o->relationship, 'percentage' => $o->percentage,
                'is_pep' => (bool) $o->is_pep, 'nationality' => $o->nationality,
            ])->values(),
        ];
    }

    /**
     * The lists both forms offer.
     *
     * What the form OFFERS must be a subset of what the validator ACCEPTS
     * (standard §10), so these read the same tenant-scoped sources the Form
     * Request's `Rule::exists` constraints do.
     *
     * @return array<string, mixed>
     */
    private function formOptions(?ThirdParty $editing = null): array
    {
        return [
            'categories' => Category::query()
                ->where('is_active', true)->orderBy('sort_order')
                ->get(['id', 'name'])->values(),
            'users' => User::query()
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->values(),
            'parents' => ThirdParty::query()
                ->when($editing !== null, fn ($q) => $q->whereKeyNot($editing->getKey()))
                ->orderBy('legal_name')
                ->get(['id', 'legal_name'])->values(),
            'entityTypes' => collect(ThirdParty::ENTITY_TYPES)
                ->map(fn (string $type) => ['value' => $type, 'label' => ucwords(str_replace('_', ' ', $type))])
                ->values(),
            'statuses' => collect(ThirdPartyStatus::cases())
                ->map(fn (ThirdPartyStatus $s) => ['value' => $s->value, 'label' => $s->label()])
                ->values(),
        ];
    }

    /** @return array<string, int> */
    private function summary(): array
    {
        $base = fn () => ThirdParty::query();

        return [
            'total' => $base()->count(),
            'active' => $base()->where('status', ThirdPartyStatus::Active->value)->count(),
            'critical' => $base()->whereHas('engagements', fn ($q) => $q->where('effective_tier', 'critical'))->count(),
            'unassigned' => $base()->whereNull('relationship_owner_id')->count(),
        ];
    }
}

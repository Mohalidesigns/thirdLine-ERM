<?php

namespace App\Http\Controllers\Tprm;

use App\Enums\Tprm\RiskTier;
use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tprm\OverrideTierRequest;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\ScoreRun;
use App\Models\Tprm\Waiver;
use App\Presenters\GridPresenter;
use App\Services\Tprm\TierOverrideService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The Engagement Register and the Engagement Workspace (TRD §11).
 *
 * The workspace is "the most important screen" in the TRD's words, and Phase 1
 * builds the part of it that exists yet: the summary, the inherent risk
 * derivation and the score panel. The remaining tabs — due diligence,
 * assessments, contracts, obligations, findings, monitoring, exit — arrive
 * with the phases that own them, and the page shows them as not-yet-available
 * rather than as empty, which are different claims.
 */
class EngagementController extends Controller
{
    public function __construct(private readonly TierOverrideService $overrides) {}

    public function index(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('viewAny', Engagement::class);

        return Inertia::render('Tprm/Engagements/Index', [
            'summary' => fn () => $this->summary(),
            'grid' => fn () => $presenter->present(
                GridRegistry::resolve('tprm_engagements'),
                $request,
                $request->user()
            ),
            'can' => [
                'create' => $request->user()->can('create', Engagement::class),
            ],
        ]);
    }

    public function show(Request $request, Engagement $engagement)
    {
        Gate::authorize('view', $engagement);

        $engagement->load([
            'thirdParty:id,uuid,slug,legal_name,status',
            'businessUnit:id,name',
            'relationshipOwner:id,name',
            'executiveSponsor:id,name',
            'serviceType:id,name',
            'businessFunctions',
        ]);

        $current = InherentAssessment::query()
            ->where('engagement_id', $engagement->getKey())
            ->where('is_current', true)
            ->first();

        $latestRun = ScoreRun::query()
            ->where('engagement_id', $engagement->getKey())
            ->orderByDesc('id')
            ->first();

        return Inertia::render('Tprm/Engagements/Show', [
            'engagement' => $this->payload($engagement),
            // The "Why this score" panel renders from the STORED explanation,
            // never from a fresh computation — that is what makes two users
            // looking at the same score see the same derivation (AC-15).
            'derivation' => $latestRun?->explanation,
            // Phase 5. The panel's own numbers come off the same run, so the
            // headline and the detail cannot disagree: recomputing either on
            // read is exactly how they would.
            'score' => $latestRun === null ? null : [
                'ir' => $latestRun->ir === null ? null : (float) $latestRun->ir,
                'ac' => $latestRun->ac === null ? null : (float) $latestRun->ac,
                'ec' => $latestRun->ec === null ? null : (float) $latestRun->ec,
                'm' => $latestRun->m === null ? null : (float) $latestRun->m,
                'fu' => $latestRun->fu === null ? null : (float) $latestRun->fu,
                'su' => $latestRun->su === null ? null : (float) $latestRun->su,
                'rr' => $latestRun->rr === null ? null : (float) $latestRun->rr,
                'band' => $latestRun->band?->value,
                'band_label' => $latestRun->band?->label(),
                'dc' => $latestRun->dc === null ? null : (float) $latestRun->dc,
                'run_type' => $latestRun->run_type,
                'triggered_by' => $latestRun->triggered_by,
                'computed_at' => $latestRun->created_at?->toDayDateTimeString(),
                'engine_version' => $latestRun->engine_version,
                'ruleset_version' => $latestRun->ruleset_version,
            ],
            'findings' => fn () => $this->scoringFindings($engagement),
            'inherentVersion' => $current === null ? null : [
                'version' => $current->version,
                'ruleset_version' => $current->ruleset_version,
                'assessed_at' => $current->assessed_at?->toDayDateTimeString(),
                'raw_score' => $current->raw_score,
                'resulting_tier' => $current->resulting_tier?->value,
                'knockouts_fired' => $current->knockouts_fired ?? [],
            ],
            'history' => ScoreRun::query()
                ->where('engagement_id', $engagement->getKey())
                ->orderByDesc('id')->limit(10)
                ->get(['id', 'run_type', 'ruleset_version', 'ir', 'rr', 'band', 'created_at'])
                ->map(fn (ScoreRun $run) => [
                    'id' => $run->getKey(),
                    'run_type' => $run->run_type,
                    'ruleset_version' => $run->ruleset_version,
                    'ir' => $run->ir,
                    'rr' => $run->rr,
                    'band' => $run->band?->value,
                    'at' => $run->created_at?->toDayDateTimeString(),
                ])->values(),
            'can' => [
                'edit' => $request->user()->can('update', $engagement),
                'overrideTier' => $request->user()->can('overrideTier', $engagement),
                'approveIntake' => $request->user()->can('approveIntake', $engagement),
            ],
        ]);
    }

    /**
     * Raise a computed tier (FR-TIER-04).
     *
     * A refused override is a flash message, not a 403: the user holds the
     * authority, and what is wrong is the request — asking for a tier below
     * the computed one, which TRD §7.3's `max()` would silently discard. The
     * difference matters to whoever reads the message.
     */
    public function overrideTier(OverrideTierRequest $request, Engagement $engagement)
    {
        $result = $this->overrides->override(
            $engagement,
            RiskTier::from($request->string('tier')->toString()),
            $request->string('rationale')->toString(),
            \Illuminate\Support\Carbon::parse($request->string('expires_at')->toString()),
            $request->user()->id,
            $request->string('approver_role')->toString() ?: null,
        );

        return $result['applied']
            ? back()->with('success', 'The tier override was recorded and appears on the override register.')
            : back()->withInput()->with('error', $result['reason']);
    }

    public function clearOverride(Request $request, Engagement $engagement)
    {
        Gate::authorize('overrideTier', $engagement);

        $this->overrides->clear($engagement, $request->user()->id, 'Withdrawn by '.$request->user()->name);

        return back()->with('success', 'The override was removed and the computed tier restored.');
    }

    /**
     * The override register — FR-TIER-04's "overrides are reported separately
     * to the risk committee".
     *
     * Every exception in the module lands in one table, so this report is the
     * whole picture rather than the tier-shaped slice of it. Expiring-soon
     * first, because that is the column a committee acts on.
     */
    public function overrideRegister(Request $request)
    {
        Gate::authorize('viewAny', Engagement::class);

        $waivers = Waiver::query()
            ->with(['engagement.thirdParty:id,legal_name', 'approver:id,name', 'requester:id,name'])
            ->when(
                ! $request->boolean('include_lapsed'),
                fn ($query) => $query->inForce(),
                fn ($query) => $query->orderByRaw("CASE status WHEN 'approved' THEN 0 ELSE 1 END")
            )
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->paginate(25)
            ->withQueryString();

        $waivers->through(fn (Waiver $waiver) => [
            'id' => $waiver->getKey(),
            'type' => $waiver->waivable_type,
            'type_label' => $waiver->label(),
            'engagement' => $waiver->engagement?->reference,
            'engagement_name' => $waiver->engagement?->name,
            'third_party' => $waiver->engagement?->thirdParty?->legal_name,
            'rationale' => $waiver->rationale,
            'approver' => $waiver->approver?->name,
            'approver_role' => $waiver->approver_role,
            'approved_at' => $waiver->approved_at?->toDateString(),
            'expires_at' => $waiver->expires_at?->toDateString(),
            'days_remaining' => $waiver->expires_at === null
                ? null
                : (int) now()->startOfDay()->diffInDays($waiver->expires_at, false),
            'status' => $waiver->status,
            'in_force' => $waiver->isInForce(),
            'url' => $waiver->engagement ? route('tprm.engagements.show', $waiver->engagement) : null,
        ]);

        return Inertia::render('Tprm/Overrides/Index', [
            'waivers' => $waivers,
            'includeLapsed' => $request->boolean('include_lapsed'),
            'summary' => [
                'in_force' => Waiver::query()->inForce()->count(),
                'expiring_30' => Waiver::query()->expiringWithin(30)->count(),
                'lapsed' => Waiver::query()->where('status', Waiver::STATUS_APPROVED)
                    ->whereNotNull('expires_at')
                    ->whereDate('expires_at', '<', now()->toDateString())->count(),
            ],
        ]);
    }

    /**
     * The findings entering this engagement's residual score.
     *
     * Open ones plus risk-accepted ones, because an accepted finding still
     * contributes at half weight — a list that hid them would leave a reader
     * unable to reconcile the score panel's FU against anything on the page.
     *
     * @return list<array<string, mixed>>
     */
    private function scoringFindings(Engagement $engagement): array
    {
        return \App\Models\Tprm\Finding::query()
            ->where('engagement_id', $engagement->getKey())
            ->scoring()
            ->with('owner:id,name')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('target_date')
            ->get()
            ->map(fn (\App\Models\Tprm\Finding $finding): array => [
                'id' => $finding->getKey(),
                'reference' => $finding->reference,
                'title' => $finding->title,
                'severity' => $finding->severity->value,
                'status' => $finding->status->value,
                'status_label' => $finding->status->label(),
                'target_date' => $finding->target_date?->toDateString(),
                'is_overdue' => $finding->isOverdue(),
                'risk_accepted' => $finding->isRiskAccepted(),
                'owner' => $finding->owner?->name,
                'url' => route('tprm.findings.show', $finding),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function payload(Engagement $engagement): array
    {
        return [
            'id' => $engagement->getKey(),
            'uuid' => $engagement->uuid,
            'reference' => $engagement->reference,
            'name' => $engagement->name,
            'service_description' => $engagement->service_description,
            'type' => $engagement->engagement_type?->value,
            'type_label' => $engagement->engagement_type?->label(),
            'status' => $engagement->status->value,
            'status_label' => $engagement->status->label(),
            'third_party' => [
                'id' => $engagement->thirdParty?->getKey(),
                'legal_name' => $engagement->thirdParty?->legal_name,
                'url' => $engagement->thirdParty ? route('tprm.third-parties.show', $engagement->thirdParty) : null,
            ],
            'business_unit' => $engagement->businessUnit?->name,
            'relationship_owner' => $engagement->relationshipOwner?->name,
            'executive_sponsor' => $engagement->executiveSponsor?->name,
            'service_type' => $engagement->serviceType?->name,
            'inherent_score' => $engagement->inherent_score,
            'inherent_tier' => $engagement->inherent_tier?->value,
            'effective_tier' => $engagement->effective_tier?->value,
            'effective_tier_label' => $engagement->effective_tier?->label(),
            'tier_override' => $engagement->tier_override?->value,
            'tier_override_reason' => $engagement->tier_override_reason,
            'tier_override_expires_at' => $engagement->tier_override_expires_at?->toDateString(),
            // Phase 5 fills these. Null is "not yet computed", which the page
            // renders as such rather than as a score of zero.
            'residual_score' => $engagement->residual_score,
            'residual_band' => $engagement->residual_band?->value,
            'data_confidence' => $engagement->data_confidence,
            'processes_personal_data' => (bool) $engagement->processes_personal_data,
            'cross_border' => (bool) $engagement->cross_border,
            'transfer_basis' => $engagement->transfer_basis,
            'pci_in_scope' => (bool) $engagement->pci_in_scope,
            'supports_critical_function' => (bool) $engagement->supports_critical_function,
            'next_assessment_due' => $engagement->next_assessment_due?->toDateString(),
            'functions' => $engagement->businessFunctions->map(fn ($f) => [
                'id' => $f->getKey(),
                'code' => $f->function_code,
                'name' => $f->name,
                'criticality' => $f->criticality,
                'rto_hours' => $f->rto_hours,
                // Through getAttribute rather than `->pivot->…`: the pivot is
                // attached at runtime by the belongsToMany and is not a declared
                // property, so the direct read is invisible to static analysis.
                'dependency_level' => $f->getRelation('pivot')->getAttribute('dependency_level'),
            ])->values(),
        ];
    }

    /** @return array<string, int> */
    private function summary(): array
    {
        $base = fn () => Engagement::query();

        return [
            'total' => $base()->count(),
            'critical' => $base()->where('effective_tier', 'critical')->count(),
            'untiered' => $base()->whereNull('effective_tier')->count(),
            'overdue' => $base()->whereNotNull('next_assessment_due')
                ->whereDate('next_assessment_due', '<', now()->toDateString())->count(),
        ];
    }
}

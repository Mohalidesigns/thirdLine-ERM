<?php

namespace App\Http\Controllers\Tprm;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\ScoreRun;
use App\Presenters\GridPresenter;
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

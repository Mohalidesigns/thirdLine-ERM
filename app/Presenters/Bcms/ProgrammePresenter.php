<?php

namespace App\Presenters\Bcms;

use App\Models\Bcms\ClauseRef;
use App\Models\Bcms\ManagementReview;
use App\Models\Bcms\Objective;
use App\Models\Bcms\Programme;
use App\Models\Bcms\ProgrammeObligation;
use App\Models\Bcms\ProgrammeScopeItem;
use App\Models\User;
use App\Services\Bcms\MaturityService;
use App\Services\Bcms\PolicyService;
use App\Services\Bcms\RaciService;

/**
 * The programme overview screen (Blueprint §15, screen 1).
 *
 * A PRESENTER, NOT A CONTROLLER METHOD (development standard §1). Every figure
 * here is one that will eventually be printed in a board pack, and logic in a
 * controller is logic only an HTTP test can reach.
 *
 * NOTHING HERE COMPUTES A MATURITY SCORE. It reads the latest stored assessment.
 * Orchestration §5 makes the scoring engine single-owner and Phase 11 builds the
 * heatmap over the same rows; a presenter that recalculated would be the second
 * scorer that rule exists to prevent.
 *
 * `objective_progress` IS NULL WHERE IT CANNOT BE COMPUTED. An objective with a
 * target and no baseline has no progress — a percentage of an unknown starting
 * point is a made-up number, and the screen says so rather than showing zero.
 */
class ProgrammePresenter
{
    public function __construct(
        private MaturityService $maturity,
        private PolicyService $policy,
        private RaciService $raci,
    ) {}

    /** @return array<string, mixed> */
    public function present(?Programme $programme, ?User $user): array
    {
        $latestMaturity = $this->maturity->latest();
        $policy = $this->policy->current();

        return [
            'programme' => $programme === null ? null : $this->programme($programme),
            'objectives' => $programme === null ? [] : $this->objectives($programme),
            'scope' => $programme === null ? [] : $this->scope($programme),
            'obligations' => $programme === null ? [] : $this->obligations($programme),
            'policy' => $policy === null ? null : $this->policy($policy),
            'maturity' => $latestMaturity === null ? null : $this->maturity($latestMaturity),
            'reviews' => $programme === null ? [] : $this->reviews($programme),
            'raci_gaps' => $this->raci->processGaps(),
            'can' => [
                'manage' => $user?->can('bcms.programme.manage') === true,
                'approve' => $user?->can('bcms.programme.approve') === true,
                'manage_policy' => $user?->can('bcms.plan.manage') === true,
                'approve_policy' => $user?->can('bcms.plan.approve') === true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function programme(Programme $programme): array
    {
        return [
            'id' => $programme->getKey(),
            'uuid' => $programme->uuid,
            'name' => $programme->name,
            'year' => $programme->year,
            'status' => $programme->status,
            'scope_statement' => $programme->scope_statement,
            'out_of_scope_statement' => $programme->out_of_scope_statement,
            'interested_parties' => $programme->interested_parties ?? [],
            'owner' => $programme->owner?->name,
            'approved_by' => $programme->approver?->name,
            'approved_at' => $programme->approved_at?->toDateString(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function objectives(Programme $programme): array
    {
        return Objective::query()
            ->where('programme_id', $programme->getKey())
            ->with('keyRiskIndicator:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (Objective $o) => [
                'id' => $o->getKey(),
                'title' => $o->title,
                'measure' => $o->measure_description,
                'baseline' => $o->baseline_value,
                'target' => $o->target_value,
                'unit' => $o->target_unit,
                'target_date' => $o->target_date?->toDateString(),
                'status' => $o->status,
                'kri' => $o->keyRiskIndicator?->name,
                // Null, not zero. Progress against an unknown baseline is a
                // made-up number, and the screen prints "no baseline" instead.
                'measurable' => $o->target_value !== null,
                'baselined' => $o->baseline_value !== null,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function scope(Programme $programme): array
    {
        return ProgrammeScopeItem::query()
            ->where('programme_id', $programme->getKey())
            ->with('scopable')
            ->get()
            ->map(function (ProgrammeScopeItem $item): array {
                $target = $item->scopable;

                return [
                    'id' => $item->getKey(),
                    'type' => $item->scopable_type,
                    // Through `getAttribute`, because the morph target is a
                    // business unit or a process and neither is known here.
                    // Null is a live state: a unit deleted after being scoped
                    // in leaves a row pointing at nothing, and the screen has
                    // to say so rather than render blank.
                    'label' => $target === null
                        ? 'No longer in the register'
                        : (string) ($target->getAttribute('name') ?? 'Unnamed'),
                    'in_scope' => (bool) $item->in_scope,
                    'rationale' => $item->rationale,
                ];
            })
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function obligations(Programme $programme): array
    {
        $library = ClauseRef::query()->pluck('title', 'code');
        $standards = ClauseRef::query()->pluck('standard', 'code');

        return ProgrammeObligation::query()
            ->where('programme_id', $programme->getKey())
            ->with('owner:id,name')
            ->get()
            ->map(fn (ProgrammeObligation $o) => [
                'id' => $o->getKey(),
                'clause_ref' => $o->clause_ref,
                'title' => $library[$o->clause_ref] ?? $o->clause_ref,
                'standard' => $standards[$o->clause_ref] ?? null,
                'applies' => (bool) $o->applies,
                'applicability_note' => $o->applicability_note,
                'owner' => $o->owner?->name,
                'cadence' => $o->cadence,
                'cadence_per_year' => $o->cadence_per_year,
                'how_satisfied' => $o->how_satisfied,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function policy(\App\Models\Bcms\Plan $policy): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Bcms\PlanAttestation> $attestations */
        $attestations = $policy->attestations()->with('attestor:id,name')->orderByDesc('attested_at')->get();

        return [
            'id' => $policy->getKey(),
            'uuid' => $policy->uuid,
            'title' => $policy->title,
            'version' => $policy->version,
            'status' => $policy->status,
            'effective_from' => $policy->effective_from?->toDateString(),
            'next_review_date' => $policy->next_review_date?->toDateString(),
            'approved_at' => $policy->approved_at?->toDateString(),
            'attested_this_year' => $attestations->contains(fn ($a) => (int) $a->period_year === (int) now()->year),
            'attestations' => $attestations->map(fn (\App\Models\Bcms\PlanAttestation $a): array => [
                'year' => $a->period_year,
                'type' => $a->attestation_type,
                'by' => $a->attested_by_name,
                'role' => $a->attested_by_role,
                'at' => $a->attested_at?->toDateTimeString(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function maturity(\App\Models\Bcms\MaturityAssessment $assessment): array
    {
        return [
            'assessed_at' => $assessment->assessed_at?->toDateTimeString(),
            'overall_score' => $assessment->overall_score,
            'method_version' => $assessment->method_version,
            'scores' => $assessment->scores->map(fn (\App\Models\Bcms\MaturityScore $s): array => [
                'clause_group' => $s->clause_group->value,
                'label' => $s->clause_group->label(),
                'score' => $s->score,
                'evidence_count' => $s->evidence_count,
                'expected_count' => $s->expected_count,
                'rationale' => $s->rationale,
            ])->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function reviews(Programme $programme): array
    {
        return ManagementReview::query()
            ->where('programme_id', $programme->getKey())
            ->with('chair:id,name')
            ->orderByDesc('held_on')
            ->limit(10)
            ->get()
            ->map(fn (ManagementReview $r) => [
                'id' => $r->getKey(),
                'uuid' => $r->uuid,
                'reference' => $r->reference,
                'title' => $r->title,
                'held_on' => $r->held_on?->toDateString(),
                'status' => $r->status,
                'chair' => $r->chair?->name,
                'inputs_captured' => $r->inputs_captured_at !== null,
            ])
            ->all();
    }
}

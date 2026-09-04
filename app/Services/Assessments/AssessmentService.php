<?php

namespace App\Services\Assessments;

use App\Models\KeyRiskIndicator;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskAssessmentControl;
use App\Models\RiskCause;
use App\Models\RiskCauseCategory;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\AssessmentChainService;
use App\Services\ReferenceCodeService;
use App\Services\RiskScoringService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The risk assessment journey (migration Phase 3.3).
 *
 * Lifted out of RiskAssessmentController, which had grown to 807 lines around
 * a chain that writes to five tables in one transaction:
 *
 *   Risk -> Root Cause -> Likelihood -> Impact -> Inherent Risk ->
 *   Existing Controls -> Control Effectiveness -> Residual Risk ->
 *   Risk Treatment -> Action Plan -> Owner -> Due Date -> KRI
 *
 * Each step writes to the object that owns it: causes to `risk_causes`,
 * control ratings to `risk_assessment_controls`, actions to `treatment_plans`,
 * monitoring to `key_risk_indicators`. The assessment is the journey through
 * them, not a duplicate store of their data. The arithmetic itself stays in
 * AssessmentChainService and RiskScoringService — this composes them.
 */
class AssessmentService
{
    /**
     * Labels and hints for the impact dimensions, which lived as a literal in
     * the Blade template. A dimension a tenant's profile does not score is
     * never asked for, so an entry here that goes unused costs nothing.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const DIMENSION_META = [
        'financial' => ['Financial', 'Direct monetary loss or cost'],
        'operational' => ['Operational', 'Process disruption or service degradation'],
        'reputational' => ['Reputational', 'Brand damage, media coverage, customer trust'],
        'regulatory' => ['Regulatory', 'CBN sanctions, penalties, license risk'],
        'strategic' => ['Strategic', 'Effect on strategic objectives'],
    ];

    /**
     * The chain as a stepper: five stages covering the thirteen steps.
     *
     * @var list<array{label: string, steps: string, icon: string}>
     */
    public const STAGES = [
        ['label' => 'Context & Cause', 'steps' => '1-2', 'icon' => 'account_tree'],
        ['label' => 'Inherent Risk', 'steps' => '3-5', 'icon' => 'trending_up'],
        ['label' => 'Controls', 'steps' => '6-7', 'icon' => 'shield'],
        ['label' => 'Residual Risk', 'steps' => '8', 'icon' => 'shield_moon'],
        ['label' => 'Treatment & Monitoring', 'steps' => '9-13', 'icon' => 'task_alt'],
    ];

    public function __construct(
        private RiskScoringService $scoring,
        private AssessmentChainService $chain,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Read side */
    /* ------------------------------------------------------------------ */

    /**
     * The risks the picker offers.
     *
     * Step 1 — the risk — is chosen before the rest of the chain can be drawn:
     * which controls to rate and which causes to review are properties of the
     * risk, so without one there is no form to render.
     *
     * @return list<array<string, mixed>>
     */
    public function selectableRisks(): array
    {
        return Risk::where('organization_id', TenantContext::organizationId())
            ->where('status', 'active')
            ->visibleTo()
            ->with('category')
            ->orderBy('risk_code')
            ->get()
            ->map(fn (Risk $risk) => [
                'id' => $risk->id,
                'risk_code' => $risk->risk_code,
                'title' => $risk->title,
                'category' => $risk->category?->name,
                'inherent_rating' => $risk->inherent_rating,
                'residual_rating' => $risk->residual_rating,
            ])
            ->values()
            ->all();
    }

    /**
     * Everything the thirteen-step form needs, for a new assessment or a draft
     * being edited. One method, so the create and edit forms cannot drift.
     *
     * @return array<string, mixed>
     */
    public function formData(Risk $risk, ?RiskAssessment $assessment = null): array
    {
        $orgId = $risk->organization_id;
        $profile = $this->scoring->profileForRisk($risk);

        $named = fn ($query) => $query->where('organization_id', $orgId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])
            ->values()
            ->all();

        return [
            'risk' => [
                'id' => $risk->id,
                'risk_code' => $risk->risk_code,
                'title' => $risk->title,
                'description' => $risk->description,
                'category' => $risk->category?->name,
                'inherent_rating' => $risk->inherent_rating,
            ],
            'assessment' => $assessment ? $this->presentForForm($assessment) : null,

            // Step 2
            'causes' => $risk->causes()->with('category')->get()
                ->map(fn (RiskCause $cause) => [
                    'id' => $cause->id,
                    'description' => $cause->description,
                    'cause_category_id' => $cause->cause_category_id,
                    'source' => $cause->source,
                    'is_primary' => (bool) $cause->is_primary,
                ])->values()->all(),
            'causeCategories' => RiskCauseCategory::options()
                ->map(fn ($label, $id) => ['id' => $id, 'name' => $label])->values()->all(),
            'causeSources' => collect(RiskCause::SOURCES)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),

            // Steps 3-5: the axes and dimensions come from the organization's
            // scoring profile, not from a hardcoded 5x5 — which is also how
            // `impact_people` stops being offered on a form that has nowhere
            // to store it.
            'likelihoodLabels' => $profile->axisLabels('likelihood'),
            'impactLabels' => $profile->axisLabels('impact'),
            'dimensions' => collect($profile->dimensions())
                ->map(fn (string $code) => [
                    'code' => $code,
                    'label' => self::DIMENSION_META[$code][0] ?? ucfirst($code),
                    'hint' => self::DIMENSION_META[$code][1] ?? null,
                ])->values()->all(),

            // Steps 6-8
            'controls' => $this->chain->controlsFor($risk, $assessment)->all(),
            'effectivenessRatings' => collect(RiskAssessmentControl::RATINGS)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),

            // Step 9
            'strategies' => collect(RiskAssessment::TREATMENT_STRATEGIES)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),

            // Steps 10-12
            'actionPlans' => $assessment
                ? TreatmentPlan::where('risk_id', $risk->id)->orderByDesc('id')->get()
                    ->map(fn (TreatmentPlan $plan) => [
                        'id' => $plan->getKey(),
                        'action_title' => data_get($plan, 'action_title'),
                        'action_description' => data_get($plan, 'action_description'),
                        'owner_id' => data_get($plan, 'owner_id'),
                        'target_date' => optional(data_get($plan, 'target_date'))->toDateString(),
                        'priority' => data_get($plan, 'priority'),
                    ])->values()->all()
                : [],
            'users' => $named(User::query()),

            // Step 13
            'linkedKris' => $this->kriOptions($orgId, fn ($query) => $query->where('risk_id', $risk->id)),
            'availableKris' => $this->kriOptions($orgId, fn ($query) => $query->whereNull('risk_id')),

            'previousAssessment' => $this->previousApproved($risk, $assessment),
            'stages' => self::STAGES,
        ];
    }

    /**
     * Everything the detail page draws.
     *
     * @return array<string, mixed>
     */
    public function detail(RiskAssessment $assessment): array
    {
        $orgId = $assessment->organization_id;

        $assessment->load([
            'risk.category', 'risk.riskOwner', 'assessor',
            'risk.causes.category',
            'assessedControls.control',
        ]);

        $history = $this->history($assessment);
        $previous = $this->previousFor($assessment);

        return [
            'assessment' => $this->present($assessment, $history),
            'risk' => [
                'id' => $assessment->risk?->id,
                'risk_code' => $assessment->risk?->risk_code,
                'title' => $assessment->risk?->title,
                'category' => $assessment->risk?->category?->name,
                'owner' => $assessment->risk?->riskOwner?->name,
            ],
            'causes' => $assessment->risk?->causes
                ->map(fn (RiskCause $cause) => [
                    'id' => $cause->id,
                    'description' => $cause->description,
                    'category' => $cause->category?->name,
                    'source' => $cause->source,
                    'is_primary' => (bool) $cause->is_primary,
                ])->values()->all() ?? [],
            'ratedControls' => $assessment->assessedControls
                ->map(fn (RiskAssessmentControl $rated) => [
                    'id' => $rated->getKey(),
                    'control_code' => $rated->control_code,
                    'control_name' => $rated->control_name,
                    'design_effectiveness' => $rated->design_effectiveness,
                    'operating_effectiveness' => $rated->operating_effectiveness,
                    'effectiveness_pct' => $rated->effectiveness_pct === null ? null : (float) $rated->effectiveness_pct,
                    'is_key_control' => (bool) $rated->is_key_control,
                    'control_weight' => (float) $rated->control_weight,
                    'finding' => $rated->finding,
                    'notes' => $rated->notes,
                    'evidence_ref' => $rated->evidence_ref,
                ])->values()->all(),
            'effectiveness' => $this->chain->effectiveness($assessment->assessedControls),
            'actionPlans' => TreatmentPlan::where('risk_id', $assessment->risk_id)
                ->with(['owner'])
                ->orderBy('target_date')
                ->get()
                ->map(fn (TreatmentPlan $plan) => [
                    'id' => $plan->getKey(),
                    'treatment_code' => data_get($plan, 'treatment_code'),
                    'action_title' => data_get($plan, 'action_title'),
                    'owner' => data_get($plan, 'owner.name'),
                    'target_date' => optional(data_get($plan, 'target_date'))->toDateString(),
                    'priority' => data_get($plan, 'priority'),
                    'status' => data_get($plan, 'status'),
                    'progress_pct' => data_get($plan, 'progress_pct'),
                ])->values()->all(),
            'kris' => $this->kriOptions($orgId, fn ($query) => $query->where('risk_id', $assessment->risk_id)),
            'previousAssessment' => $previous ? $this->present($previous, $history) : null,
            'assessmentHistory' => $history->sortByDesc('assessment_date')->values()
                ->map(fn (RiskAssessment $item): array => [
                    'id' => $item->getKey(),
                    'assessment_date' => optional($item->assessment_date)->toDateString(),
                    'assessment_type' => $item->assessment_type,
                    'overall_score' => $item->overall_score,
                    'overall_rating' => $item->overall_rating,
                    'residual_score' => $item->residual_score,
                    'residual_rating' => $item->residual_rating,
                    'status' => $item->status,
                    'assessor' => $item->assessor?->name,
                    'score_change' => (int) $item->getAttribute('score_change'),
                ])->values()->all(),
            'dimensionData' => $this->dimensionSeries($assessment, $previous),
            'comparisonData' => $this->comparisonSeries($assessment, $previous),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Write side */
    /* ------------------------------------------------------------------ */

    /**
     * Write the chain. Shared by store() and update() so a draft and a new
     * assessment go through identical logic.
     *
     * @param  array<string, mixed>  $validated
     */
    public function persistChain(RiskAssessment $assessment, array $validated, Risk $risk, ?int $userId): RiskAssessment
    {
        $profile = $this->scoring->profileForRisk($risk);

        // Steps 4-5 — Impact and Inherent Risk. Both computed: an inherent
        // score the assessor can type is an inherent score that stops agreeing
        // with the likelihood and impact printed beside it.
        $impacts = collect($profile->dimensions())
            ->mapWithKeys(fn (string $d) => [$d => $validated["impact_{$d}"] ?? null])
            ->all();

        $impactScore = $this->scoring->calculateImpact($impacts, $risk->organization_id);
        $inherentScore = $this->scoring->calculateScore((int) $validated['likelihood'], $impactScore, $profile);

        $notes = trim(
            ($validated['rationale'] ?? '')
            .(! empty($validated['recommendations']) ? "\n\nRecommendations:\n".$validated['recommendations'] : '')
        );

        $assessment->fill([
            'organization_id' => $risk->organization_id,
            'assessment_type' => $validated['assessment_type'],
            'assessment_date' => $validated['assessment_date'],
            'likelihood_score' => (int) $validated['likelihood'],
            'likelihood_rationale' => $validated['likelihood_rationale'] ?? null,
            'impact_financial' => $impacts['financial'] ?? null,
            'impact_operational' => $impacts['operational'] ?? null,
            'impact_reputational' => $impacts['reputational'] ?? null,
            'impact_regulatory' => $impacts['regulatory'] ?? null,
            'impact_strategic' => $impacts['strategic'] ?? null,
            'impact_score' => $impactScore,
            'overall_score' => $inherentScore,
            'overall_rating' => $this->scoring->calculateRating($inherentScore, $profile),
            'treatment_strategy' => $validated['treatment_strategy'] ?? null,
            'assessment_notes' => $notes ?: null,
        ]);

        $assessment->save();

        // Step 2 — Root Cause, on the risk, snapshotted onto the assessment.
        $causes = $this->syncCauses($risk, $validated['causes'] ?? [], $userId);
        $assessment->cause_snapshot = $causes->map->toSnapshot()->values()->all();

        // Steps 6-8 — Controls, effectiveness, and the residual that follows.
        $this->chain->syncControls($assessment, $validated['controls'] ?? []);
        $this->chain->applyToAssessment($assessment, [
            'likelihood' => $validated['residual_likelihood'] ?? null,
            'impact' => $validated['residual_impact'] ?? null,
            'justification' => $validated['residual_justification'] ?? null,
        ]);

        // An override without a reason is not a judgement, it is an unexplained
        // number. Checked after the service has decided whether the posted
        // values actually differ from the derivation, which is why it cannot
        // live in the Form Request with the rest of the rules.
        if ($assessment->residualWasOverridden() && blank($assessment->residual_justification)) {
            throw ValidationException::withMessages([
                'residual_justification' => 'Overriding the derived residual score requires a justification.',
            ]);
        }

        // Steps 10-12 — Action Plan, Owner, Due Date.
        $this->syncActionPlans($risk, $assessment, $validated['actions'] ?? [], $userId);

        // Step 13 — KRI.
        $this->syncKris($risk, $validated['kri_ids'] ?? []);

        if (($validated['action'] ?? null) === 'submit' && $assessment->status === 'draft') {
            $assessment->status = 'in_review';
        }

        $assessment->save();

        return $assessment;
    }

    /**
     * Step 2 — persist the risk's causes and return the set this assessment
     * considered.
     *
     * Causes are not deleted here. A cause that an assessor stops listing this
     * quarter has not stopped being true, and silently dropping it would lose
     * the history that makes cross-register cause analysis worth having.
     * Retiring a cause is a deliberate action on the risk.
     *
     * @param  array<int, array<string, mixed>>  $input
     * @return Collection<int, RiskCause>
     */
    private function syncCauses(Risk $risk, array $input, ?int $userId): Collection
    {
        $existing = $risk->causes()->get()->keyBy('id');
        $considered = collect();
        $order = 0;

        foreach ($input as $row) {
            $description = trim((string) ($row['description'] ?? ''));

            if ($description === '') {
                continue;
            }

            // Every optional key is read defensively. The rules validate each
            // one as `nullable`, so validated() simply omits an absent field —
            // and the Blade form always posted all of them, which is why
            // `$row['cause_category_id']` never raised. A JSON client sending
            // only a description is a legitimate caller, not a 500.
            $attributes = [
                'description' => $description,
                'cause_category_id' => ($row['cause_category_id'] ?? null) ?: null,
                'source' => ($row['source'] ?? null) ?: null,
                'is_primary' => (bool) ($row['is_primary'] ?? false),
                'sort_order' => $order++,
            ];

            // An id is only honoured if it is a cause of THIS risk, so a
            // forged id cannot edit another risk's cause register.
            $cause = $existing->get((int) ($row['id'] ?? 0));

            if ($cause !== null) {
                $cause->update($attributes);
            } else {
                $cause = $risk->causes()->create($attributes + [
                    'organization_id' => $risk->organization_id,
                    'created_by' => $userId,
                ]);
            }

            $considered->push($cause->load('category'));
        }

        // Only one cause can be the primary one.
        if ($considered->where('is_primary', true)->count() > 1) {
            $keep = $considered->firstWhere('is_primary', true);
            $risk->causes()->where('id', '!=', $keep->id)->update(['is_primary' => false]);
            $considered->where('id', '!=', $keep->id)->each->setAttribute('is_primary', false);
        }

        return $considered;
    }

    /**
     * Steps 10-12 — the action plans this assessment raised, each with the
     * owner and due date the chain requires.
     *
     * These are real `treatment_plans` rows, not a copy held on the assessment:
     * the action register, the owner's My Responsibilities queue and the
     * overdue-actions widget all read that table, and an action that only
     * exists inside an assessment reaches none of them.
     *
     * @param  array<int, array<string, mixed>>  $input
     */
    private function syncActionPlans(Risk $risk, RiskAssessment $assessment, array $input, ?int $userId): void
    {
        foreach ($input as $row) {
            $title = trim((string) ($row['action_title'] ?? ''));

            if ($title === '' || ! empty($row['id'])) {
                continue;
            }

            TreatmentPlan::create([
                'organization_id' => $risk->organization_id,
                'risk_id' => $risk->id,
                'treatment_code' => ReferenceCodeService::generate('treatment_plans', 'treatment_code', 'TP'),
                'strategy' => $assessment->treatment_strategy,
                'action_title' => $title,
                'action_description' => $row['action_description'] ?? null,
                'owner_id' => (int) $row['owner_id'],
                'target_date' => $row['target_date'],
                'priority' => $row['priority'] ?? 'medium',
                'status' => 'not_started',
                'progress_pct' => 0,
                // The residual this action is expected to achieve — the third
                // value on a treatment chart, alongside inherent and current
                // residual.
                'expected_residual_likelihood' => $assessment->residual_likelihood,
                'expected_residual_impact' => $assessment->residual_impact,
                'created_by' => $userId,
            ]);
        }
    }

    /**
     * Step 13 — attach the KRIs that will monitor this risk going forward.
     *
     * Only unattached KRIs can be claimed: `key_risk_indicators.risk_id` is the
     * canonical link, and reassigning an indicator another risk is already
     * monitoring would silently blank that risk's monitoring.
     *
     * @param  array<int, int>  $kriIds
     */
    private function syncKris(Risk $risk, array $kriIds): void
    {
        if ($kriIds === []) {
            return;
        }

        KeyRiskIndicator::where('organization_id', $risk->organization_id)
            ->whereIn('id', $kriIds)
            ->where(fn ($query) => $query->whereNull('risk_id')->orWhere('risk_id', $risk->id))
            ->update(['risk_id' => $risk->id]);
    }

    /* ------------------------------------------------------------------ */
    /*  Presentation helpers */
    /* ------------------------------------------------------------------ */

    /**
     * The full assessment history for a risk, each row carrying its score
     * change against the chronologically preceding assessment.
     *
     * @return Collection<int, RiskAssessment>
     */
    private function history(RiskAssessment $assessment): Collection
    {
        $history = RiskAssessment::where('risk_id', $assessment->risk_id)
            ->where('organization_id', $assessment->organization_id)
            ->with('assessor')
            ->orderBy('assessment_date')
            ->orderBy('id')
            ->get();

        $previousScore = null;

        foreach ($history as $item) {
            $item->setAttribute(
                'score_change',
                $previousScore === null ? 0 : ((int) $item->overall_score - $previousScore)
            );
            $previousScore = (int) $item->overall_score;
        }

        return $history;
    }

    private function previousFor(RiskAssessment $assessment): ?RiskAssessment
    {
        return RiskAssessment::where('risk_id', $assessment->risk_id)
            ->where('organization_id', $assessment->organization_id)
            ->where('assessment_date', '<', $assessment->assessment_date)
            ->where('status', 'approved')
            ->orderByDesc('assessment_date')
            ->first();
    }

    /** @return array<string, mixed>|null */
    private function previousApproved(Risk $risk, ?RiskAssessment $assessment): ?array
    {
        $previous = RiskAssessment::where('risk_id', $risk->id)
            ->where('status', 'approved')
            ->when($assessment?->exists, fn ($query) => $query->where('id', '!=', $assessment->id))
            ->orderByDesc('assessment_date')
            ->first();

        return $previous === null ? null : [
            'id' => $previous->getKey(),
            'assessment_date' => optional($previous->assessment_date)->toDateString(),
            'overall_score' => $previous->overall_score,
            'overall_rating' => $previous->overall_rating,
            'residual_score' => $previous->residual_score,
            'residual_rating' => $previous->residual_rating,
        ];
    }

    /**
     * @param  Collection<int, RiskAssessment>  $history
     * @return array<string, mixed>
     */
    private function present(RiskAssessment $assessment, Collection $history): array
    {
        return [
            'id' => $assessment->getKey(),
            'reference' => 'ASS-'.str_pad((string) $assessment->getKey(), 4, '0', STR_PAD_LEFT),
            'assessment_type' => $assessment->assessment_type,
            'assessment_date' => optional($assessment->assessment_date)->toDateString(),
            'status' => $assessment->status,
            'assessor' => $assessment->assessor?->name,
            'reviewer_id' => $assessment->reviewer_id,
            'assessor_id' => $assessment->assessor_id,
            'likelihood_score' => $assessment->likelihood_score,
            'likelihood_rationale' => $assessment->likelihood_rationale,
            'impact_score' => $assessment->impact_score,
            'overall_score' => $assessment->overall_score,
            'overall_rating' => $assessment->overall_rating,
            'residual_likelihood' => $assessment->residual_likelihood,
            'residual_impact' => $assessment->residual_impact,
            'residual_score' => $assessment->residual_score,
            'residual_rating' => $assessment->residual_rating,
            'residual_source' => $assessment->residual_source,
            'residual_justification' => $assessment->residual_justification,
            'treatment_strategy' => $assessment->treatment_strategy,
            'assessment_notes' => $assessment->assessment_notes,
            'review_comments' => $assessment->review_comments,
            'score_change' => (int) ($history->firstWhere('id', $assessment->getKey())?->getAttribute('score_change') ?? 0),
        ];
    }

    /** The chain as the edit form holds it. @return array<string, mixed> */
    private function presentForForm(RiskAssessment $assessment): array
    {
        return [
            'id' => $assessment->getKey(),
            'assessment_type' => $assessment->assessment_type,
            'assessment_date' => optional($assessment->assessment_date)->toDateString(),
            'status' => $assessment->status,
            'likelihood' => $assessment->likelihood_score,
            'likelihood_rationale' => $assessment->likelihood_rationale,
            'impacts' => [
                'financial' => $assessment->impact_financial,
                'operational' => $assessment->impact_operational,
                'reputational' => $assessment->impact_reputational,
                'regulatory' => $assessment->impact_regulatory,
                'strategic' => $assessment->impact_strategic,
            ],
            'residual_likelihood' => $assessment->residual_likelihood,
            'residual_impact' => $assessment->residual_impact,
            'residual_justification' => $assessment->residual_justification,
            'residual_source' => $assessment->residual_source,
            'treatment_strategy' => $assessment->treatment_strategy,
            'assessment_notes' => $assessment->assessment_notes,
        ];
    }

    /**
     * Radar chart: impact dimension scores, current vs previous.
     *
     * @return array<string, mixed>
     */
    private function dimensionSeries(RiskAssessment $assessment, ?RiskAssessment $previous): array
    {
        $read = fn (RiskAssessment $row) => [
            (int) $row->impact_financial,
            (int) $row->impact_operational,
            (int) $row->impact_reputational,
            (int) $row->impact_regulatory,
            (int) ($row->impact_strategic ?? 0),
        ];

        return [
            'labels' => ['Financial', 'Operational', 'Reputational', 'Regulatory', 'Strategic'],
            'current' => $read($assessment),
            'previous' => $previous ? $read($previous) : [0, 0, 0, 0, 0],
        ];
    }

    /**
     * Bar chart: side-by-side comparison with the previous assessment.
     *
     * @return array<string, mixed>
     */
    private function comparisonSeries(RiskAssessment $assessment, ?RiskAssessment $previous): array
    {
        $read = fn (RiskAssessment $row) => [
            (int) $row->likelihood_score,
            (int) $row->impact_financial,
            (int) $row->impact_operational,
            (int) $row->impact_reputational,
            (int) $row->impact_regulatory,
            (int) ($row->impact_strategic ?? 0),
        ];

        return [
            'labels' => ['Likelihood', 'Financial', 'Operational', 'Reputational', 'Regulatory', 'Strategic'],
            'current' => $read($assessment),
            'previous' => $previous ? $read($previous) : [0, 0, 0, 0, 0, 0],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function kriOptions(int $orgId, callable $filter): array
    {
        $query = KeyRiskIndicator::where('organization_id', $orgId);
        $filter($query);

        return $query->orderBy('kri_code')->get()
            ->map(fn (KeyRiskIndicator $kri) => [
                'id' => $kri->getKey(),
                'kri_code' => data_get($kri, 'kri_code'),
                'kri_name' => data_get($kri, 'kri_name') ?? data_get($kri, 'name'),
                'current_value' => data_get($kri, 'current_value'),
                'status' => data_get($kri, 'status'),
            ])->values()->all();
    }
}

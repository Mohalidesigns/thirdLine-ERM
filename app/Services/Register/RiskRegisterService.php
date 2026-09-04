<?php

namespace App\Services\Register;

use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Control;
use App\Models\KeyRiskIndicator;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskAuditTrail;
use App\Models\RiskCategory;
use App\Models\RiskControlMapping;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\ReferenceCodeService;
use App\Services\RiskScoringService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The risk register (migration Phase 3.2).
 *
 * Lifted out of RiskRegisterController: the write methods each touched two
 * models — the risk and its append-only audit trail — inside a transaction,
 * and the read methods each derived a figure the Blade view computed in an
 * `@php` block, where no test could reach it. Both are the ThirdLine §4.2
 * line for a service.
 *
 * The scoring on the way in is deliberately UNCHANGED from the controller it
 * came from and is pinned by tests/Feature/Characterisation/RiskRegisterScoringTest:
 * create() collapses the impact dimensions through RiskScoringService
 * (profile-aware), update() still takes the plain maximum, and both take the
 * rating from the service's bands. Unifying the two is a scoring decision,
 * not a porting one.
 */
class RiskRegisterService
{
    /** The impact dimensions the bespoke form scores, in the order it shows them. */
    public const IMPACT_DIMENSIONS = [
        'impact_financial' => ['label' => 'Financial Impact', 'hint' => 'Direct financial loss (NGN)'],
        'impact_operational' => ['label' => 'Operational Impact', 'hint' => 'Business continuity disruption'],
        'impact_reputational' => ['label' => 'Reputational Impact', 'hint' => 'Brand & customer trust'],
        'impact_regulatory' => ['label' => 'Regulatory Impact', 'hint' => 'CBN penalties & sanctions'],
    ];

    public function __construct(private RiskScoringService $scoring) {}

    /* ------------------------------------------------------------------ */
    /*  Read side */
    /* ------------------------------------------------------------------ */

    /**
     * Everything the detail page draws, with each tab's rows already shaped.
     *
     * @return array<string, mixed>
     */
    public function detail(Risk $risk): array
    {
        $risk->load([
            'category',
            'riskOwner',
            'businessUnit',
            'entity',
            'assessments' => fn ($q) => $q->orderByDesc('assessment_date')->limit(10),
            'assessments.assessor',
            'controlMappings',
            'treatmentPlans' => fn ($q) => $q->orderByDesc('created_at'),
            'keyRiskIndicators' => fn ($q) => $q->orderBy('kri_name'),
            'auditTrails' => fn ($q) => $q->orderByDesc('changed_at')->limit(20),
        ]);

        return [
            'risk' => $this->present($risk),
            'metrics' => $this->metrics($risk),
            // Columns on models Phases 3.3/3.4/3.5 own are read with data_get()
            // rather than by adding @property docblocks to those models here —
            // the same line Phase 3.1 drew. The typing of RiskAssessment,
            // TreatmentPlan, KeyRiskIndicator and Control belongs to the phase
            // that ports them.
            'assessments' => $risk->assessments->map(fn (RiskAssessment $assessment) => [
                'id' => $assessment->getKey(),
                'assessment_date' => optional(data_get($assessment, 'assessment_date'))->toDateString(),
                'inherent_score' => data_get($assessment, 'overall_score'),
                'inherent_rating' => data_get($assessment, 'overall_rating'),
                'residual_score' => data_get($assessment, 'residual_score'),
                'residual_rating' => data_get($assessment, 'residual_rating'),
                'assessor' => data_get($assessment, 'assessor.name'),
                'notes' => data_get($assessment, 'assessment_notes'),
            ])->values()->all(),
            'controls' => $risk->controlMappings->map(fn (Control $control) => [
                'id' => $control->id,
                'control_code' => $control->control_code,
                'name' => $control->name,
                'control_type' => $control->control_type,
                'effectiveness_rating' => $control->effectiveness_rating,
                // Null, not 0: an unrated control has no effectiveness, and a
                // zero-width red bar reads as "measured, and terrible".
                'effectiveness_percent' => $control->effectiveness_rating ? $control->effectiveness_percent : null,
                'effectiveness_label' => $control->effectiveness_label,
                'is_key_control' => (bool) data_get($control, 'pivot.is_key_control'),
                'status' => $control->status ?? 'active',
            ])->values()->all(),
            'treatmentPlans' => $risk->treatmentPlans->map(fn (TreatmentPlan $plan) => [
                'id' => $plan->getKey(),
                'plan_code' => data_get($plan, 'plan_code'),
                'strategy' => data_get($plan, 'strategy') ?? $risk->treatment_strategy,
                'status' => data_get($plan, 'status') ?? 'active',
                'description' => data_get($plan, 'description'),
            ])->values()->all(),
            'kris' => $risk->keyRiskIndicators->map(fn (KeyRiskIndicator $kri) => [
                'id' => $kri->getKey(),
                'kri_code' => data_get($kri, 'kri_code'),
                'kri_name' => data_get($kri, 'kri_name') ?? data_get($kri, 'name'),
                'current_value' => data_get($kri, 'current_value'),
                'amber_threshold' => data_get($kri, 'amber_threshold'),
                'red_threshold' => data_get($kri, 'red_threshold'),
                'status' => data_get($kri, 'status') ?? 'active',
            ])->values()->all(),
            'auditTrail' => $risk->auditTrails->map(fn (RiskAuditTrail $trail) => [
                'id' => $trail->getKey(),
                'action_type' => data_get($trail, 'action_type'),
                'field_changed' => data_get($trail, 'field_changed'),
                'changed_at' => optional(data_get($trail, 'changed_at'))->toIso8601String(),
            ])->values()->all(),
            'availableControls' => $this->unmappedControls($risk),
        ];
    }

    /**
     * The KPI figures, each either a number or an explicit absence.
     *
     * `inherentScore` is null when the risk carries neither a stored score nor
     * a likelihood and impact to derive one from. Risk::inherentScore() is an
     * accessor that multiplies the two and never returns null, so reading it
     * directly gave an unscored risk a literal 0 rated "Low" — a measurement
     * nobody took, on the KPI tile of every newly-created risk. The accessor
     * is untouched (grids, widgets and exports all read it); this decides
     * whether the tile has anything to show. See the characterisation test.
     *
     * @return array<string, mixed>
     */
    public function metrics(Risk $risk): array
    {
        $hasInherent = $risk->getRawOriginal('inherent_score') !== null
            || ($risk->inherent_likelihood !== null && $risk->inherent_impact !== null);

        // Residual has no such accessor, so it genuinely falls back to the
        // latest assessment — and says when it has, because a residual score
        // the risk has not inherited is not yet the risk's own.
        $latest = $risk->relationLoaded('assessments')
            ? $risk->assessments->first()
            : $risk->assessments()->orderByDesc('assessment_date')->first();

        $residualScore = $risk->residual_score ?? $latest?->residual_score;
        $residualRating = $risk->residual_rating ?? $latest?->residual_rating;

        [$effectivenessPct, $effectivenessSubtitle] = $this->controlEffectiveness($risk);

        return [
            'inherentScore' => $hasInherent ? (int) $risk->inherent_score : null,
            'inherentRating' => $hasInherent ? $risk->inherent_rating : null,
            'residualScore' => $residualScore === null ? null : (int) $residualScore,
            'residualRating' => $residualRating,
            'pendingApproval' => $risk->residual_score === null
                && $latest !== null
                && $latest->residual_score !== null,
            'controlEffectivenessPct' => $effectivenessPct,
            'controlEffectivenessSubtitle' => $effectivenessSubtitle,
            'treatmentStrategy' => $risk->treatment_strategy,
            'status' => $risk->status ?? 'active',
            'impactDimensions' => [
                'Financial' => $risk->inherent_impact_financial,
                'Operational' => $risk->inherent_impact_operational,
                'Reputational' => $risk->inherent_impact_reputational,
                'Regulatory' => $risk->inherent_impact_regulatory,
            ],
        ];
    }

    /**
     * Control effectiveness, in the priority order the screen has always used.
     *
     * @return array{0: int|null, 1: string}
     */
    private function controlEffectiveness(Risk $risk): array
    {
        if ($risk->control_effectiveness_pct !== null) {
            return [(int) round((float) $risk->control_effectiveness_pct), 'Effectiveness'];
        }

        $mapped = $risk->relationLoaded('controlMappings')
            ? $risk->controlMappings
            : $risk->controlMappings()->get();

        if ($mapped->isEmpty()) {
            // The Blade tile printed "0%" beside this subtitle. There is no
            // measurement to round to zero — the tile now says so instead.
            return [null, 'No controls mapped'];
        }

        return [
            (int) round((float) $mapped->avg(fn (Control $control) => $control->effectiveness_percent)),
            $mapped->count().' mapped control'.($mapped->count() === 1 ? '' : 's'),
        ];
    }

    /** @return array<string, mixed> */
    public function present(Risk $risk): array
    {
        return [
            'id' => $risk->id,
            'risk_code' => $risk->risk_code,
            'title' => $risk->title,
            'description' => $risk->description,
            'category' => $risk->category?->name,
            'category_id' => $risk->category_id,
            'business_unit' => $risk->businessUnit?->name,
            'business_unit_id' => $risk->business_unit_id,
            'process_id' => $risk->process_id,
            'risk_owner' => $risk->riskOwner?->name,
            'risk_owner_id' => $risk->risk_owner_id,
            'risk_steward_id' => $risk->risk_steward_id,
            'identified_by' => $risk->identified_by,
            'entity' => $risk->entity ? $risk->entity->name.' ('.$risk->entity->entity_code.')' : null,
            'date_identified' => optional($risk->date_identified)->toDateString(),
            'risk_source' => $risk->risk_source,
            'risk_type' => $risk->risk_type,
            'status' => $risk->status,
            'inherent_likelihood' => $risk->inherent_likelihood,
            'impact_financial' => $risk->inherent_impact_financial,
            'impact_operational' => $risk->inherent_impact_operational,
            'impact_reputational' => $risk->inherent_impact_reputational,
            'impact_regulatory' => $risk->inherent_impact_regulatory,
            'treatment_strategy' => $risk->treatment_strategy,
            'risk_velocity' => $risk->risk_velocity,
            'review_frequency' => $risk->review_frequency,
            'financial_exposure' => $risk->financial_exposure_ngn,
            'regulatory_tags' => $risk->regulatory_mapping ?? [],
            'notes' => $risk->appetite_notes,
        ];
    }

    /**
     * Controls in the tenant not already mapped to this risk — the "Map
     * Existing Control" dropdown on the Controls tab.
     *
     * @return list<array{id: int, control_code: string|null, name: string|null}>
     */
    public function unmappedControls(Risk $risk): array
    {
        $mapped = $risk->relationLoaded('controlMappings')
            ? $risk->controlMappings->pluck('id')->all()
            : $risk->controlMappings()->pluck('controls.id')->all();

        return Control::query()
            ->where('organization_id', $risk->organization_id)
            ->whereNotIn('id', $mapped)
            ->orderBy('control_code')
            ->get(['id', 'control_code', 'name'])
            ->map(fn (Control $control) => [
                'id' => $control->id,
                'control_code' => $control->control_code,
                'name' => $control->name,
            ])
            ->values()
            ->all();
    }

    /**
     * The lookups and option lists both forms need. The option lists were
     * literals inside the two Blade templates, which is how the Risk Source
     * select came to offer six labels the validator never checked.
     *
     * @return array<string, mixed>
     */
    public function formOptions(): array
    {
        $orgId = TenantContext::organizationId();

        $named = fn ($query) => $query->where('organization_id', $orgId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])
            ->values()
            ->all();

        return [
            'categories' => $named(RiskCategory::query()),
            'businessUnits' => $named(BusinessUnit::query()),
            'processes' => $named(BusinessProcess::query()),
            'users' => $named(User::query()),
            'options' => [
                'likelihood' => [
                    ['value' => 1, 'label' => '1 - Rare'],
                    ['value' => 2, 'label' => '2 - Unlikely'],
                    ['value' => 3, 'label' => '3 - Possible'],
                    ['value' => 4, 'label' => '4 - Likely'],
                    ['value' => 5, 'label' => '5 - Almost Certain'],
                ],
                'impact' => [
                    ['value' => 1, 'label' => '1 - Insignificant'],
                    ['value' => 2, 'label' => '2 - Minor'],
                    ['value' => 3, 'label' => '3 - Moderate'],
                    ['value' => 4, 'label' => '4 - Major'],
                    ['value' => 5, 'label' => '5 - Catastrophic'],
                ],
                'impactDimensions' => collect(self::IMPACT_DIMENSIONS)
                    ->map(fn (array $meta, string $field) => $meta + ['field' => $field])
                    ->values()
                    ->all(),
                'riskSources' => ['Self-Identified', 'Audit Finding', 'Regulatory', 'Incident', 'RCSA', 'External'],
                'riskTypes' => ['strategic', 'operational', 'financial', 'compliance', 'technology', 'reputational'],
                'treatmentStrategies' => ['mitigate', 'accept', 'transfer', 'avoid'],
                'velocities' => ['slow', 'medium', 'fast'],
                'reviewFrequencies' => ['monthly', 'quarterly', 'semi-annual', 'annual'],
                'statuses' => ['active', 'dormant', 'closed', 'retired'],
                'frameworks' => ['CBN ORMS', 'Basel III', 'NDPA', 'NFIU', 'BOFIA', 'SEC Rules'],
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Write side */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $validated  StoreRiskRequest::validated()
     */
    public function create(array $validated, int $organizationId, ?int $userId): Risk
    {
        return DB::transaction(function () use ($validated, $organizationId, $userId) {
            $riskCode = ReferenceCodeService::generate('risks', 'risk_code', 'RK');

            $maxImpact = $this->scoring->calculateMaxImpact(
                (int) $validated['impact_financial'],
                (int) $validated['impact_operational'],
                (int) $validated['impact_reputational'],
                (int) $validated['impact_regulatory'],
                null,
                $organizationId
            );
            $inherentScore = $this->scoring->calculateScore((int) $validated['inherent_likelihood'], $maxImpact);
            $inherentRating = $this->scoring->calculateRating($inherentScore);

            $risk = Risk::create([
                'organization_id' => $organizationId,
                'risk_code' => $riskCode,
                'title' => $validated['title'],
                'description' => $validated['description'],
                'category_id' => $validated['category_id'],
                'business_unit_id' => $validated['business_unit_id'],
                'process_id' => $validated['process_id'] ?? null,
                'risk_owner_id' => $validated['risk_owner_id'],
                'risk_steward_id' => $validated['risk_steward_id'] ?? null,
                'identified_by' => $validated['identified_by'] ?? null,
                'date_identified' => $validated['date_identified'] ?? null,
                'risk_source' => $validated['risk_source'] ?? 'Self-Identified',
                'risk_type' => $validated['risk_type'] ?? 'operational',
                'inherent_likelihood' => $validated['inherent_likelihood'],
                'inherent_impact' => $maxImpact,
                'inherent_impact_financial' => $validated['impact_financial'],
                'inherent_impact_operational' => $validated['impact_operational'],
                'inherent_impact_reputational' => $validated['impact_reputational'],
                'inherent_impact_regulatory' => $validated['impact_regulatory'],
                'inherent_score' => $inherentScore,
                'inherent_rating' => $inherentRating,
                'treatment_strategy' => $validated['treatment_strategy'] ?? null,
                'risk_velocity' => $validated['risk_velocity'] ?? null,
                'review_frequency' => $validated['review_frequency'] ?? null,
                'financial_exposure_ngn' => $validated['financial_exposure'] ?? null,
                'regulatory_mapping' => $validated['regulatory_tags'] ?? null,
                'appetite_notes' => $validated['notes'] ?? null,
                'status' => $validated['status'] ?? 'active',
                'created_by' => $userId,
            ]);

            $this->audit($risk, 'created', 'all', null, $risk->toArray(), $userId);

            return $risk;
        });
    }

    /**
     * @param  array<string, mixed>  $validated  UpdateRiskRequest::validated()
     */
    public function update(Risk $risk, array $validated, ?int $userId): Risk
    {
        return DB::transaction(function () use ($risk, $validated, $userId) {
            $oldValues = $risk->toArray();

            $inherentImpact = max(
                (int) $validated['impact_financial'],
                (int) $validated['impact_operational'],
                (int) $validated['impact_reputational'],
                (int) $validated['impact_regulatory']
            );
            $inherentScore = (int) $validated['inherent_likelihood'] * $inherentImpact;
            $inherentRating = $this->scoring->calculateRating($inherentScore);

            $risk->update([
                'title' => $validated['title'],
                'description' => $validated['description'],
                'category_id' => $validated['category_id'],
                'business_unit_id' => $validated['business_unit_id'],
                'process_id' => $validated['process_id'] ?? null,
                'risk_owner_id' => $validated['risk_owner_id'],
                'risk_steward_id' => $validated['risk_steward_id'] ?? null,
                'identified_by' => $validated['identified_by'] ?? null,
                'date_identified' => $validated['date_identified'] ?? null,
                // `risks.risk_source` is NOT NULL. The controller assigned
                // `?? null` here, so saving the edit form with Risk Source left
                // blank — its select offers an empty option — threw a
                // constraint violation and 500'd. Keeping the stored value is
                // what the screen already implies: the field was not edited.
                'risk_source' => $validated['risk_source'] ?? $risk->risk_source,
                'status' => $validated['status'],
                'inherent_likelihood' => $validated['inherent_likelihood'],
                'inherent_impact' => $inherentImpact,
                'inherent_impact_financial' => $validated['impact_financial'],
                'inherent_impact_operational' => $validated['impact_operational'],
                'inherent_impact_reputational' => $validated['impact_reputational'],
                'inherent_impact_regulatory' => $validated['impact_regulatory'],
                'inherent_score' => $inherentScore,
                'inherent_rating' => $inherentRating,
                'treatment_strategy' => $validated['treatment_strategy'] ?? null,
                'risk_velocity' => $validated['risk_velocity'] ?? null,
                'review_frequency' => $validated['review_frequency'] ?? null,
                'financial_exposure_ngn' => $validated['financial_exposure'] ?? null,
                'regulatory_mapping' => $validated['regulatory_tags'] ?? null,
                'appetite_notes' => $validated['notes'] ?? null,
            ]);

            $this->audit($risk, 'updated', 'multiple', $oldValues, $risk->fresh()->toArray(), $userId);

            return $risk;
        });
    }

    public function delete(Risk $risk, ?int $userId): void
    {
        DB::transaction(function () use ($risk, $userId) {
            $this->audit($risk, 'deleted', 'all', $risk->toArray(), null, $userId);

            $risk->delete();
        });
    }

    /**
     * Attach a control to the risk. Idempotent: a control already mapped is
     * reported rather than mapped twice.
     *
     * @param  array<string, mixed>  $validated  MapControlRequest::validated()
     * @return array{control: Control, mapped: bool}
     */
    public function mapControl(Risk $risk, array $validated): array
    {
        $control = Control::query()
            ->where('organization_id', $risk->organization_id)
            ->findOrFail($validated['control_id']);

        $exists = RiskControlMapping::query()
            ->where('risk_id', $risk->id)
            ->where('control_id', $control->id)
            ->exists();

        if ($exists) {
            return ['control' => $control, 'mapped' => false];
        }

        // `mapping_rationale` is NOT NULL and `control_weight` NOT NULL with a
        // default of 1.00, while both inputs are optional on the form. The
        // controller wrote `?? null` into each, so a mapping saved without a
        // rationale was a constraint violation. An absent weight is left to
        // the column default rather than overwritten with null; an absent
        // rationale is stored as the empty string the column can hold.
        RiskControlMapping::create(array_filter([
            'risk_id' => $risk->id,
            'control_id' => $control->id,
            'is_key_control' => (bool) ($validated['is_key_control'] ?? false),
            'control_weight' => $validated['control_weight'] ?? null,
            'mapping_rationale' => $validated['mapping_rationale'] ?? '',
        ], fn ($value) => $value !== null));

        return ['control' => $control, 'mapped' => true];
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(Risk $risk, string $action, string $field, ?array $old, ?array $new, ?int $userId): void
    {
        RiskAuditTrail::create([
            'entity_type' => $risk->getMorphClass(),
            'entity_id' => $risk->id,
            'organization_id' => $risk->organization_id,
            'action_type' => $action,
            'field_changed' => $field,
            'old_value' => $old === null ? null : json_encode($old),
            'new_value' => $new === null ? null : json_encode($new),
            'changed_by' => $userId,
            'changed_at' => now(),
        ]);
    }
}

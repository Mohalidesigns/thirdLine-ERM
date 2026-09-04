<?php

namespace App\Services\Controls;

use App\Events\ControlUpdated;
use App\Models\BusinessUnit;
use App\Models\Control;
use App\Models\Risk;
use App\Models\RiskControlMapping;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\ControlEffectivenessService;
use App\Support\Tenancy\TenantContext;

/**
 * The control library (migration Phase 3.4).
 *
 * Lifted out of ControlController: every write here touches the control, its
 * risk mappings and — through ControlEffectivenessService — the residual score
 * of every risk the control is mapped to, which is the ThirdLine §4.2 line for
 * a service. The effectiveness arithmetic itself stays where it is; this
 * composes it.
 */
class ControlService
{
    public function __construct(private ControlEffectivenessService $effectiveness) {}

    /* ------------------------------------------------------------------ */
    /*  Read side */
    /* ------------------------------------------------------------------ */

    /**
     * The lookups and vocabularies both forms need. The option lists were
     * literals inside the two Blade templates and inline `in:` rules; they are
     * now the model constants the Form Requests validate against, so a select
     * cannot offer a value the validator rejects.
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
            'businessUnits' => $named(BusinessUnit::query()),
            'users' => $named(User::query()),
            'risks' => Risk::where('organization_id', $orgId)
                ->visibleTo()
                ->orderBy('risk_code')
                ->get(['id', 'risk_code', 'title'])
                ->map(fn (Risk $risk) => [
                    'id' => $risk->id,
                    'risk_code' => $risk->risk_code,
                    'title' => $risk->title,
                ])->values()->all(),
            'options' => [
                'types' => Control::TYPES,
                'natures' => Control::NATURES,
                'frequencies' => Control::FREQUENCIES,
                'effectivenessRatings' => Control::EFFECTIVENESS_RATINGS,
                'statuses' => Control::STATUSES,
            ],
        ];
    }

    /**
     * Everything the detail page draws.
     *
     * @return array<string, mixed>
     */
    public function detail(Control $control): array
    {
        $control->load(['controlOwner', 'businessUnit', 'risks', 'tests.tester']);

        return [
            'control' => $this->present($control),
            'linkedRisks' => $control->risks->map(fn (Risk $risk) => [
                'id' => $risk->id,
                'risk_code' => $risk->risk_code,
                'title' => $risk->title,
                'inherent_rating' => $risk->inherent_rating,
                'residual_rating' => $risk->residual_rating,
                'is_key_control' => (bool) data_get($risk, 'pivot.is_key_control'),
                'control_weight' => (float) (data_get($risk, 'pivot.control_weight') ?? 1.0),
                'mapping_rationale' => data_get($risk, 'pivot.mapping_rationale'),
            ])->values()->all(),
            'tests' => $control->tests->sortByDesc('scheduled_date')->take(10)
                ->map(fn ($test) => [
                    'id' => $test->getKey(),
                    'test_code' => data_get($test, 'test_code'),
                    'title' => data_get($test, 'title'),
                    'test_type' => data_get($test, 'test_type'),
                    'status' => data_get($test, 'status'),
                    'result' => data_get($test, 'result'),
                    'scheduled_date' => optional(data_get($test, 'scheduled_date'))->toDateString(),
                    'tester' => data_get($test, 'tester.name'),
                ])->values()->all(),
            // Risks this control could still be mapped to — the "Link to Risk"
            // dropdown. A risk already mapped is not offered again; the mapping
            // is unique on (risk_id, control_id) and offering it would produce
            // an error the caller could have been spared.
            'linkableRisks' => Risk::where('organization_id', $control->organization_id)
                ->visibleTo()
                ->whereNotIn('id', $control->risks->modelKeys())
                ->orderBy('risk_code')
                ->get(['id', 'risk_code', 'title'])
                ->map(fn (Risk $risk) => [
                    'id' => $risk->id,
                    'risk_code' => $risk->risk_code,
                    'title' => $risk->title,
                ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function present(Control $control): array
    {
        return [
            'id' => $control->id,
            'control_code' => $control->control_code,
            'name' => $control->name,
            'description' => $control->description,
            'control_type' => $control->control_type,
            'control_nature' => $control->control_nature,
            'frequency' => $control->frequency,
            'automation_level' => $control->automation_level,
            'owner_id' => $control->owner_id,
            'owner' => $control->controlOwner?->name,
            'business_unit_id' => $control->business_unit_id,
            'business_unit' => $control->businessUnit?->name,
            'effectiveness_rating' => $control->effectiveness_rating,
            // The accessor's number, which is the organisation's configured
            // band map — not a second interpretation of the rating.
            'effectiveness_percent' => $control->effectiveness_rating ? $control->effectiveness_percent : null,
            'effectiveness_label' => $control->effectiveness_label,
            'status' => $control->status,
            'last_test_date' => optional($control->last_test_date)->toDateString(),
            'next_test_due' => optional($control->next_test_due)->toDateString(),
            'last_test_result' => $control->last_test_result,
            'tests_passed_count' => $control->tests_passed_count,
            'tests_failed_count' => $control->tests_failed_count,
            'total_tests_count' => $control->total_tests_count,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Write side */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $validated  StoreControlRequest::validated()
     */
    public function create(array $validated, int $organizationId, ?int $userId): Control
    {
        $riskIds = $validated['risk_ids'] ?? [];
        unset($validated['risk_ids']);

        $control = Control::create(array_merge($validated, [
            'organization_id' => $organizationId,
            'control_code' => $this->nextControlCode($organizationId),
            'status' => $validated['status'] ?? 'active',
            'created_by' => $userId,
        ]));

        foreach ($riskIds as $riskId) {
            RiskControlMapping::create([
                'risk_id' => $riskId,
                'control_id' => $control->id,
                'organization_id' => $organizationId,
                'mapping_rationale' => 'Linked during control creation',
                'created_by' => $userId,
            ]);
        }

        AuditTrailService::record($control, 'create');

        return $control;
    }

    /**
     * @param  array<string, mixed>  $validated  UpdateControlRequest::validated()
     */
    public function update(Control $control, array $validated, ?int $userId): Control
    {
        $original = $control->getAttributes();

        $control->update(array_merge($validated, ['updated_by' => $userId]));

        // A rating change moves the residual score of every risk this control
        // is mapped to, which is the point of rating it.
        foreach ($control->risks as $risk) {
            $this->effectiveness->recalculateForRisk($risk);
        }

        AuditTrailService::recordChanges($control, $original);

        $changedFields = array_keys(array_diff_assoc($control->getAttributes(), $original));
        ControlUpdated::dispatch($control, $changedFields);

        return $control;
    }

    /**
     * Delete a control, or say why not.
     *
     * @return string|null the refusal message, or null when it was deleted
     */
    public function delete(Control $control): ?string
    {
        $linked = RiskControlMapping::where('control_id', $control->id)->count();

        if ($linked > 0) {
            return "Cannot delete control {$control->control_code}: it is linked to {$linked} risk(s). Please unlink first.";
        }

        AuditTrailService::record($control, 'delete');

        $control->delete();

        return null;
    }

    /**
     * Map this control onto a risk.
     *
     * @param  array<string, mixed>  $validated  LinkControlRiskRequest::validated()
     * @return array{risk: Risk, linked: bool}
     */
    public function linkRisk(Control $control, array $validated, ?int $userId): array
    {
        $risk = Risk::where('organization_id', $control->organization_id)
            ->findOrFail($validated['risk_id']);

        $exists = RiskControlMapping::where('risk_id', $risk->id)
            ->where('control_id', $control->id)
            ->exists();

        if ($exists) {
            return ['risk' => $risk, 'linked' => false];
        }

        // The columns the controller wrote here — `weight`, `rationale`,
        // `mapping_status`, `linked_by` — are not columns of
        // `risk_control_mapping` and are not fillable, so mass assignment
        // dropped all four and left `mapping_rationale` unset. That column is
        // NOT NULL, so linking a control to a risk from this screen was a
        // constraint violation every time. The real column names, with the
        // weight left to its 1.00 default when none is given.
        RiskControlMapping::create(array_filter([
            'risk_id' => $risk->id,
            'control_id' => $control->id,
            'organization_id' => $control->organization_id,
            'control_weight' => $validated['weight'] ?? null,
            'is_key_control' => (bool) ($validated['is_key_control'] ?? false),
            'mapping_rationale' => $validated['rationale'] ?? '',
            'created_by' => $userId,
        ], fn ($value) => $value !== null));

        $this->effectiveness->recalculateForRisk($risk);

        return ['risk' => $risk, 'linked' => true];
    }

    public function unlinkRisk(Control $control, Risk $risk): void
    {
        RiskControlMapping::where('risk_id', $risk->id)
            ->where('control_id', $control->id)
            ->firstOrFail()
            ->delete();

        $this->effectiveness->recalculateForRisk($risk);
    }

    /**
     * `CTL-NNNN`, from the tenant's highest control id.
     *
     * Kept as it was rather than moved to ReferenceCodeService, which has no
     * control prefix: a collision is theoretically possible under concurrent
     * creates, exactly as before.
     */
    private function nextControlCode(int $organizationId): string
    {
        $last = Control::where('organization_id', $organizationId)->orderByDesc('id')->first();

        return sprintf('CTL-%04d', $last ? $last->id + 1 : 1);
    }
}

<?php

namespace App\Support\Graph;

/**
 * Which column on a typed table means what to the graph.
 *
 * The domain tables were written over five releases by different hands, so the
 * reference code is `risk_code` on one, `event_reference` on the next and
 * `scenario_reference` on a third; the state is `status`, `issue_status` or
 * `current_status` depending on the table. Rather than scatter that knowledge
 * across a trait, a backfill migration and a query service, it lives here once.
 *
 * Every column named below was verified against the live schema. Where a table
 * carries both a deprecated and a canonical column for the same fact (see
 * docs/schema/canonical-columns.md) the CANONICAL one is used —
 * `event_reference` not `reference`, `action_title` not `treatment_title`.
 *
 * @phpstan-type SourceSpec array{
 *     table: string,
 *     type_code: string,
 *     code: ?string,
 *     code_prefix: string,
 *     name: ?string,
 *     description: ?string,
 *     owner: ?string,
 *     delegate_owner: ?string,
 *     lifecycle_state: ?string,
 *     status: ?string,
 *     active_flag: ?string,
 *     entity: ?string,
 *     business_unit: ?string,
 *     node_via: ?array{table: string, key: string},
 *     parent: ?string,
 *     effective_from: ?string,
 *     effective_to: ?string,
 *     is_node: bool,
 * }
 */
class ObjectSourceMap
{
    /**
     * @return array<class-string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            \App\Models\Risk::class => self::spec('risks', 'Risk', 'RK', [
                'code' => 'risk_code',
                'name' => 'title',
                'owner' => 'risk_owner_id',
                'delegate_owner' => 'risk_steward_id',
                'lifecycle_state' => 'status',
                'entity' => 'entity_id',
                'business_unit' => 'business_unit_id',
                'parent' => 'parent_risk_id',
                'effective_from' => 'date_identified',
            ]),

            \App\Models\Control::class => self::spec('controls', 'Control', 'CTL', [
                'code' => 'control_code',
                'owner' => 'owner_id',
                'lifecycle_state' => 'status',
                'entity' => 'entity_id',
                'business_unit' => 'business_unit_id',
            ]),

            \App\Models\KeyRiskIndicator::class => self::spec('key_risk_indicators', 'KeyRiskIndicator', 'KRI', [
                'code' => 'kri_code',
                'owner' => 'owner_id',
                // A KRI has no workflow state; is_active is its lifecycle and
                // current_status is its RAG reading, which is a measurement,
                // not a state the object moves through.
                'active_flag' => 'is_active',
                'status' => 'current_status',
                'entity' => 'entity_id',
                // KRIs carry no business_unit_id, and entity_id is unset on
                // every row in the live data. The indicator monitors a risk,
                // so it sits on whatever node that risk sits on.
                'node_via' => ['table' => 'risks', 'key' => 'risk_id'],
            ]),

            \App\Models\Issue::class => self::spec('issues', 'Issue', 'ISS', [
                'code' => 'issue_reference',
                'name' => 'title',
                'owner' => 'responsible_owner_id',
                'lifecycle_state' => 'issue_status',
                'entity' => 'entity_id',
                'business_unit' => 'business_unit_id',
                'effective_from' => 'examination_date',
            ]),

            \App\Models\LossEvent::class => self::spec('loss_events', 'LossEvent', 'LE', [
                'code' => 'event_reference',
                'name' => 'title',
                'owner' => 'responsible_officer_id',
                'lifecycle_state' => 'current_status',
                'entity' => 'entity_id',
                'business_unit' => 'business_unit_id',
                'effective_from' => 'date_of_loss',
            ]),

            \App\Models\NearMiss::class => self::spec('near_misses', 'NearMiss', 'NM', [
                'code' => 'event_reference',
                'name' => 'title',
                'owner' => 'investigator_id',
                'lifecycle_state' => 'status',
                'business_unit' => 'business_unit_id',
                'effective_from' => 'date_occurred',
            ]),

            \App\Models\TreatmentPlan::class => self::spec('treatment_plans', 'TreatmentPlan', 'TP', [
                'code' => 'treatment_code',
                'name' => 'action_title',
                'description' => 'action_description',
                'owner' => 'owner_id',
                'lifecycle_state' => 'status',
                'node_via' => ['table' => 'risks', 'key' => 'risk_id'],
                'effective_to' => 'target_date',
            ]),

            // No code and no name column of its own: an appetite row is
            // identified by the category it applies to. The synthetic code is
            // generated from the primary key, which is stable.
            \App\Models\RiskAppetite::class => self::spec('risk_appetite', 'RiskAppetite', 'RA', [
                'code' => null,
                'name' => 'appetite_statement',
                'description' => 'tolerance_metric',
                'owner' => 'approved_by',
                'lifecycle_state' => 'appetite_level',
                'effective_from' => 'effective_date',
                'effective_to' => 'expiry_date',
            ]),

            \App\Models\AssessmentCampaign::class => self::spec('assessment_campaigns', 'AssessmentCampaign', 'CMP', [
                'code' => 'campaign_code',
                'name' => 'title',
                'owner' => 'reviewer_id',
                'lifecycle_state' => 'status',
                'effective_from' => 'start_date',
                'effective_to' => 'end_date',
            ]),

            // Phase 4.6. `emerging_risks` has no entity_id or business_unit_id
            // column — an emerging risk is on the organisation's horizon, not a
            // node's, which is why neither is mapped here.
            \App\Models\EmergingRisk::class => self::spec('emerging_risks', 'EmergingRisk', 'EMR', [
                'code' => 'reference',
                'name' => 'title',
                'owner' => 'owner_id',
                'lifecycle_state' => 'status',
                'effective_from' => 'detected_at',
            ]),

            \App\Models\ControlTest::class => self::spec('control_tests', 'ControlTest', 'CT', [
                'code' => 'test_code',
                'name' => 'title',
                'owner' => 'tester_id',
                'lifecycle_state' => 'status',
                'node_via' => ['table' => 'controls', 'key' => 'control_id'],
                'effective_from' => 'scheduled_date',
            ]),

            // Type is resolved per row from entity_type_id — see
            // Entity::resolveObjectTypeCode(). The value here is the fallback.
            \App\Models\Entity::class => self::spec('entities', 'BusinessUnit', 'ENT', [
                'code' => 'entity_code',
                'owner' => 'owner_id',
                'delegate_owner' => 'delegate_owner_id',
                'lifecycle_state' => 'status',
                'parent' => 'parent_id',
                'is_node' => true,
            ]),

            \App\Models\BusinessUnit::class => self::spec('business_units', 'BusinessUnit', 'BU', [
                'code' => 'code',
                'owner' => 'head_id',
                'active_flag' => 'is_active',
                'parent' => 'parent_id',
                'is_node' => true,
            ]),

            \App\Models\BusinessProcess::class => self::spec('business_processes', 'Process', 'PRC', [
                'code' => 'code',
                'owner' => 'owner_id',
                'active_flag' => 'is_active',
                'business_unit' => 'business_unit_id',
                'is_node' => true,
            ]),

            \App\Models\RiskCategory::class => self::spec('risk_categories', 'RiskCategory', 'RC', [
                'code' => 'code',
                'active_flag' => 'is_active',
                'parent' => 'parent_id',
            ]),

            \App\Models\QuantificationScenario::class => self::spec('quantification_scenarios', 'Scenario', 'SCN', [
                'code' => 'scenario_reference',
                'owner' => 'calibrated_by',
                'lifecycle_state' => 'status',
                'node_via' => ['table' => 'risks', 'key' => 'risk_register_id'],
            ]),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function for(string $modelClass): ?array
    {
        return self::all()[ltrim($modelClass, '\\')] ?? null;
    }

    /**
     * The specs whose table is an org-graph node, in the order the unification
     * pass must build them: entities and business units first, because a
     * process hangs off a business unit.
     *
     * @return array<class-string, array<string, mixed>>
     */
    public static function nodeSpecs(): array
    {
        return array_filter(self::all(), fn (array $spec) => $spec['is_node']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function spec(string $table, string $typeCode, string $prefix, array $overrides = []): array
    {
        return array_merge([
            'table' => $table,
            'type_code' => $typeCode,
            'code_prefix' => $prefix,
            'code' => 'code',
            'name' => 'name',
            'description' => 'description',
            'owner' => null,
            'delegate_owner' => null,
            'lifecycle_state' => null,
            'status' => null,
            'active_flag' => null,
            'entity' => null,
            'business_unit' => null,
            'node_via' => null,
            'parent' => null,
            'effective_from' => null,
            'effective_to' => null,
            'is_node' => false,
        ], $overrides);
    }
}

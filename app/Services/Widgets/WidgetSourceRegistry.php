<?php

namespace App\Services\Widgets;

use App\Models\Control;
use App\Models\ControlTest;
use App\Models\GraphObject;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\LossEvent;
use App\Models\MeasureBreach;
use App\Models\NearMiss;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\TreatmentPlan;

/**
 * WP-08 TASK 1 — the whitelist between user-authored widget JSON and the
 * query builder.
 *
 * A widget definition's `query` block is tenant-authored content on a
 * multi-tenant platform, the same trust level as a workflow condition. It
 * never reaches the query builder raw: every source, column, join, aggregate
 * and sort passes through this registry, and anything not listed here does
 * not exist as far as the engine is concerned. Failing closed on an unknown
 * name is the entire design.
 *
 * Per source:
 *   model        the Eloquent class (carries tenancy; queries never start
 *                from DB::table)
 *   node_column  how the row hangs off the org graph — 'node_id' everywhere,
 *                written back by ObjectSyncService on save
 *   date_column  what "in this period" filters against
 *   label/code   what a register row is called and keyed by
 *   owner_column what "mine" means for this source
 *   columns      filter/group/sort vocabulary
 *   sums         columns that may be aggregated beyond COUNT
 *   permission   the module permission a viewer needs before this source
 *                renders for them at all
 */
class WidgetSourceRegistry
{
    /** @var array<string, array<string, mixed>> */
    private const SOURCES = [
        'risks' => [
            'model' => Risk::class,
            'node_column' => 'node_id',
            'date_column' => 'created_at',
            'code_column' => 'risk_code',
            'label_column' => 'title',
            'owner_column' => 'risk_owner_id',
            'permission' => 'risk.view',
            'columns' => [
                'risk_code', 'title', 'status', 'risk_source', 'treatment_strategy',
                'inherent_likelihood', 'inherent_impact', 'inherent_score', 'inherent_rating',
                'residual_likelihood', 'residual_impact', 'residual_score', 'residual_rating',
                'target_rating', 'risk_velocity', 'control_effectiveness_pct',
                'financial_exposure_ngn', 'category_id', 'business_unit_id', 'process_id',
                'risk_owner_id', 'date_identified', 'last_assessment_date', 'next_review_date',
                'created_at',
            ],
            'sums' => ['residual_score', 'inherent_score', 'financial_exposure_ngn', 'control_effectiveness_pct'],
        ],
        'controls' => [
            'model' => Control::class,
            'node_column' => 'node_id',
            'date_column' => 'created_at',
            'code_column' => 'control_code',
            'label_column' => 'name',
            'owner_column' => 'owner_id',
            'permission' => 'control.view',
            'columns' => [
                'control_code', 'name', 'status', 'control_type', 'control_nature',
                'frequency', 'automation_level', 'effectiveness_rating', 'effectiveness_pct',
                'last_test_date', 'next_test_due', 'last_test_result', 'owner_id',
                'business_unit_id', 'created_at',
            ],
            'sums' => ['effectiveness_pct'],
        ],
        'issues' => [
            'model' => Issue::class,
            'node_column' => 'node_id',
            'date_column' => 'created_at',
            'code_column' => 'issue_reference',
            'label_column' => 'title',
            'owner_column' => 'responsible_owner_id',
            'permission' => 'issue.view',
            'columns' => [
                'issue_reference', 'title', 'issue_status', 'issue_category', 'priority',
                'issue_source', 'regulatory_reportable', 'cbn_reportable',
                'remediation_due_date', 'actual_close_date', 'progress_percentage',
                'current_escalation_level', 'responsible_owner_id', 'business_unit_id',
                'potential_loss_kobo', 'actual_loss_kobo', 'created_at',
            ],
            'sums' => ['potential_loss_kobo', 'actual_loss_kobo', 'progress_percentage'],
        ],
        'loss_events' => [
            'model' => LossEvent::class,
            'node_column' => 'node_id',
            'date_column' => 'date_of_loss',
            'code_column' => 'event_reference',
            'label_column' => 'title',
            'owner_column' => 'responsible_officer_id',
            'permission' => 'loss_event.view',
            'columns' => [
                'event_reference', 'title', 'current_status', 'event_severity',
                'basel_l1_category', 'basel_l2_category', 'cbn_risk_category', 'loss_category',
                'date_of_loss', 'date_discovered', 'date_reported', 'is_near_miss',
                'gross_loss_amount_kobo', 'insurance_recovery_kobo', 'other_recovery_kobo',
                'cbn_reportable', 'nfiu_reportable', 'business_unit_id',
                'responsible_officer_id', 'created_at',
            ],
            'sums' => ['gross_loss_amount_kobo', 'insurance_recovery_kobo', 'other_recovery_kobo'],
        ],
        'treatment_plans' => [
            'model' => TreatmentPlan::class,
            'node_column' => 'node_id',
            'date_column' => 'target_date',
            'code_column' => 'treatment_code',
            'label_column' => 'action_title',
            'owner_column' => 'owner_id',
            'permission' => 'treatment.view',
            'columns' => [
                'treatment_code', 'action_title', 'strategy', 'status', 'priority',
                'progress_pct', 'target_date', 'completion_date', 'owner_id', 'risk_id',
                'cost_estimate_ngn', 'actual_cost_ngn', 'created_at',
            ],
            'sums' => ['progress_pct', 'cost_estimate_ngn', 'actual_cost_ngn'],
        ],
        'key_risk_indicators' => [
            'model' => KeyRiskIndicator::class,
            'node_column' => 'node_id',
            'date_column' => 'last_measurement_at',
            'code_column' => 'kri_code',
            'label_column' => 'name',
            'owner_column' => 'owner_id',
            'permission' => 'kri.view',
            'columns' => [
                'kri_code', 'name', 'current_status', 'trend_direction', 'current_value',
                'measurement_frequency', 'threshold_direction', 'is_automated', 'is_active',
                'owner_id', 'risk_id', 'last_measurement_at', 'created_at',
            ],
            'sums' => ['current_value'],
        ],
        'control_tests' => [
            'model' => ControlTest::class,
            'node_column' => 'node_id',
            'date_column' => 'scheduled_date',
            'code_column' => 'test_code',
            'label_column' => 'title',
            'owner_column' => 'tester_id',
            'permission' => 'control_test.view',
            'columns' => [
                'test_code', 'title', 'test_type', 'status', 'result', 'score',
                'scheduled_date', 'completed_date', 'tester_id', 'reviewer_id',
                'control_id', 'created_at',
            ],
            'sums' => ['score'],
        ],
        'risk_assessments' => [
            'model' => RiskAssessment::class,
            'node_column' => 'node_id',
            'date_column' => 'assessment_date',
            'code_column' => 'id',
            'label_column' => 'assessment_type',
            'owner_column' => 'assessor_id',
            'permission' => 'assessment.view',
            'columns' => [
                'assessment_type', 'status', 'assessment_date', 'overall_score',
                'overall_rating', 'residual_score', 'residual_rating', 'risk_id',
                'assessor_id', 'created_at',
            ],
            'sums' => ['overall_score', 'residual_score'],
        ],
        'near_misses' => [
            'model' => NearMiss::class,
            'node_column' => 'node_id',
            'date_column' => 'date_occurred',
            'code_column' => 'reference',
            'label_column' => 'title',
            'owner_column' => 'investigator_id',
            'permission' => 'loss_event.view',
            'columns' => [
                'reference', 'title', 'status', 'severity', 'date_occurred',
                'control_gap_identified', 'potential_loss_kobo', 'business_unit_id',
                'created_at',
            ],
            'sums' => ['potential_loss_kobo'],
        ],
        'measure_breaches' => [
            'model' => MeasureBreach::class,
            // object_id names a governance OBJECT (a KRI, a risk), not an org
            // node — the engine widens the scope filter through objects.node_id.
            'node_column' => 'object_id',
            'node_column_kind' => 'object_ref',
            'date_column' => 'breached_at',
            'code_column' => 'id',
            'label_column' => 'band_to',
            'owner_column' => null,
            'permission' => 'measure.view',
            'columns' => [
                'status', 'severity', 'band_from', 'band_to', 'breached_at',
                'measure_id', 'period_id', 'created_at',
            ],
            'sums' => ['value'],
        ],
        // The graph itself — what bar_by_type and the generic registers read.
        'objects' => [
            'model' => GraphObject::class,
            // An object is in scope when it IS one of the nodes or HANGS OFF
            // one — the engine matches id OR node_id.
            'node_column' => 'node_id',
            'node_column_kind' => 'self_or_node',
            'date_column' => 'created_at',
            'code_column' => 'code',
            'label_column' => 'name',
            'owner_column' => 'owner_id',
            'permission' => 'hq.view',
            'columns' => [
                'code', 'name', 'object_type_id', 'lifecycle_state', 'status',
                'owner_id', 'parent_id', 'hierarchy_depth', 'created_at',
            ],
            'sums' => [],
        ],

        /* ------------------------------------------------------------------
         | Third-party risk — FR-RPT-08.
         |
         | THESE FOUR CARRY NO `node_id`, AND THE JOIN IS THE POINT. An
         | engagement is not owned by one part of the organisation the way a
         | risk is: it supports business FUNCTIONS, and a payments switch
         | serves treasury, operations and the branch network at once.
         | Denormalising one node onto `tp_engagements` would have to pick one
         | of those, and picking wrongly is how a business unit stops seeing
         | the vendor it depends on. `engagement_functions` resolves through
         | `tp_engagement_functions` instead — see WidgetQueryEngine.
         ------------------------------------------------------------------ */

        'tprm_engagements' => [
            'model' => \App\Models\Tprm\Engagement::class,
            // The row IS the engagement, so the column holding the engagement
            // id is its own key.
            'node_column' => 'id',
            'node_column_kind' => 'engagement_functions',
            'date_column' => 'created_at',
            'code_column' => 'reference',
            'label_column' => 'name',
            'owner_column' => 'relationship_owner_id',
            'permission' => 'tprm.view',
            'columns' => [
                'reference', 'name', 'engagement_type', 'status', 'effective_tier',
                'inherent_tier', 'inherent_score', 'residual_score', 'residual_band',
                'assurance_coverage', 'evidence_confidence', 'data_confidence',
                'supports_critical_function', 'is_material_outsourcing', 'pci_in_scope',
                'processes_personal_data', 'cross_border', 'cloud_model',
                'exit_plan_required', 'next_assessment_due', 'next_review_due',
                'business_unit_id', 'relationship_owner_id', 'third_party_id',
                'start_date', 'end_date', 'created_at',
            ],
            'sums' => ['residual_score', 'inherent_score', 'annual_spend_minor'],
        ],

        'tprm_findings' => [
            'model' => \App\Models\Tprm\Finding::class,
            'node_column' => 'engagement_id',
            'node_column_kind' => 'engagement_functions',
            // `identified_at`, never `created_at`: a finding imported from a
            // prior programme was identified long before this row existed, and
            // ageing it from the row would restart every clock at go-live.
            'date_column' => 'identified_at',
            'code_column' => 'reference',
            'label_column' => 'title',
            'owner_column' => 'owner_id',
            'permission' => 'tprm.finding.view',
            'columns' => [
                'reference', 'title', 'severity', 'status', 'source', 'identified_at',
                'target_date', 'sla_days', 'closure_type', 'owner_id', 'engagement_id',
                'third_party_id', 'created_at',
            ],
            'sums' => [],
        ],

        'tprm_contracts' => [
            'model' => \App\Models\Tprm\Contract::class,
            'node_column' => 'engagement_id',
            'node_column_kind' => 'engagement_functions',
            'date_column' => 'effective_date',
            'code_column' => 'reference',
            'label_column' => 'title',
            'owner_column' => 'internal_signatory_id',
            'permission' => 'tprm.contract.view',
            'columns' => [
                'reference', 'title', 'contract_type', 'status', 'effective_date',
                'expiry_date', 'renewal_type', 'notice_period_days_entity',
                'governing_law_country', 'blocking_gaps_count', 'clause_analysis_status',
                'engagement_id', 'created_at',
            ],
            'sums' => ['value_minor', 'blocking_gaps_count'],
        ],

        /*
         * Evidence, scoped by the engagement that owns it.
         *
         * A DOCUMENT OWNED BY THE PROVIDER RATHER THAN THE ENGAGEMENT IS NOT
         * NODE-ATTRIBUTABLE, and that is a fact about SOC 2 reports rather
         * than a limitation of this query: one report covers every engagement
         * with that provider, across every unit that buys from them.
         * `owner_type` is in the whitelist so a widget filters to the
         * engagement-owned population explicitly, and the shipped Expiring
         * Evidence widget says so in its description rather than quietly
         * under-counting on an HQ page.
         */
        'tprm_evidence' => [
            'model' => \App\Models\Tprm\Document::class,
            'node_column' => 'owner_id',
            'node_column_kind' => 'engagement_functions',
            'date_column' => 'valid_to',
            'code_column' => 'version',
            'label_column' => 'title',
            'owner_column' => 'uploaded_by',
            'permission' => 'tprm.evidence.view',
            'columns' => [
                'title', 'owner_type', 'owner_id', 'document_type_id', 'issuer',
                'issue_date', 'valid_from', 'valid_to', 'is_superseded',
                'extraction_status', 'virus_scan_status', 'uploaded_by', 'created_at',
            ],
            'sums' => [],
        ],

        /*
         * Incidents name a PROVIDER, not one engagement — `engagement_ids` is
         * a json array — so they are scoped through that provider's
         * engagements instead. An incident at a vendor that serves three units
         * is in scope on all three, which is the honest answer: the outage
         * happened to all of them.
         */
        'tprm_incidents' => [
            'model' => \App\Models\Tprm\Incident::class,
            'node_column' => 'third_party_id',
            'node_column_kind' => 'third_party_engagements',
            // OUR awareness, not the vendor's detection date — the same column
            // the NDPA §40(2) clock runs from.
            'date_column' => 'reported_to_us_at',
            'code_column' => 'reference',
            'label_column' => 'title',
            'owner_column' => 'created_by',
            'permission' => 'tprm.incident.view',
            'columns' => [
                'reference', 'title', 'type', 'severity', 'status', 'detected_at',
                'reported_to_us_at', 'personal_data_involved', 'customer_impact',
                'cbn_reportable', 'cbn_deadline_at', 'cbn_reported_at',
                'ndpc_reportable', 'ndpc_deadline_at', 'ndpc_reported_at',
                'third_party_id', 'created_at',
            ],
            'sums' => ['estimated_loss_minor', 'data_subjects_affected'],
        ],
    ];

    /** @return array<string, mixed>|null */
    public function source(?string $key): ?array
    {
        return $key === null ? null : (self::SOURCES[$key] ?? null);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::SOURCES);
    }

    public function allowsColumn(string $sourceKey, string $column): bool
    {
        $source = $this->source($sourceKey);

        return $source !== null && in_array($column, $source['columns'], true);
    }

    public function allowsSum(string $sourceKey, string $column): bool
    {
        $source = $this->source($sourceKey);

        return $source !== null && in_array($column, $source['sums'], true);
    }
}

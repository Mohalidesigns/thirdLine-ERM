<?php

namespace App\Http\Api;

use App\Models\AssessmentCampaign;
use App\Models\Control;
use App\Models\ControlTest;
use App\Models\GraphObject;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\LossEvent;
use App\Models\Measure;
use App\Models\MeasureValue;
use App\Models\NearMiss;
use App\Models\ObjectType;
use App\Models\Period;
use App\Models\QuantificationScenario;
use App\Models\Risk;
use App\Models\RiskAppetite;
use App\Models\RiskAssessment;
use App\Models\RiskCategory;
use App\Models\SimulationRun;
use App\Models\TreatmentPlan;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;

/**
 * WP-07 TASK 2 — what the API exposes, declared once.
 *
 * One table instead of twenty near-identical controllers. Every entry states
 * exactly what a client may filter on, sort by, include and read — and that
 * allowlist IS the security boundary: without it, `?filter[password]=` or
 * `?sort=remember_token` become a query the client controls, and
 * `?include=owner.tokens` walks a relationship nobody meant to publish.
 *
 * FIELDS ARE AN ALLOWLIST, NOT A DENYLIST. A column added to `risks` next
 * quarter does not silently appear in the API; somebody has to decide it should
 * be public. That is the right default for a table holding examination findings.
 *
 * `permissions` names the permission BOTH the token and its user must hold for
 * each action. They are spelled out per resource rather than derived from a
 * prefix because the platform's permission names are not uniform — measures are
 * written with `measure.record`, appetites with `appetite.manage`, campaigns
 * with `campaign.manage` — and a derived name that does not exist fails closed,
 * silently making an endpoint unreachable for everybody with nothing to say why.
 * ApiAuthorizationTest checks every one of these against the seeder.
 *
 * A resource with no `create`/`edit` entry is read-only through the API: it is
 * produced by the platform rather than supplied to it. A simulation result is
 * not something a client should be able to assert.
 */
class ApiResourceRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'risks' => [
                'model' => Risk::class,
                'permissions' => ['view' => 'risk.view', 'create' => 'risk.create', 'edit' => 'risk.edit', 'delete' => 'risk.delete'],
                'reference' => ['column' => 'risk_code', 'prefix' => 'RK'],
                'fields' => [
                    'risk_code', 'title', 'description', 'status', 'risk_level',
                    'category_id', 'risk_owner_id', 'business_unit_id', 'node_id',
                    'inherent_likelihood', 'inherent_impact', 'inherent_score', 'inherent_rating',
                    'residual_likelihood', 'residual_impact', 'residual_score', 'residual_rating',
                    'treatment_strategy', 'accepted_by', 'accepted_at', 'acceptance_expires_at',
                    'date_identified', 'last_assessment_date', 'next_review_date',
                    'created_at', 'updated_at',
                ],
                'filters' => ['status', 'risk_level', 'category_id', 'risk_owner_id', 'inherent_rating', 'residual_rating', 'node_id', 'treatment_strategy'],
                'sorts' => ['risk_code', 'title', 'inherent_score', 'residual_score', 'next_review_date', 'created_at', 'updated_at'],
                'includes' => ['category', 'owner', 'businessUnit'],
                'writable' => ['title', 'description', 'category_id', 'risk_owner_id', 'status', 'risk_level', 'inherent_likelihood', 'inherent_impact', 'residual_likelihood', 'residual_impact', 'treatment_strategy', 'date_identified', 'next_review_date'],
            ],

            'controls' => [
                'model' => Control::class,
                'permissions' => ['view' => 'control.view', 'create' => 'control.create', 'edit' => 'control.edit', 'delete' => 'control.delete'],
                'reference' => ['column' => 'control_code', 'prefix' => 'CTL'],
                'fields' => [
                    'control_code', 'name', 'description', 'control_type', 'control_nature',
                    'frequency', 'automation_level', 'effectiveness_rating', 'status',
                    'owner_id', 'node_id', 'created_at', 'updated_at',
                ],
                'filters' => ['status', 'control_type', 'control_nature', 'effectiveness_rating', 'owner_id', 'node_id'],
                'sorts' => ['control_code', 'name', 'effectiveness_rating', 'created_at'],
                'includes' => ['owner'],
                'writable' => ['name', 'description', 'control_type', 'control_nature', 'frequency', 'automation_level', 'status', 'owner_id'],
            ],

            'kris' => [
                'model' => KeyRiskIndicator::class,
                'permissions' => ['view' => 'kri.view', 'create' => 'kri.create', 'edit' => 'kri.edit', 'delete' => 'kri.delete'],
                'reference' => ['column' => 'kri_code', 'prefix' => 'KRI'],
                'fields' => [
                    'kri_code', 'name', 'description', 'measurement_frequency', 'unit_of_measure',
                    'current_value', 'current_status', 'trend_direction', 'owner_id', 'risk_id',
                    'is_automated', 'last_measurement_at', 'created_at', 'updated_at',
                ],
                'filters' => ['current_status', 'measurement_frequency', 'owner_id', 'risk_id', 'is_automated'],
                'sorts' => ['kri_code', 'name', 'current_value', 'last_measurement_at'],
                'includes' => ['owner'],
                'writable' => ['name', 'description', 'measurement_frequency', 'unit_of_measure', 'owner_id', 'risk_id'],
            ],

            'measures' => [
                'model' => Measure::class,
                'permissions' => ['view' => 'measure.view', 'create' => 'measure.manage', 'edit' => 'measure.manage'],
                'fields' => ['code', 'name', 'description', 'measure_kind', 'unit_id', 'aggregation', 'polarity', 'decimal_places', 'frequency', 'owner_id', 'is_active', 'created_at'],
                'filters' => ['measure_kind', 'is_active', 'owner_id', 'code'],
                'sorts' => ['code', 'name', 'created_at'],
                'includes' => ['unit'],
                'writable' => ['name', 'description', 'measure_kind', 'aggregation', 'polarity', 'frequency', 'owner_id', 'is_active'],
            ],

            'measure-values' => [
                'model' => MeasureValue::class,
                'permissions' => ['view' => 'measure.view', 'create' => 'measure.record', 'edit' => 'measure.record'],
                'fields' => ['measure_id', 'object_id', 'period_id', 'scenario', 'value', 'currency_code', 'fx_rate_used', 'status', 'rag_band', 'entered_by', 'entered_at', 'source', 'note', 'created_at'],
                'filters' => ['measure_id', 'object_id', 'period_id', 'scenario', 'status', 'rag_band'],
                'sorts' => ['entered_at', 'created_at', 'value'],
                'includes' => ['measure', 'period'],
                'writable' => ['measure_id', 'object_id', 'period_id', 'scenario', 'value', 'currency_code', 'status', 'note', 'source'],
            ],

            'issues' => [
                'model' => Issue::class,
                'permissions' => ['view' => 'issue.view', 'create' => 'issue.create', 'edit' => 'issue.edit', 'delete' => 'issue.delete'],
                'reference' => ['column' => 'issue_reference', 'prefix' => 'ISS'],
                'fields' => [
                    'issue_reference', 'title', 'description', 'issue_source', 'issue_category',
                    'priority', 'issue_status', 'responsible_owner_id', 'business_unit_id',
                    'regulatory_reportable', 'cbn_reportable', 'remediation_due_date',
                    'actual_close_date', 'potential_loss_kobo', 'actual_loss_kobo',
                    'created_at', 'updated_at',
                ],
                'filters' => ['issue_status', 'priority', 'issue_category', 'issue_source', 'responsible_owner_id', 'regulatory_reportable', 'cbn_reportable'],
                'sorts' => ['issue_reference', 'priority', 'remediation_due_date', 'created_at'],
                'includes' => ['issueOwner', 'businessUnit'],
                'writable' => ['title', 'description', 'issue_source', 'issue_category', 'priority', 'issue_status', 'responsible_owner_id', 'remediation_due_date'],
            ],

            'loss-events' => [
                'model' => LossEvent::class,
                'permissions' => ['view' => 'loss_event.view', 'create' => 'loss_event.create', 'edit' => 'loss_event.edit', 'delete' => 'loss_event.delete'],
                'reference' => ['column' => 'event_reference', 'prefix' => 'LE'],
                'fields' => [
                    'event_reference', 'title', 'description', 'date_of_loss', 'date_discovered',
                    'date_reported', 'basel_l1_category', 'basel_l2_category', 'cbn_risk_category',
                    'gross_loss_amount_kobo', 'insurance_recovery_kobo', 'other_recovery_kobo',
                    'actual_recovery_kobo', 'pending_recovery_kobo', 'loss_category',
                    'event_severity', 'current_status', 'cbn_reportable', 'created_at', 'updated_at',
                ],
                'filters' => ['current_status', 'basel_l1_category', 'event_severity', 'loss_category', 'cbn_reportable'],
                'sorts' => ['event_reference', 'date_of_loss', 'gross_loss_amount_kobo', 'created_at'],
                'includes' => [],
                'writable' => ['title', 'description', 'date_of_loss', 'date_discovered', 'basel_l1_category', 'basel_l2_category', 'gross_loss_amount_kobo', 'loss_category', 'event_severity'],
            ],

            'near-misses' => [
                'model' => NearMiss::class,
                'permissions' => ['view' => 'loss_event.view', 'create' => 'loss_event.create', 'edit' => 'loss_event.edit'],
                'reference' => ['column' => 'reference', 'prefix' => 'NM'],
                'fields' => ['reference', 'title', 'description', 'status', 'potential_loss_kobo', 'created_at'],
                'filters' => ['status'],
                'sorts' => ['reference', 'created_at'],
                'includes' => [],
                'writable' => ['title', 'description', 'status', 'potential_loss_kobo'],
            ],

            'treatments' => [
                'model' => TreatmentPlan::class,
                'permissions' => ['view' => 'treatment.view', 'create' => 'treatment.create', 'edit' => 'treatment.edit', 'delete' => 'treatment.delete'],
                'reference' => ['column' => 'treatment_code', 'prefix' => 'TP'],
                // Canonical columns only (WP-01): action_title, strategy,
                // target_date, cost_estimate_ngn. The 200038 duplicates are not
                // published, so the API does not have to change when they go.
                'fields' => ['treatment_code', 'action_title', 'action_description', 'strategy', 'status', 'owner_id', 'risk_id', 'target_date', 'completion_date', 'cost_estimate_ngn', 'actual_cost_ngn', 'progress_pct', 'created_at', 'updated_at'],
                'filters' => ['status', 'strategy', 'owner_id', 'risk_id'],
                'sorts' => ['treatment_code', 'target_date', 'progress_pct', 'created_at'],
                'includes' => ['risk', 'owner'],
                'writable' => ['action_title', 'action_description', 'strategy', 'status', 'owner_id', 'risk_id', 'target_date', 'cost_estimate_ngn', 'progress_pct'],
            ],

            'assessments' => [
                'model' => RiskAssessment::class,
                'permissions' => ['view' => 'assessment.view', 'create' => 'assessment.create', 'edit' => 'assessment.create'],
                'fields' => ['risk_id', 'assessment_date', 'assessment_type', 'status', 'assessor_id', 'reviewer_id', 'likelihood_score', 'impact_score', 'overall_score', 'overall_rating', 'residual_score', 'residual_rating', 'approved_by', 'approved_date', 'created_at'],
                'filters' => ['status', 'assessment_type', 'risk_id', 'assessor_id', 'reviewer_id'],
                'sorts' => ['assessment_date', 'overall_score', 'created_at'],
                'includes' => ['risk'],
                'writable' => ['risk_id', 'assessment_date', 'assessment_type', 'likelihood_score', 'impact_score', 'residual_likelihood', 'residual_impact', 'assessor_id', 'reviewer_id'],
            ],

            'campaigns' => [
                'model' => AssessmentCampaign::class,
                'permissions' => ['view' => 'campaign.view', 'create' => 'campaign.create', 'edit' => 'campaign.manage'],
                'reference' => ['column' => 'campaign_code', 'prefix' => 'CAM'],
                'fields' => ['campaign_code', 'name', 'description', 'status', 'start_date', 'end_date', 'completion_pct', 'created_at'],
                'filters' => ['status'],
                'sorts' => ['campaign_code', 'start_date', 'completion_pct'],
                'includes' => [],
                'writable' => ['name', 'description', 'status', 'start_date', 'end_date'],
            ],

            'control-tests' => [
                'model' => ControlTest::class,
                'permissions' => ['view' => 'control_test.view', 'create' => 'control_test.create', 'edit' => 'control_test.edit'],
                'reference' => ['column' => 'test_code', 'prefix' => 'CT'],
                'fields' => ['test_code', 'title', 'control_id', 'test_type', 'tester_id', 'reviewer_id', 'scheduled_date', 'completed_date', 'status', 'result', 'score', 'created_at'],
                'filters' => ['status', 'result', 'control_id', 'tester_id', 'reviewer_id'],
                'sorts' => ['test_code', 'scheduled_date', 'score'],
                'includes' => ['control'],
                'writable' => ['title', 'control_id', 'test_type', 'tester_id', 'reviewer_id', 'scheduled_date'],
            ],

            'appetites' => [
                'model' => RiskAppetite::class,
                'permissions' => ['view' => 'appetite.view', 'create' => 'appetite.manage', 'edit' => 'appetite.manage'],
                'fields' => ['risk_category_id', 'appetite_level', 'appetite_type', 'appetite_statement', 'tolerance_metric', 'max_tolerance', 'target_min', 'target_max', 'capacity', 'current_position', 'unit_of_measure', 'effective_date', 'expiry_date', 'approved_by', 'approved_date'],
                'filters' => ['risk_category_id', 'appetite_level', 'appetite_type'],
                'sorts' => ['effective_date', 'appetite_level'],
                'includes' => ['riskCategory'],
                'writable' => ['risk_category_id', 'appetite_level', 'appetite_statement', 'tolerance_metric', 'max_tolerance', 'target_min', 'target_max', 'effective_date', 'expiry_date'],
            ],

            'scenarios' => [
                'model' => QuantificationScenario::class,
                'permissions' => ['view' => 'quantification.view', 'create' => 'quantification.create', 'edit' => 'quantification.create'],
                'reference' => ['column' => 'scenario_reference', 'prefix' => 'SCN'],
                'fields' => ['scenario_reference', 'name', 'description', 'status', 'cbn_risk_category', 'frequency_lambda', 'severity_mu', 'severity_sigma', 'severity_min_kobo', 'severity_max_kobo', 'created_at'],
                'filters' => ['status', 'cbn_risk_category'],
                'sorts' => ['scenario_reference', 'name', 'created_at'],
                'includes' => [],
                'writable' => ['name', 'description', 'status', 'cbn_risk_category', 'frequency_lambda', 'severity_mu', 'severity_sigma'],
            ],

            'simulations' => [
                'model' => SimulationRun::class,
                'permissions' => ['view' => 'quantification.view'],
                'fields' => ['simulation_reference', 'status', 'progress', 'iterations', 'horizon_years', 'scenario_ids', 'random_seed', 'started_at', 'completed_at', 'runtime_seconds', 'error_message', 'created_at'],
                'filters' => ['status'],
                'sorts' => ['simulation_reference', 'created_at', 'completed_at'],
                'includes' => [],
                'writable' => [],
            ],

            'periods' => [
                'model' => Period::class,
                'permissions' => ['view' => 'period.view'],
                'fields' => ['code', 'name', 'type', 'start_date', 'end_date', 'is_closed', 'closed_at', 'parent_period_id'],
                'filters' => ['type', 'is_closed', 'calendar_id'],
                'sorts' => ['start_date', 'code'],
                'includes' => [],
                'writable' => [],
            ],

            'categories' => [
                'model' => RiskCategory::class,
                'permissions' => ['view' => 'risk.view', 'create' => 'risk.admin', 'edit' => 'risk.admin'],
                'fields' => ['code', 'name', 'description', 'parent_id'],
                'filters' => ['parent_id'],
                'sorts' => ['code', 'name'],
                'includes' => [],
                'writable' => ['name', 'description', 'parent_id'],
            ],

            'object-types' => [
                'model' => ObjectType::class,
                'permissions' => ['view' => 'risk.view'],
                'fields' => ['code', 'name', 'plural_name', 'description', 'category', 'icon', 'color', 'is_system', 'is_node_type', 'code_prefix'],
                'filters' => ['category', 'is_system', 'is_node_type'],
                'sorts' => ['code', 'name', 'sort_order'],
                'includes' => [],
                'writable' => [],
            ],

            'objects' => [
                'model' => GraphObject::class,
                'permissions' => ['view' => 'risk.view', 'create' => 'risk.create', 'edit' => 'risk.edit'],
                'fields' => ['object_type_id', 'node_id', 'parent_id', 'code', 'name', 'description', 'owner_id', 'lifecycle_state', 'status', 'hierarchy_path', 'hierarchy_depth', 'effective_from', 'effective_to', 'source_model_type', 'source_model_id', 'created_at', 'updated_at'],
                'filters' => ['object_type_id', 'node_id', 'parent_id', 'lifecycle_state', 'status', 'owner_id', 'code'],
                'sorts' => ['code', 'name', 'hierarchy_path', 'created_at'],
                'includes' => ['objectType', 'owner'],
                'writable' => ['name', 'description', 'owner_id', 'lifecycle_state', 'status', 'parent_id', 'node_id'],
            ],

            'workflows' => [
                'model' => WorkflowDefinition::class,
                'permissions' => ['view' => 'workflow.view'],
                'fields' => ['code', 'version', 'name', 'description', 'entity_type', 'trigger', 'is_active', 'is_published', 'published_at', 'created_at'],
                'filters' => ['code', 'entity_type', 'trigger', 'is_published', 'is_active'],
                'sorts' => ['code', 'version', 'created_at'],
                'includes' => [],
                'writable' => [],
            ],

            'workflow-instances' => [
                'model' => WorkflowInstance::class,
                'permissions' => ['view' => 'workflow.view'],
                'fields' => ['definition_id', 'definition_version', 'entity_type', 'entity_id', 'status', 'outcome', 'current_nodes', 'started_at', 'completed_at', 'sla_due_at', 'breached_at'],
                'filters' => ['status', 'entity_type', 'entity_id', 'definition_id'],
                'sorts' => ['started_at', 'completed_at'],
                'includes' => ['definition'],
                'writable' => [],
            ],

            'tasks' => [
                'model' => WorkflowTask::class,
                'permissions' => ['view' => 'task.view'],
                'fields' => ['instance_id', 'node_code', 'node_name', 'node_type', 'assignee_id', 'assignee_role', 'status', 'due_at', 'escalated_at', 'completed_at', 'outcome', 'comments', 'created_at'],
                'filters' => ['status', 'assignee_id', 'assignee_role', 'node_code', 'instance_id'],
                'sorts' => ['due_at', 'created_at'],
                'includes' => ['instance'],
                'writable' => [],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $name): ?array
    {
        return static::all()[$name] ?? null;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(static::all());
    }
}

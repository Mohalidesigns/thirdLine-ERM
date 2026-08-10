<?php

namespace App\Support;

use App\Models\ApprovalRequest;
use App\Models\AssessmentCampaign;
use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Connector;
use App\Models\ConnectorRun;
use App\Models\Control;
use App\Models\ControlTest;
use App\Models\Entity;
use App\Models\FxRate;
use App\Models\GraphObject;
use App\Models\IcaapAssessment;
use App\Models\Issue;
use App\Models\IssueRemediationAction;
use App\Models\JobRun;
use App\Models\KeyRiskIndicator;
use App\Models\KriMeasurement;
use App\Models\LossEvent;
use App\Models\LossEventRca;
use App\Models\Measure;
use App\Models\MeasureBreach;
use App\Models\MeasureThreshold;
use App\Models\MeasureValue;
use App\Models\NearMiss;
use App\Models\Organization;
use App\Models\Period;
use App\Models\QuantificationScenario;
use App\Models\Questionnaire;
use App\Models\Risk;
use App\Models\RiskAppetite;
use App\Models\RiskAssessment;
use App\Models\RiskCategory;
use App\Models\SimulationRun;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;

/**
 * The single naming authority for polymorphic entity types.
 *
 * Four tables record "which kind of thing is this row about" as a string:
 * risk_audit_trail.entity_type, approval_requests.entity_type,
 * workflow_instances.entity_type and the entity_type key inside
 * notifications_log.metadata. Before WP-01 they disagreed:
 *
 *   AuditTrailService wrote class_basename()        -> "Risk"
 *   Risk::auditTrail() queried                       -> "risk"
 *   RiskRegisterController wrote                     -> "risk"
 *   WorkflowController wrote                         -> "App\Models\Risk"
 *
 * So $risk->auditTrail was empty for every change the service recorded — the
 * audit trail silently returned nothing, which on a compliance platform is
 * indistinguishable from "nothing ever happened".
 *
 * The aliases below are snake_case because that is what the existing
 * controllers and the workflow definitions already write, and because a stored
 * FQCN would tie years of audit history to a namespace that must stay free to
 * change.
 */
class MorphTypes
{
    /**
     * alias => model class.
     *
     * Registered with Relation::enforceMorphMap(), so a model that is *not*
     * listed here throws the moment anything asks for its morph class rather
     * than quietly writing an FQCN that no query will ever match again.
     * Adding a model to an audited or approvable flow means adding it here.
     *
     * @return array<string, class-string>
     */
    public static function map(): array
    {
        return [
            'approval_request' => ApprovalRequest::class,
            'assessment_campaign' => AssessmentCampaign::class,
            'business_process' => BusinessProcess::class,
            'business_unit' => BusinessUnit::class,
            // WP-07. The integration surface is audited and job-tracked, so
            // these need aliases too: a job_runs row names its subject, and a
            // connector run is something the audit trail records.
            'connector' => Connector::class,
            'connector_run' => ConnectorRun::class,
            'control' => Control::class,
            'control_test' => ControlTest::class,
            'entity' => Entity::class,
            'fx_rate' => FxRate::class,
            // WP-06. A workflow may run over a graph object of a type a
            // configurer invented — a Policy, an Obligation — which has no
            // table of its own, so `objects` is the subject.
            'graph_object' => GraphObject::class,
            'icaap_assessment' => IcaapAssessment::class,
            'issue' => Issue::class,
            'job_run' => JobRun::class,
            'issue_remediation_action' => IssueRemediationAction::class,
            'key_risk_indicator' => KeyRiskIndicator::class,
            'kri_measurement' => KriMeasurement::class,
            'loss_event' => LossEvent::class,
            'loss_event_rca' => LossEventRca::class,
            // WP-04. The measure engine is audited and approvable — a period
            // close, a breach acknowledgement and a threshold re-baselining all
            // write to the audit trail or the approval queue, and both resolve
            // the entity through this map.
            'measure' => Measure::class,
            'measure_breach' => MeasureBreach::class,
            'measure_threshold' => MeasureThreshold::class,
            'measure_value' => MeasureValue::class,
            'near_miss' => NearMiss::class,
            'organization' => Organization::class,
            'period' => Period::class,
            'quantification_scenario' => QuantificationScenario::class,
            'questionnaire' => Questionnaire::class,
            'risk' => Risk::class,
            'risk_appetite' => RiskAppetite::class,
            'risk_assessment' => RiskAssessment::class,
            'risk_category' => RiskCategory::class,
            'simulation_run' => SimulationRun::class,
            'treatment_plan' => TreatmentPlan::class,
            'user' => User::class,
            'webhook_delivery' => WebhookDelivery::class,
            'webhook_subscription' => WebhookSubscription::class,
            'workflow_definition' => WorkflowDefinition::class,
            'workflow_instance' => WorkflowInstance::class,
            'workflow_task' => WorkflowTask::class,
        ];
    }

    /**
     * The alias for a model class, or null if it is not mapped.
     */
    public static function aliasFor(string $class): ?string
    {
        return array_search(ltrim($class, '\\'), self::map(), true) ?: null;
    }

    /**
     * Normalise any historic representation to the canonical alias.
     *
     * Accepts the alias itself, an FQCN ("App\Models\Risk") and a bare class
     * basename ("Risk"), because all three are sitting in the four tables
     * today. Returns null when nothing matches, so callers can decide whether
     * an unrecognised value is worth failing over.
     */
    public static function normalise(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $map = self::map();

        if (isset($map[$value])) {
            return $value;
        }

        if ($alias = self::aliasFor($value)) {
            return $alias;
        }

        // Bare basename, e.g. "RiskAssessment".
        foreach ($map as $alias => $class) {
            if (class_basename($class) === $value) {
                return $alias;
            }
        }

        return null;
    }

    /**
     * Every representation of an entity type that may be sitting in the
     * database, for use in a `whereIn` when reading a historic column.
     *
     * risk_audit_trail cannot be normalised in place — it is append-only and
     * hash-chained, with entity_type inside the digest, so rewriting it would
     * mean re-sealing the chain and destroying the tamper-evidence it exists to
     * provide. Reads against that table therefore have to accept the three
     * spellings the application wrote over its history:
     *
     *   the alias        "treatment_plan"     (canonical, and what is written now)
     *   the basename     "TreatmentPlan"      (AuditTrailService before the morph map)
     *   the FQCN         "App\Models\TreatmentPlan"  (WorkflowController)
     *
     * The set is bounded and shrinking in relative terms: Relation::enforceMorphMap()
     * means nothing new is written in the legacy spellings.
     *
     * @param  string  $alias  a canonical alias, or any spelling of one
     * @return list<string>
     */
    public static function spellingsFor(string $alias): array
    {
        $canonical = self::normalise($alias);

        if ($canonical === null) {
            // Unknown type: match only what was asked for, rather than
            // widening a query to something we cannot account for.
            return [$alias];
        }

        $class = self::map()[$canonical];

        return array_values(array_unique([
            $canonical,
            class_basename($class),
            $class,
            '\\'.$class,
        ]));
    }
}

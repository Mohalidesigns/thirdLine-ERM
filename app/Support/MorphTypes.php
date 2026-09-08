<?php

namespace App\Support;

use App\Models\ApprovalRequest;
use App\Models\AssessmentCampaign;
use App\Models\Bcms\Application as BcmsApplication;
use App\Models\Bcms\DataSet as BcmsDataSet;
use App\Models\Bcms\Equipment as BcmsEquipment;
use App\Models\Bcms\ExerciseOccurrence as BcmsExerciseOccurrence;
use App\Models\Bcms\Process as BcmsProcess;
use App\Models\Bcms\Programme as BcmsProgramme;
use App\Models\Bcms\Site as BcmsSite;
use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Connector;
use App\Models\ConnectorRun;
use App\Models\Control;
use App\Models\ControlTest;
use App\Models\DataImport;
use App\Models\EmergingRisk;
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
use App\Models\ObjectType;
use App\Models\Organization;
use App\Models\Period;
use App\Models\QuantificationScenario;
use App\Models\Questionnaire;
use App\Models\Rcsa\RcsaActionPlan;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaCycle;
use App\Models\Rcsa\RcsaExportJob;
use App\Models\Rcsa\RcsaImportBatch;
use App\Models\Rcsa\RcsaMethodology;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Models\Risk;
use App\Models\RiskAppetite;
use App\Models\RiskAssessment;
use App\Models\RiskCategory;
use App\Models\SimulationRun;
use App\Models\Tprm\ThirdParty;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use ThirdLine\Platform\Licensing\Models\LicenseAuditLog;
use ThirdLine\Platform\Licensing\Models\LicenseStore;

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
            /*
             * BCMS. The seven types a `bcms_dependencies` row may point at
             * (ADR 0002) live in THIS map rather than in a BCMS-local one,
             * because `Relation::enforceMorphMap()` takes one map for the whole
             * application and a second would silently replace the first.
             *
             * Blueprint §9.1 names the keys `applications`, `vendors`, `sites`,
             * `users`, `equipment`, `data_sets` and `processes`. Those are the
             * enum CASE names in `App\Enums\Bcms\DependencyType`; the stored
             * strings are these, because this map's convention is singular
             * snake_case and `users` would be a second alias for a class that
             * already has one — `getMorphClass()` returns the first match, so
             * two aliases for one class is a coin toss written into customer
             * data. `bcms_process` is prefixed for the same reason:
             * `business_process` is already taken and means something else.
             */
            'bcms_application' => BcmsApplication::class,
            'bcms_data_set' => BcmsDataSet::class,
            'bcms_equipment' => BcmsEquipment::class,
            'bcms_process' => BcmsProcess::class,
            // Phase 4: a reschedule request is an `ApprovalRequest` over an
            // occurrence, and `enforceMorphMap()` refuses a model that has no
            // alias — so the approvals engine cannot route one without this.
            'bcms_exercise_occurrence' => BcmsExerciseOccurrence::class,
            // Phase 1 (ADR 0008): RACI assignments and programme scope rows are
            // morphs over a programme as well as a process.
            'bcms_programme' => BcmsProgramme::class,
            'bcms_site' => BcmsSite::class,
            'tprm_third_party' => ThirdParty::class,
            'business_process' => BusinessProcess::class,
            'business_unit' => BusinessUnit::class,
            // WP-07. The integration surface is audited and job-tracked, so
            // these need aliases too: a job_runs row names its subject, and a
            // connector run is something the audit trail records.
            'connector' => Connector::class,
            'connector_run' => ConnectorRun::class,
            'control' => Control::class,
            'control_test' => ControlTest::class,
            // Phase 5.5. A JobRun's subject is stored as a morph, and
            // ProcessDataImportJob::track() passes the DataImport — so without
            // an alias here `getMorphClass()` threw ClassMorphViolationException
            // and the import could not be queued at all. Like `simulation_run`,
            // this is a job-subject alias; the model is not in the object graph.
            'data_import' => DataImport::class,
            // Phase 4.6 — the horizon joined the graph; every mirrored model
            // needs a canonical alias, which ObjectIdentityTest enforces.
            'emerging_risk' => EmergingRisk::class,
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
            // Migration Phase 0: the licensing client's two tables. Neither is
            // audited today, but enforceMorphMap makes any unmapped model fatal
            // the moment something asks for its morph class.
            'license_audit_log' => LicenseAuditLog::class,
            'license_store' => LicenseStore::class,
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
            // ObjectType is exposed by ApiResourceRegistry, and
            // Relation::enforceMorphMap() makes any unmapped model fatal the
            // moment something calls getMorphClass() on it. WebhookController
            // ::availableEvents() does exactly that for every registry model,
            // so /admin/webhooks threw ClassMorphViolationException for every
            // user until this line existed.
            'object_type' => ObjectType::class,
            'organization' => Organization::class,
            'period' => Period::class,
            'quantification_scenario' => QuantificationScenario::class,
            'questionnaire' => Questionnaire::class,
            // RCSA v2 (P7). The aliases are what `risk_audit_trail.entity_type`
            // stores, and that column is 50 characters — every one of these is
            // comfortably inside it, which is worth checking whenever one is
            // added because SQLite never enforces the width and MySQL does.
            'rcsa_action_plan' => RcsaActionPlan::class,
            'rcsa_assessment' => RcsaAssessment::class,
            'rcsa_assessment_line' => RcsaAssessmentLine::class,
            'rcsa_cycle' => RcsaCycle::class,
            'rcsa_export_job' => RcsaExportJob::class,
            'rcsa_import_batch' => RcsaImportBatch::class,
            'rcsa_methodology' => RcsaMethodology::class,
            'rcsa_register_risk' => RcsaRegisterRisk::class,
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

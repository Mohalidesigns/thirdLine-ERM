<?php

namespace Tests\Unit\Support;

use App\Support\MorphTypes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An alias in MorphTypes::map() is a promise about data already written.
 *
 * `Relation::enforceMorphMap()` makes the alias the stored value in
 * model_has_roles, risk_audit_trail, approval_requests, bcms_dependencies and
 * the rest. When 26a7697 changed `User` from 'App\Models\User' to 'user', no
 * migration rewrote the existing rows and every user on a seeded server lost
 * their roles. Renaming, removing or re-pointing an alias that already exists
 * is the same defect run backwards: the rows keep the old string, and nothing
 * resolves it.
 *
 * So the pairs that exist today are pinned here, as a literal list. A NEW alias
 * can be added freely (it has no rows yet). Anything else fails this test.
 *
 * That each class has exactly one alias is asserted by
 * RewriteLegacyMorphClassNamesTest::every_morph_alias_belongs_to_exactly_one_class,
 * which is not repeated here.
 *
 * Plain PHPUnit TestCase: MorphTypes::map() touches no container.
 */
class MorphAliasesArePinnedTest extends TestCase
{
    /**
     * Written out by hand on purpose. Do not generate this from MorphTypes::map():
     * a pin that follows the thing it pins pins nothing.
     *
     * @var array<string, class-string>
     */
    private const PINNED = [
        'approval_request' => 'App\\Models\\ApprovalRequest',
        'assessment_campaign' => 'App\\Models\\AssessmentCampaign',
        'bcms_application' => 'App\\Models\\Bcms\\Application',
        'bcms_data_set' => 'App\\Models\\Bcms\\DataSet',
        'bcms_equipment' => 'App\\Models\\Bcms\\Equipment',
        'bcms_process' => 'App\\Models\\Bcms\\Process',
        'bcms_exercise_occurrence' => 'App\\Models\\Bcms\\ExerciseOccurrence',
        'bcms_programme' => 'App\\Models\\Bcms\\Programme',
        'bcms_site' => 'App\\Models\\Bcms\\Site',
        'tprm_third_party' => 'App\\Models\\Tprm\\ThirdParty',
        'tprm_document' => 'App\\Models\\Tprm\\Document',
        'business_process' => 'App\\Models\\BusinessProcess',
        'business_unit' => 'App\\Models\\BusinessUnit',
        'connector' => 'App\\Models\\Connector',
        'connector_run' => 'App\\Models\\ConnectorRun',
        'control' => 'App\\Models\\Control',
        'control_test' => 'App\\Models\\ControlTest',
        'data_import' => 'App\\Models\\DataImport',
        'emerging_risk' => 'App\\Models\\EmergingRisk',
        'entity' => 'App\\Models\\Entity',
        'fx_rate' => 'App\\Models\\FxRate',
        'graph_object' => 'App\\Models\\GraphObject',
        'icaap_assessment' => 'App\\Models\\IcaapAssessment',
        'issue' => 'App\\Models\\Issue',
        'job_run' => 'App\\Models\\JobRun',
        'issue_remediation_action' => 'App\\Models\\IssueRemediationAction',
        'key_risk_indicator' => 'App\\Models\\KeyRiskIndicator',
        'kri_measurement' => 'App\\Models\\KriMeasurement',
        'license_audit_log' => 'ThirdLine\\Platform\\Licensing\\Models\\LicenseAuditLog',
        'license_store' => 'ThirdLine\\Platform\\Licensing\\Models\\LicenseStore',
        'loss_event' => 'App\\Models\\LossEvent',
        'loss_event_rca' => 'App\\Models\\LossEventRca',
        'measure' => 'App\\Models\\Measure',
        'measure_breach' => 'App\\Models\\MeasureBreach',
        'measure_threshold' => 'App\\Models\\MeasureThreshold',
        'measure_value' => 'App\\Models\\MeasureValue',
        'near_miss' => 'App\\Models\\NearMiss',
        'object_type' => 'App\\Models\\ObjectType',
        'organization' => 'App\\Models\\Organization',
        'period' => 'App\\Models\\Period',
        'quantification_scenario' => 'App\\Models\\QuantificationScenario',
        'questionnaire' => 'App\\Models\\Questionnaire',
        'rcsa_action_plan' => 'App\\Models\\Rcsa\\RcsaActionPlan',
        'rcsa_assessment' => 'App\\Models\\Rcsa\\RcsaAssessment',
        'rcsa_assessment_line' => 'App\\Models\\Rcsa\\RcsaAssessmentLine',
        'rcsa_cycle' => 'App\\Models\\Rcsa\\RcsaCycle',
        'rcsa_export_job' => 'App\\Models\\Rcsa\\RcsaExportJob',
        'rcsa_import_batch' => 'App\\Models\\Rcsa\\RcsaImportBatch',
        'rcsa_methodology' => 'App\\Models\\Rcsa\\RcsaMethodology',
        'rcsa_register_risk' => 'App\\Models\\Rcsa\\RcsaRegisterRisk',
        'risk' => 'App\\Models\\Risk',
        'risk_appetite' => 'App\\Models\\RiskAppetite',
        'risk_assessment' => 'App\\Models\\RiskAssessment',
        'risk_category' => 'App\\Models\\RiskCategory',
        'simulation_run' => 'App\\Models\\SimulationRun',
        'treatment_plan' => 'App\\Models\\TreatmentPlan',
        'user' => 'App\\Models\\User',
        'webhook_delivery' => 'App\\Models\\WebhookDelivery',
        'webhook_subscription' => 'App\\Models\\WebhookSubscription',
        'workflow_definition' => 'App\\Models\\WorkflowDefinition',
        'workflow_instance' => 'App\\Models\\WorkflowInstance',
        'workflow_task' => 'App\\Models\\WorkflowTask',
    ];

    #[Test]
    public function no_existing_morph_alias_is_removed_renamed_or_re_pointed(): void
    {
        $map = MorphTypes::map();
        $problems = [];

        foreach (self::PINNED as $alias => $class) {
            if (! array_key_exists($alias, $map)) {
                $problems[] = "'{$alias}' (-> {$class}) is no longer in MorphTypes::map(). Removed, or renamed?";

                continue;
            }

            if ($map[$alias] !== $class) {
                $problems[] = "'{$alias}' pointed at {$class} and now points at {$map[$alias]}.";
            }
        }

        $this->assertSame([], $problems, $this->howToFix($problems));
    }

    /**
     * @param  list<string>  $problems
     */
    private function howToFix(array $problems): string
    {
        return "MorphTypes::map() changed an alias that already exists:\n  - ".implode("\n  - ", $problems)."\n\n"
            ."Rows written under the old alias still hold the old string, and nothing will resolve them\n"
            ."(this is how every user lost their roles in production). To make this change safely:\n"
            ."  1. Write a rewrite migration modelled on\n"
            ."     database/migrations/2026_10_03_100001_rewrite_legacy_morph_class_names_to_aliases.php\n"
            ."     that updates every morph column holding the old alias to the new one (and handles\n"
            ."     unique-key collisions the way that migration does).\n"
            ."  2. Only then update PINNED in tests/Unit/Support/MorphAliasesArePinnedTest.php.\n"
            .'If you did not mean to change an alias, revert your edit to MorphTypes::map().';
    }
}

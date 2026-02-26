<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comprehensive schema alignment migration.
 * Adds all columns referenced by controllers that are missing from the original migrations.
 * Uses hasColumn guards to be idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addColumns('treatment_plans', [
            ['string', 'treatment_code', 20, ['nullable' => true, 'unique' => true]],
            ['string', 'treatment_title', 255, ['nullable' => true]],
            ['text', 'treatment_description', null, ['nullable' => true]],
            ['string', 'treatment_type', 50, ['nullable' => true]],
            ['unsignedBigInteger', 'treatment_owner_id', null, ['nullable' => true]],
            ['date', 'target_completion_date', null, ['nullable' => true]],
            ['date', 'actual_completion_date', null, ['nullable' => true]],
            ['decimal', 'estimated_cost', [15, 2], ['nullable' => true]],
            ['decimal', 'actual_cost', [15, 2], ['nullable' => true]],
            ['unsignedTinyInteger', 'progress_percentage', null, ['default' => 0]],
            ['text', 'implementation_notes', null, ['nullable' => true]],
            ['text', 'milestones', null, ['nullable' => true]],
            ['text', 'success_criteria', null, ['nullable' => true]],
            ['unsignedTinyInteger', 'expected_residual_likelihood', null, ['nullable' => true]],
            ['unsignedTinyInteger', 'expected_residual_impact', null, ['nullable' => true]],
            ['unsignedBigInteger', 'approved_by', null, ['nullable' => true]],
            ['timestamp', 'approved_at', null, ['nullable' => true]],
            ['text', 'rejection_reason', null, ['nullable' => true]],
            ['unsignedBigInteger', 'updated_by', null, ['nullable' => true]],
        ]);

        $this->addColumns('key_risk_indicators', [
            ['unsignedBigInteger', 'risk_id', null, ['nullable' => true]],
            ['boolean', 'is_active', null, ['default' => true]],
            ['string', 'kri_name', 255, ['nullable' => true]],
            ['string', 'measurement_unit', 100, ['nullable' => true]],
            ['string', 'direction', 30, ['nullable' => true]],
            ['decimal', 'target_value', [15, 4], ['nullable' => true]],
            ['unsignedBigInteger', 'kri_owner_id', null, ['nullable' => true]],
            ['date', 'last_measurement_date', null, ['nullable' => true]],
        ]);

        $this->addColumns('loss_events', [
            ['string', 'event_reference', 30, ['nullable' => true]],
            ['string', 'event_title', 255, ['nullable' => true]],
            ['text', 'event_description', null, ['nullable' => true]],
            ['date', 'date_of_loss', null, ['nullable' => true]],
            ['date', 'date_discovered', null, ['nullable' => true]],
            ['string', 'status', 30, ['nullable' => true]],
            ['decimal', 'gross_loss_amount', [18, 2], ['nullable' => true]],
            ['decimal', 'recovery_amount', [18, 2], ['nullable' => true]],
            ['decimal', 'insurance_recovery', [18, 2], ['nullable' => true]],
            ['decimal', 'net_loss_amount', [18, 2], ['nullable' => true]],
            ['string', 'event_type', 50, ['nullable' => true]],
            ['string', 'severity', 30, ['nullable' => true]],
            ['string', 'basel_event_type', 100, ['nullable' => true]],
            ['string', 'cbn_loss_category', 255, ['nullable' => true]],
            ['string', 'currency', 3, ['default' => 'NGN']],
            ['boolean', 'is_regulatory_reportable', null, ['default' => false]],
            ['string', 'regulatory_body', 255, ['nullable' => true]],
            ['date', 'reporting_deadline', null, ['nullable' => true]],
            ['text', 'root_cause_summary', null, ['nullable' => true]],
            ['text', 'corrective_action_summary', null, ['nullable' => true]],
            ['unsignedBigInteger', 'reported_by', null, ['nullable' => true]],
            ['unsignedBigInteger', 'approved_by', null, ['nullable' => true]],
            ['timestamp', 'approved_at', null, ['nullable' => true]],
            ['timestamp', 'status_changed_at', null, ['nullable' => true]],
            ['unsignedBigInteger', 'status_changed_by', null, ['nullable' => true]],
            ['unsignedBigInteger', 'updated_by', null, ['nullable' => true]],
        ]);

        $this->addColumns('issues', [
            ['string', 'issue_reference', 30, ['nullable' => true]],
            ['string', 'issue_title', 255, ['nullable' => true]],
            ['text', 'issue_description', null, ['nullable' => true]],
            ['string', 'issue_status', 30, ['nullable' => true]],
            ['unsignedBigInteger', 'issue_owner_id', null, ['nullable' => true]],
            ['date', 'target_resolution_date', null, ['nullable' => true]],
            ['date', 'actual_resolution_date', null, ['nullable' => true]],
            ['text', 'root_cause', null, ['nullable' => true]],
            ['text', 'impact_description', null, ['nullable' => true]],
            ['text', 'recommended_action', null, ['nullable' => true]],
            ['string', 'source_reference', 255, ['nullable' => true]],
            ['unsignedTinyInteger', 'escalation_level', null, ['default' => 0]],
            ['unsignedTinyInteger', 'progress_percentage', null, ['default' => 0]],
            ['text', 'closure_justification', null, ['nullable' => true]],
            ['text', 'evidence_of_resolution', null, ['nullable' => true]],
            ['timestamp', 'closure_requested_at', null, ['nullable' => true]],
            ['unsignedBigInteger', 'closure_requested_by', null, ['nullable' => true]],
            ['text', 'closure_rejection_reason', null, ['nullable' => true]],
            ['timestamp', 'closure_rejected_at', null, ['nullable' => true]],
            ['unsignedBigInteger', 'closure_rejected_by', null, ['nullable' => true]],
            ['timestamp', 'closed_at', null, ['nullable' => true]],
            ['unsignedBigInteger', 'closed_by', null, ['nullable' => true]],
            ['timestamp', 'status_changed_at', null, ['nullable' => true]],
            ['unsignedBigInteger', 'status_changed_by', null, ['nullable' => true]],
            ['unsignedBigInteger', 'updated_by', null, ['nullable' => true]],
        ]);

        $this->addColumns('risk_assessments', [
            ['string', 'inherent_rating', 20, ['nullable' => true]],
        ]);

        $this->addColumns('risk_control_mapping', [
            ['unsignedBigInteger', 'organization_id', null, ['nullable' => true]],
        ]);

        $this->addColumns('risks', [
            ['string', 'risk_type', 50, ['nullable' => true]],
        ]);

        $this->addColumns('loss_event_rca', [
            ['unsignedBigInteger', 'organization_id', null, ['nullable' => true]],
            ['string', 'root_cause_category', 50, ['nullable' => true]],
            ['text', 'root_cause_description', null, ['nullable' => true]],
            ['text', 'contributing_factors_text', null, ['nullable' => true]],
            ['text', 'analysis_details', null, ['nullable' => true]],
            ['text', 'recommendations', null, ['nullable' => true]],
            ['text', 'lessons_learned', null, ['nullable' => true]],
            ['timestamp', 'analysis_date', null, ['nullable' => true]],
            ['unsignedBigInteger', 'performed_by', null, ['nullable' => true]],
            ['string', 'status', 20, ['nullable' => true]],
        ]);

        if (Schema::hasTable('near_misses')) {
            $this->addColumns('near_misses', [
                ['string', 'event_reference', 30, ['nullable' => true]],
            ]);
        }
    }

    public function down(): void
    {
        // Not implemented - this is a forward-only alignment migration
    }

    /**
     * Add columns to a table only if they don't already exist.
     */
    private function addColumns(string $table, array $columns): void
    {
        foreach ($columns as $col) {
            [$type, $name, $length, $options] = $col;

            if (Schema::hasColumn($table, $name)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($type, $name, $length, $options) {
                $column = match ($type) {
                    'string' => $t->string($name, $length ?? 255),
                    'text' => $t->text($name),
                    'boolean' => $t->boolean($name),
                    'date' => $t->date($name),
                    'timestamp' => $t->timestamp($name),
                    'unsignedBigInteger' => $t->unsignedBigInteger($name),
                    'unsignedTinyInteger' => $t->unsignedTinyInteger($name),
                    'decimal' => is_array($length)
                        ? $t->decimal($name, $length[0], $length[1])
                        : $t->decimal($name),
                };

                if (!empty($options['nullable'])) {
                    $column->nullable();
                }
                if (array_key_exists('default', $options)) {
                    $column->default($options['default']);
                }
            });
        }
    }
};

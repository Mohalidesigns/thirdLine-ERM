<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Incident Register tables:
     * - incidents (intake, classification, loss capture, regulatory countdown)
     * - incident_actions (containment, corrective, preventive, regulatory actions)
     * - incident_timeline (chronological narrative with material events)
     * - incident_notifications (statutory notifications owed with live countdowns)
     */
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', indexName: 'fk_incidents_org')->cascadeOnDelete();
            $table->string('incident_ref', 40);
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('description_rich')->nullable();
            $table->enum('incident_type', [
                'operational', 'security', 'cyber', 'fraud', 'compliance', 'data_breach',
                'health_safety', 'conduct', 'third_party', 'continuity', 'other',
            ])->default('operational');
            $table->enum('basel_event_type', [
                'Internal Fraud', 'External Fraud', 'Employment Practices',
                'Clients Products & Business Practices', 'Damage to Physical Assets',
                'Business Disruption & System Failures', 'Execution Delivery & Process Mgmt',
            ])->nullable();
            $table->enum('severity', ['Low', 'Medium', 'High', 'Critical'])->default('Medium');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->string('detection_method')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->foreignId('reported_by')->nullable()
                ->constrained('users', indexName: 'fk_incidents_reporter')->nullOnDelete();
            $table->foreignId('business_unit_id')->nullable()
                ->constrained('business_units', indexName: 'fk_incidents_unit')->nullOnDelete();
            $table->foreignId('process_id')->nullable()
                ->constrained('business_processes', indexName: 'fk_incidents_process')->nullOnDelete();
            $table->enum('status', [
                'Reported', 'Triaged', 'Under Investigation', 'Contained',
                'Remediated', 'Closed', 'Reopened',
            ])->default('Reported');

            // Minor units + currency code, never a float (R7)
            $table->bigInteger('gross_loss_minor')->default(0);
            $table->bigInteger('recovery_minor')->default(0);
            $table->bigInteger('net_loss_minor')->default(0);
            $table->char('currency', 3)->nullable();
            $table->boolean('near_miss')->default(false);

            $table->boolean('is_reportable')->default(false);
            $table->json('regulator_ids')->nullable();
            $table->timestamp('notification_due_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->string('notification_reference')->nullable();

            $table->text('root_cause')->nullable();
            $table->json('root_cause_rich')->nullable();
            $table->string('root_cause_category')->nullable();
            $table->json('contributing_factors')->nullable();
            $table->text('lessons_learned')->nullable();
            $table->json('lessons_learned_rich')->nullable();

            $table->foreignId('owner_id')->nullable()
                ->constrained('users', indexName: 'fk_incidents_owner')->nullOnDelete();
            $table->foreignId('investigator_id')->nullable()
                ->constrained('users', indexName: 'fk_incidents_investigator')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()
                ->constrained('users', indexName: 'fk_incidents_closer')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            $table->json('control_failure_ids')->nullable();
            $table->json('risk_ids')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'incident_ref'], 'uniq_incidents_org_ref');
            $table->index('organization_id');
            $table->index('business_unit_id');
            $table->index('process_id');
            $table->index('owner_id');
            $table->index('investigator_id');
            $table->index('reported_by');
            $table->index('closed_by');
            $table->index('status');
            $table->index('severity');
            $table->index('incident_type');
            $table->index('near_miss');
            $table->index('is_reportable');
            $table->index('occurred_at');
            $table->index('notification_due_at');
        });

        Schema::create('incident_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', indexName: 'fk_incident_actions_org')->cascadeOnDelete();
            $table->foreignId('incident_id')
                ->constrained('incidents', indexName: 'fk_incident_actions_incident')->cascadeOnDelete();
            $table->enum('action_type', [
                'containment', 'corrective', 'preventive', 'communication', 'regulatory',
            ])->default('corrective');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()
                ->constrained('users', indexName: 'fk_incident_actions_owner')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->enum('status', ['Open', 'In Progress', 'Completed', 'Verified', 'Cancelled'])
                ->default('Open');
            $table->boolean('is_mandatory')->default(true);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('verified_by')->nullable()
                ->constrained('users', indexName: 'fk_incident_actions_verifier')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('evidence_id')->nullable();
            $table->timestamps();

            $table->index('organization_id');
            $table->index('incident_id');
            $table->index('owner_id');
            $table->index('verified_by');
            $table->index('status');
            $table->index('due_at');
        });

        Schema::create('incident_timeline', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', indexName: 'fk_incident_timeline_org')->cascadeOnDelete();
            $table->foreignId('incident_id')
                ->constrained('incidents', indexName: 'fk_incident_timeline_incident')->cascadeOnDelete();
            $table->dateTime('occurred_at');
            $table->foreignId('actor_id')->nullable()
                ->constrained('users', indexName: 'fk_incident_timeline_actor')->nullOnDelete();
            $table->string('event_type', 60);
            $table->text('description');
            $table->boolean('is_material')->default(false);
            $table->timestamps();

            $table->index('organization_id');
            $table->index('incident_id');
            $table->index('actor_id');
            $table->index(['incident_id', 'occurred_at'], 'idx_incident_timeline_order');
        });

        Schema::create('incident_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', indexName: 'fk_incident_notifications_org')->cascadeOnDelete();
            $table->foreignId('incident_id')
                ->constrained('incidents', indexName: 'fk_incident_notifications_incident')->cascadeOnDelete();
            $table->unsignedBigInteger('obligation_id')->nullable();
            $table->string('obligation_title')->nullable();
            $table->string('obligation_ref', 80)->nullable();
            $table->string('legal_reference')->nullable();
            $table->unsignedBigInteger('regulator_id')->nullable();
            $table->string('regulator_name')->nullable();
            $table->string('regulator_code', 40)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->enum('status', ['Pending', 'Notified', 'Not Required', 'Waived'])->default('Pending');
            $table->boolean('is_required')->default(true);
            $table->timestamp('notified_at')->nullable();
            $table->string('notification_reference')->nullable();
            $table->foreignId('notified_by')->nullable()
                ->constrained('users', indexName: 'fk_incident_notifications_notifier')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('verification_status', 20)->default('verified');
            $table->timestamps();

            $table->index('organization_id');
            $table->index('incident_id');
            $table->index('notified_by');
            $table->index('status');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_notifications');
        Schema::dropIfExists('incident_timeline');
        Schema::dropIfExists('incident_actions');
        Schema::dropIfExists('incidents');
    }
};

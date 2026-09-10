<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BCMS Phase 0, part 5 of 8 — incidents and crisis management, IT disaster
 * recovery, training and competency (Blueprint §9.5).
 *
 * `bcms_dr_systems.application_id` points at `bcms_applications`, not at an
 * Enterprise Architecture module, because this product has no EA module
 * (ADR 0001). It is one of the four named seams.
 *
 * The CBN clock columns on `bcms_incidents` are not decoration. The CBN
 * Risk-Based Cybersecurity Framework requires reporting inside a stated window
 * from detection, and an incident register that records only "reported: yes"
 * cannot answer the one question an examiner asks about a reportable incident,
 * which is when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bcms_incidents', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40);
            $table->string('title', 250);
            $table->string('incident_type', 40)->nullable(); // cyber|power|flood|fire|civil_unrest|supplier|pandemic|system|other
            $table->string('severity', 20)->nullable();      // sev1..sev4
            $table->foreignId('business_unit_id')->nullable()->constrained('business_units')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('bcms_sites')->nullOnDelete();

            // Detection and declaration are different moments and both matter.
            // The regulatory clock runs from detection; the crisis team's
            // response time runs from declaration.
            $table->timestamp('detected_at')->nullable();
            $table->foreignId('declared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('declared_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->string('status', 20)->default('open'); // open|contained|recovering|closed|cancelled
            $table->string('activation_level', 20)->nullable(); // monitor|standby|partial|full
            $table->json('impacted_processes')->nullable();
            $table->bigInteger('estimated_impact_minor')->nullable();
            $table->string('currency', 3)->nullable();

            // An EXERCISE incident. Standing rule 5: exercise-linked traffic is
            // simulation by default and carries the "THIS IS AN EXERCISE"
            // prefix. Without this flag a drill's incident record pollutes the
            // loss history a regulator reads.
            $table->boolean('is_exercise')->default(false);
            $table->foreignId('occurrence_id')->nullable()
                ->constrained('bcms_exercise_occurrences')->nullOnDelete();

            $table->boolean('is_reportable')->default(false);
            $table->timestamp('regulator_notified_at')->nullable();
            $table->string('cbn_reference', 80)->nullable();
            $table->timestamp('reporting_due_at')->nullable();

            // The ERM bridge: an incident with a realised loss becomes a loss
            // event in the register. One-way (ADR 0001).
            $table->foreignId('erm_loss_event_id')->nullable()->constrained('loss_events')->nullOnDelete();

            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status', 'severity']);
            $table->index(['organization_id', 'declared_at']);
        });

        Schema::create('bcms_incident_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('bcms_incidents')->cascadeOnDelete();
            $table->timestamp('logged_at');
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entry_type', 30); // decision|action|communication|situation_report|escalation
            $table->longText('content');
            $table->json('attachments')->nullable();

            // The decision log is the artefact ISO 22361 asks for and it is
            // only evidence if it cannot be rewritten. Edits append; this
            // column records that an entry supersedes an earlier one rather
            // than replacing it.
            $table->foreignId('supersedes_entry_id')->nullable()
                ->constrained('bcms_incident_log')->nullOnDelete();

            $table->timestamps();

            $table->index(['organization_id', 'incident_id', 'logged_at']);
        });

        Schema::create('bcms_incident_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('bcms_incidents')->cascadeOnDelete();
            $table->string('title', 250);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('due_at')->nullable();
            $table->string('priority', 20)->nullable();
            $table->string('status', 20)->default('open'); // open|in_progress|complete|cancelled
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'incident_id', 'status']);
        });

        /* ------------------------------------------------------------------ */
        /*  IT disaster recovery. We GOVERN and EVIDENCE failover; we do not */
        /*  execute it (Blueprint §3.3). These tables hold targets, runbook */
        /*  references and test results, never orchestration. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_dr_systems', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('bcms_applications')->nullOnDelete();
            $table->string('name', 200);
            $table->unsignedTinyInteger('recovery_tier')->nullable(); // 1 = most critical

            $table->decimal('rto_target_hours', 8, 2)->nullable();
            $table->unsignedInteger('rpo_target_minutes')->nullable();
            $table->string('dr_strategy', 40)->nullable(); // hot|warm|cold|active_active|backup_restore|none
            $table->foreignId('dr_site_id')->nullable()->constrained('bcms_sites')->nullOnDelete();
            $table->foreignId('failover_runbook_plan_id')->nullable()->constrained('bcms_plans')->nullOnDelete();

            // LAST TEST RESULT, denormalised onto the system, because the DR
            // register screen is "which systems are overdue and which missed
            // their target", and computing that per row across the test table
            // is the N+1 a reviewer would reject. Written only by the test
            // service when a test is recorded.
            $table->date('last_test_date')->nullable();
            $table->date('next_test_due')->nullable();
            $table->unsignedInteger('last_test_rto_actual_minutes')->nullable();
            $table->unsignedInteger('last_test_rpo_actual_minutes')->nullable();
            $table->boolean('last_test_met_objectives')->nullable();

            $table->string('backup_frequency', 40)->nullable();
            $table->string('replication_type', 40)->nullable();
            $table->timestamp('last_backup_verified_at')->nullable();

            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'recovery_tier']);
            $table->index(['organization_id', 'next_test_due']);
        });

        Schema::create('bcms_dr_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dr_system_id')->constrained('bcms_dr_systems')->cascadeOnDelete();

            // Nullable: a DR test recorded from a vendor's own report has no
            // occurrence. It still counts as evidence, and it still has to be
            // possible to record it, or customers keep it in the spreadsheet
            // this module is supposed to replace.
            $table->foreignId('occurrence_id')->nullable()
                ->constrained('bcms_exercise_occurrences')->nullOnDelete();

            $table->string('test_type', 30); // failover|failback|backup_restore|tabletop|component
            $table->date('test_date');
            $table->unsignedInteger('rto_actual_minutes')->nullable();
            $table->unsignedInteger('rpo_actual_minutes')->nullable();
            $table->boolean('met_objectives')->nullable();
            $table->boolean('rollback_required')->default(false);
            $table->json('issues')->nullable();
            $table->json('evidence')->nullable();
            $table->text('notes')->nullable();
            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'dr_system_id', 'test_date']);
        });

        /* ------------------------------------------------------------------ */
        /*  Training and competency — clause 7.2 is a MANDATORY RECORD. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_training_curricula', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->json('target_roles')->nullable();
            $table->json('modules')->nullable();
            $table->unsignedSmallInteger('frequency_months')->default(12);
            $table->boolean('is_mandatory')->default(false);

            // Clause 7.2 distinguishes awareness from COMPETENCE. A curriculum
            // that asserts competence must say how it is assessed, or the
            // record proves attendance and nothing else.
            $table->boolean('requires_assessment')->default(false);
            $table->unsignedTinyInteger('pass_mark')->nullable();

            $table->boolean('is_system_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('bcms_training_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_id')->constrained('bcms_training_curricula')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->boolean('competency_assessed')->default(false);
            $table->foreignId('assessor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('next_due_date')->nullable();
            $table->unsignedBigInteger('certificate_id')->nullable();

            // Attendance at a drill IS training evidence under 7.3, and linking
            // it here is what stops a customer maintaining two registers.
            $table->foreignId('occurrence_id')->nullable()
                ->constrained('bcms_exercise_occurrences')->nullOnDelete();

            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'user_id', 'next_due_date'], 'bcms_training_org_user_due_idx');
            $table->index(['organization_id', 'curriculum_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bcms_training_records');
        Schema::dropIfExists('bcms_training_curricula');
        Schema::dropIfExists('bcms_dr_tests');
        Schema::dropIfExists('bcms_dr_systems');
        Schema::dropIfExists('bcms_incident_tasks');
        Schema::dropIfExists('bcms_incident_log');
        Schema::dropIfExists('bcms_incidents');
    }
};

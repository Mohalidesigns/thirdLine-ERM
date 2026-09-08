<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BCMS Phase 0, part 4 of 8 — findings and corrective actions
 * (Blueprint §9.3, ISO 22301 clause 10.1).
 *
 * This is the most-consumed contract in the module. Orchestration §5 makes
 * Track A the sole owner of the service and the register UI, and lists four
 * consumers: the exercise AAR (P9), the broken-branch screen (P6), the
 * post-incident review (P10) and thirdLine. Producers only CREATE findings.
 *
 * `carried_to_occurrence_id` IS WRITTEN EXCLUSIVELY BY THE EXERCISE ENGINE.
 * It is the mechanism behind the ladder rule — each level builds on the
 * corrective actions of the one below (ISO 22398, compliance-analyst rule 1) —
 * and it is the column that turns a CAPA register into a rhythm: an action
 * from March's tabletop is carried onto June's functional exercise and shows on
 * its readiness checklist. Anything else writing it would break the audit
 * trail of why an action appeared on an exercise.
 *
 * The bridge to the ERM issue register (`issues`) follows TPRM's settled rule:
 * one-way, BCMS → ERM, with a close-sync back. Both columns exist from Phase 0;
 * the sync is built in Phase 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bcms_findings', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40);

            // The four producers, as four nullable FKs rather than a morph.
            // Two nullable foreign keys are checked by the database; a
            // `source_type`/`source_id` pair is checked by nobody, and a
            // finding pointing at a deleted AAR is exactly the row an examiner
            // asks about.
            $table->foreignId('aar_id')->nullable()->constrained('bcms_aars')->nullOnDelete();
            $table->unsignedBigInteger('incident_id')->nullable();   // constrained in part 7
            $table->unsignedBigInteger('call_tree_test_id')->nullable(); // constrained in part 7
            $table->unsignedBigInteger('dr_test_id')->nullable();     // constrained in part 7

            $table->string('classification', 20); // observation|improvement|nonconformity
            $table->string('severity', 20)->nullable(); // low|medium|high|critical
            $table->text('description');
            $table->text('root_cause')->nullable();

            $table->foreignId('affected_plan_id')->nullable()->constrained('bcms_plans')->nullOnDelete();
            $table->foreignId('affected_process_id')->nullable()->constrained('bcms_processes')->nullOnDelete();
            $table->foreignId('affected_business_unit_id')->nullable()->constrained('business_units')->nullOnDelete();

            // The clause reference is NOT nullable in spirit — clause 10.1
            // requires a nonconformity name the requirement it failed — but it
            // is nullable in the column because an observation raised mid-drill
            // is captured before it is classified. The service refuses to move
            // a finding to `nonconformity` without one.
            $table->string('iso_clause_ref', 60)->nullable();

            $table->string('status', 20)->default('open'); // open|in_progress|closed|accepted_risk
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('raised_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // The ERM bridge (ADR 0001). One-way except the close-sync.
            $table->foreignId('erm_issue_id')->nullable()->constrained('issues')->nullOnDelete();

            $table->boolean('ai_generated')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status', 'classification']);
            $table->index(['organization_id', 'affected_process_id']);
        });

        Schema::create('bcms_corrective_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finding_id')->constrained('bcms_findings')->cascadeOnDelete();
            $table->string('reference', 40);
            $table->string('title', 250);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('priority', 20)->nullable(); // low|medium|high|critical
            $table->string('status', 20)->default('open');

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            // Verification is a separate act by a separate person. Clause 10.1
            // asks whether the action WORKED, which the person who did it
            // cannot answer about themselves.
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verification_evidence_id')->nullable();
            $table->text('verification_note')->nullable();

            // Written ONLY by the exercise engine. See the file header.
            $table->foreignId('carried_to_occurrence_id')->nullable()
                ->constrained('bcms_exercise_occurrences')->nullOnDelete();
            $table->timestamp('carried_at')->nullable();

            // A risk accepted rather than fixed is an attributable decision.
            $table->text('acceptance_rationale')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->date('acceptance_expires_on')->nullable();

            $table->foreignId('erm_issue_id')->nullable()->constrained('issues')->nullOnDelete();
            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status', 'due_date']);
            $table->index(['organization_id', 'owner_id', 'status']);
            $table->index(['carried_to_occurrence_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bcms_corrective_actions');
        Schema::dropIfExists('bcms_findings');
    }
};

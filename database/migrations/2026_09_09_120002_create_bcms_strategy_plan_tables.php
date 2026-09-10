<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BCMS Phase 0, part 2 of 8 — continuity strategy and the plan builder
 * (Blueprint §9.2, ISO 22331 and ISO 22301 8.3/8.4).
 *
 * `bcms_plan_activations.incident_id` is added in part 7, because incidents are
 * created in part 5 and a plan can be activated before this file has a table to
 * point at. Deferring the constraint rather than reordering the files keeps
 * each file readable as one subject.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------------------------------ */
        /*  Strategies — ISO 22331. One process, several candidate strategies. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_strategies', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('process_id')->constrained('bcms_processes')->cascadeOnDelete();
            $table->string('strategy_type', 30); // recover|relocate|remote|manual_workaround|reciprocal|outsource|accept
            $table->string('title', 200)->nullable();
            $table->text('description')->nullable();

            $table->bigInteger('cost_estimate_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('rto_achievable_hours', 8, 2)->nullable();

            // The gap is STORED, not derived on read. It is
            // `rto_achievable_hours - required RTO` at the moment the strategy
            // was assessed, and the required RTO moves with each BIA cycle. A
            // derived gap would silently rewrite last year's approved strategy
            // paper.
            $table->decimal('gap_vs_required_hours', 8, 2)->nullable();
            $table->foreignId('assessed_against_assessment_id')->nullable()
                ->constrained('bcms_bia_assessments')->nullOnDelete();

            $table->boolean('is_selected')->default(false);
            $table->text('selection_rationale')->nullable();
            $table->string('approval_status', 20)->default('draft'); // draft|proposed|approved|rejected
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Resource requirements the strategy assumes — staff, seats,
            // equipment, systems. JSON because the shape differs per strategy
            // type and normalising it would create five sparse tables.
            $table->json('resource_requirements')->nullable();

            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'process_id', 'is_selected']);
            $table->index(['organization_id', 'approval_status']);
        });

        /* ------------------------------------------------------------------ */
        /*  Plans. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained('business_units')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('bcms_sites')->nullOnDelete();
            $table->string('plan_type', 30);
            $table->string('title', 250);

            // Version is a string, not an integer: customers version plans
            // `2.1`, `2026-R1`, `Rev C`, and an integer forces a translation
            // between what the system stores and what the printed cover says.
            $table->string('version', 20)->default('1.0');
            $table->foreignId('supersedes_plan_id')->nullable()->constrained('bcms_plans')->nullOnDelete();

            $table->string('status', 20)->default('draft'); // draft|review|approved|archived
            $table->date('effective_from')->nullable();
            $table->date('next_review_date')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->json('content')->nullable();

            // The offline PWA bundle (Blueprint §1.2 — the network is the first
            // thing to fail). Nullable until Phase 12 builds the generator; the
            // column is here so that is not a structural migration.
            $table->timestamp('offline_bundle_generated_at')->nullable();
            $table->string('offline_bundle_path', 500)->nullable();

            $table->boolean('ai_generated')->default(false);
            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'plan_type', 'status']);
            $table->index(['organization_id', 'next_review_date']);
        });

        Schema::create('bcms_plan_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('bcms_plans')->cascadeOnDelete();
            $table->string('section_key', 60);
            $table->string('title', 250);
            $table->longText('body')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            // `source_binding` is what makes a plan assemble itself rather than
            // be typed: `{"source":"bia","process_id":12,"field":"rto_hours"}`.
            // A bound section is re-rendered from live data when the plan is
            // regenerated, and a section a human has edited is marked
            // `is_overridden` so regeneration never silently discards it.
            $table->json('source_binding')->nullable();
            $table->boolean('is_overridden')->default(false);
            $table->boolean('ai_generated')->default(false);

            $table->timestamps();

            $table->unique(['plan_id', 'section_key']);
            $table->index(['organization_id', 'plan_id']);
        });

        Schema::create('bcms_plan_activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('bcms_plans')->cascadeOnDelete();

            // Constrained in part 7 — `bcms_incidents` does not exist yet.
            $table->unsignedBigInteger('incident_id')->nullable();

            // An activation during an exercise is not an activation in anger,
            // and a management review that cannot tell them apart reports a
            // plan as battle-tested when it was rehearsed.
            $table->unsignedBigInteger('occurrence_id')->nullable();
            $table->boolean('is_exercise')->default(false);

            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at');
            $table->timestamp('deactivated_at')->nullable();
            $table->text('activation_reason')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'plan_id']);
            $table->index(['organization_id', 'incident_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bcms_plan_activations');
        Schema::dropIfExists('bcms_plan_sections');
        Schema::dropIfExists('bcms_plans');
        Schema::dropIfExists('bcms_strategies');
    }
};

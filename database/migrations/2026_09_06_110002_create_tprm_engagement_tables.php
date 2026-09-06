<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 0, part 2 of 9 — engagements, tiering and score runs (TRD §8.2).
 *
 * `tp_engagements` is the centre of the module. Everything else in the schema
 * hangs off it, and the four columns that matter most on it are
 * `effective_tier`, `residual_score`, `residual_band` and `data_confidence` —
 * the numbers a board pack and a supervisor are shown.
 *
 * THOSE FOUR ARE STORED, NOT DERIVED IN A VIEW, and TRD §8.10 is explicit
 * about it. The reason is not performance alone. TRD §7.9 requires that a
 * score never be recomputed retrospectively: a ruleset change produces a NEW
 * run and a reportable diff, and last quarter's number stays what last quarter
 * was told. A derived column recomputes silently on every read, so the board
 * pack printed in March reprints differently in June with nothing recording
 * that it changed. The stored column plus the immutable `tp_score_runs` row is
 * the mechanism that makes AC-15 — two users see identical derivations —
 * possible at all.
 *
 * THE ERM BRIDGE STARTS HERE. `erm_risk_id` is the link required by TRD §15:
 * a High or Critical engagement, or an engagement carrying a Critical finding,
 * creates or updates a risk in this product's own register under the
 * third-party risk area, and the residual score syncs one way — TPRM to the
 * register, never back. One way, because two engines writing one score is how
 * a register ends up with a number nobody can explain. The register keeps the
 * treatment workflow; TPRM keeps the score.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------------------------------ */
        /*  Tier policies — what a tier MEANS, per tenant                      */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_tier_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('tier', 20);

            // Which questionnaire templates this tier is assessed against.
            $table->json('assessment_template_ids')->nullable();
            $table->unsignedSmallInteger('assessment_frequency_months')->nullable();
            $table->unsignedSmallInteger('screening_frequency_months')->nullable();
            $table->string('monitoring_intensity', 20)->default('passive');

            // Ordered list of roles or permissions that must approve an intake
            // at this tier. Phase 1 ships a self-contained implementation
            // behind an interface; Phase 11 swaps in the core workflow engine
            // and this column becomes its input rather than its replacement.
            $table->json('approval_chain')->nullable();

            $table->unsignedBigInteger('required_clause_set_id')->nullable();
            $table->boolean('exit_plan_required')->default(false);
            $table->unsignedSmallInteger('exit_test_frequency_months')->nullable();
            $table->boolean('site_visit_required')->default(false);
            $table->boolean('board_reportable')->default(false);

            // Remediation SLA in days, keyed by finding severity. Overrides
            // config('tprm.defaults.remediation_sla_days') for this tier: a
            // Medium finding against a Critical vendor is not the same ninety
            // days as a Medium against a Low one.
            $table->json('remediation_sla')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'tier']);
        });

        /* ------------------------------------------------------------------ */
        /*  Engagements                                                        */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_engagements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('third_party_id')->constrained('tp_third_parties')->cascadeOnDelete();

            $table->string('reference', 30);
            $table->string('name', 255);
            $table->text('service_description')->nullable();
            $table->foreignId('service_type_id')->nullable()->constrained('tp_categories')->nullOnDelete();
            $table->string('engagement_type', 30);

            $table->foreignId('business_unit_id')->nullable()->constrained('business_units')->nullOnDelete();
            $table->foreignId('relationship_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('executive_sponsor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 30)->default('draft');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            // Money is stored in minor units throughout this product — see the
            // loss_events `_kobo` columns and docs/schema/canonical-columns.md
            // rule 5, which also requires a currency code alongside every
            // stored amount. Annual spend feeds the FIN inherent factor and
            // the spend-weighted portfolio exposure metric in TRD §7.8.
            $table->unsignedBigInteger('annual_spend_minor')->nullable();
            $table->string('currency', 3)->nullable();

            $table->boolean('is_material_outsourcing')->default(false);

            // Derived from the linked functions by the tiering service, stored
            // because every register export and concentration run filters on
            // it and none of them should join three tables to find out.
            $table->boolean('supports_critical_function')->default(false);

            $table->string('cloud_model', 20)->nullable();
            $table->string('deployment_location', 120)->nullable();
            $table->boolean('pci_in_scope')->default(false);

            /* --- Data protection profile — NDPA / GAID ------------------- */
            $table->boolean('processes_personal_data')->default(false);
            $table->string('data_subject_volume_band', 30)->nullable();
            $table->json('data_categories')->nullable();
            $table->string('data_location_at_rest', 2)->nullable();
            $table->string('data_location_processing', 2)->nullable();
            $table->boolean('cross_border')->default(false);

            // NDPA §41(2) lawful basis for the transfer. `none` recorded
            // against a cross-border personal-data engagement is what fires
            // the KO-PII-XB knockout — the absence of a basis is the finding,
            // so it has to be a recordable value rather than a null.
            $table->string('transfer_basis', 40)->nullable();
            $table->text('transfer_basis_note')->nullable();

            $table->boolean('dpia_required')->default(false);
            $table->unsignedBigInteger('dpia_id')->nullable();

            /* --- Substitutability — DORA Art. 29, BCBS P3 ----------------- */
            $table->string('substitutability', 20)->nullable();
            $table->unsignedSmallInteger('time_to_replace_months')->nullable();

            /* --- Scores. Maintained by the scoring service only. ---------- */
            $table->decimal('inherent_score', 5, 2)->nullable();
            $table->string('inherent_tier', 20)->nullable();

            // A manual override is a floor like a knockout is: it may raise the
            // tier and never lower it. It needs a rationale, an approver with
            // the permission, and an EXPIRY — an override with no end date is
            // a permanent exception nobody revisits.
            $table->string('tier_override', 20)->nullable();
            $table->text('tier_override_reason')->nullable();
            $table->foreignId('tier_override_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('tier_override_expires_at')->nullable();

            $table->string('effective_tier', 20)->nullable();

            $table->decimal('residual_score', 5, 2)->nullable();
            $table->string('residual_band', 20)->nullable();
            $table->decimal('assurance_coverage', 4, 3)->nullable();
            $table->decimal('evidence_confidence', 4, 3)->nullable();
            $table->decimal('data_confidence', 4, 3)->nullable();

            $table->date('next_assessment_due')->nullable();
            $table->date('next_review_due')->nullable();
            $table->boolean('exit_plan_required')->default(false);

            $table->timestamp('terminated_at')->nullable();
            $table->string('termination_reason', 60)->nullable();

            /* --- The ERM bridge (TRD §15) --------------------------------- */

            // The risk this engagement is represented by in the ERM register.
            // Null until the engagement tiers High or Critical, or carries a
            // Critical finding. `nullOnDelete` rather than cascade: deleting a
            // risk from the register must not delete the vendor engagement it
            // was raised from.
            $table->foreignId('erm_risk_id')->nullable()->constrained('risks')->nullOnDelete();
            $table->timestamp('erm_synced_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);

            // The four composite indexes TRD §8.10 names, plus the two the
            // register list and the ERM sync actually filter on.
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'effective_tier']);
            $table->index(['organization_id', 'next_assessment_due']);
            $table->index(['organization_id', 'residual_band']);
            $table->index(['organization_id', 'third_party_id']);
            $table->index(['organization_id', 'erm_risk_id']);
        });

        /* ------------------------------------------------------------------ */
        /*  Engagement ↔ business function                                     */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_engagement_functions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained('tp_engagements')->cascadeOnDelete();
            $table->foreignId('business_function_id')->constrained('tp_business_functions')->cascadeOnDelete();

            $table->string('dependency_level', 20)->default('supporting');
            $table->string('reliance_level', 20)->default('medium');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['engagement_id', 'business_function_id'], 'tp_eng_func_unique');
            // The concentration analyser walks this the other way — which
            // engagements depend on one function — so both directions index.
            $table->index(['organization_id', 'business_function_id'], 'tp_eng_func_function_idx');
        });

        /* ------------------------------------------------------------------ */
        /*  Inherent assessments — versioned, never overwritten                */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_inherent_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained('tp_engagements')->cascadeOnDelete();

            $table->unsignedInteger('version');
            $table->string('ruleset_version', 30);

            // The full input snapshot: what was asked, what was answered, what
            // each answer scored, and the weights in force at the time. A
            // score whose inputs are not stored cannot be explained six months
            // later, and AC-15 requires that it can be.
            $table->json('answers');
            $table->json('factor_scores');
            $table->json('weights');
            $table->decimal('raw_score', 5, 2);
            $table->json('knockouts_fired')->nullable();
            $table->string('resulting_tier', 20);

            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at');

            // Exactly one row per engagement carries this. Superseding a
            // version clears it on the old row rather than deleting the row:
            // the history is the audit trail.
            $table->boolean('is_current')->default(true);

            $table->timestamps();

            $table->unique(['engagement_id', 'version']);
            $table->index(['organization_id', 'engagement_id', 'is_current'], 'tp_inherent_current_idx');
        });

        /* ------------------------------------------------------------------ */
        /*  Score runs — the immutable record of every computation             */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_score_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained('tp_engagements')->cascadeOnDelete();

            $table->string('run_type', 20);
            $table->string('engine_version', 20);
            $table->string('ruleset_version', 30);

            $table->json('inputs');

            // The score vocabulary of TRD §7.1, one column each rather than a
            // JSON blob, because the "Why this score" panel, the diff report
            // and the Assurance Depth metric all query them and a JSON path
            // query is not portable between this product's two drivers.
            $table->decimal('ir', 5, 2)->nullable();
            $table->decimal('ac', 4, 3)->nullable();
            $table->decimal('ec', 4, 3)->nullable();
            $table->decimal('m', 4, 3)->nullable();
            $table->decimal('fu', 5, 2)->nullable();
            $table->decimal('su', 5, 2)->nullable();
            $table->decimal('rr', 5, 2)->nullable();
            $table->string('band', 20)->nullable();
            $table->decimal('dc', 4, 3)->nullable();

            // The structured derivation the UI renders: factors and weights,
            // knockouts with citations, AC/EC components, per-finding and
            // per-signal contributions.
            $table->json('explanation');

            $table->string('triggered_by', 60)->nullable();

            // NO `updated_at`. A score run is an event, not a record: it
            // happened, and nothing about it changes afterwards. There is no
            // update or delete route to this table.
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'engagement_id', 'created_at']);
            $table->index(['organization_id', 'run_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_score_runs');
        Schema::dropIfExists('tp_inherent_assessments');
        Schema::dropIfExists('tp_engagement_functions');
        Schema::dropIfExists('tp_engagements');
        Schema::dropIfExists('tp_tier_policies');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 0, part 3 of 9 — due diligence, screening, financial reviews and
 * site visits (TRD §8.3).
 *
 * The rule that shapes the checklist tables is FR-DDL-08: NO SILENT SKIPPING.
 * A due diligence item closes with evidence, or it closes with a waiver that
 * names an approver and carries an expiry. There is no third option, which is
 * why `waiver_reason`, `waiver_approver_id` and `waiver_expires_at` sit beside
 * `evidence_document_id` on the same row — a checklist where "we did not get
 * round to it" and "we accepted the gap, here is who accepted it and until
 * when" are the same value is a checklist that proves nothing to a supervisor.
 *
 * `tp_screening_checks` and `tp_screening_matches` carry a five-year retention
 * obligation (CBN AML/CFT Regulations 2022 Reg. 35) and a 48-hour retrieval
 * expectation. Neither is enforceable by a column, so neither is asserted
 * here — the retention job and the retrieval test in Phase 6 are what make the
 * claim true. What this migration does is make retrieval POSSIBLE: `run_at`
 * is indexed, `raw_response` keeps the provider's answer verbatim, and nothing
 * cascades a delete from the third party into the screening history.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------------------------------ */
        /*  Due diligence checklists                                           */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_due_diligence_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained('tp_engagements')->cascadeOnDelete();

            // The tier-keyed template the checklist was generated from. Kept
            // as a plain reference rather than a foreign key because a
            // template may be retired while checklists generated from it stay
            // open, and losing which template produced a checklist loses the
            // ability to say why an item was mandatory.
            $table->string('template_code', 60)->nullable();
            $table->string('tier_at_generation', 20)->nullable();

            $table->string('status', 20)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'engagement_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('tp_due_diligence_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_id')->constrained('tp_due_diligence_checklists')->cascadeOnDelete();

            $table->string('code', 60);
            $table->string('title', 255);
            $table->string('category', 60)->nullable();
            $table->string('item_type', 30);

            // FR-DDL-09: due diligence cannot complete while a mandatory item
            // for the tier is open.
            $table->boolean('is_mandatory')->default(false);

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('open');

            // Set in part 5 of this migration set; declared unsigned here and
            // constrained there, because `tp_documents` does not exist yet.
            $table->unsignedBigInteger('evidence_document_id')->nullable();

            $table->text('waiver_reason')->nullable();
            $table->foreignId('waiver_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('waiver_expires_at')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'checklist_id', 'status']);
            $table->index(['organization_id', 'due_date']);
        });

        /* ------------------------------------------------------------------ */
        /*  Screening                                                          */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_screening_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // The subject is either the entity or one ownership row — a
            // director or UBO screened in its own right, per Reg. 29. A
            // polymorphic pair rather than two nullable foreign keys, because
            // "exactly one of these two is set" is a constraint no database in
            // use here enforces, and a nullable pair invites both being null.
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');

            $table->string('provider', 40);
            $table->json('list_types');

            $table->timestamp('run_at');
            $table->string('status', 20);

            // The provider's answer, verbatim. This is the evidence Reg. 35
            // requires to be retrievable for five years; a normalised summary
            // is our reading of the answer, not the answer.
            $table->json('raw_response')->nullable();

            $table->timestamp('next_due_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'subject_type', 'subject_id'], 'tp_screening_subject_idx');
            $table->index(['organization_id', 'run_at']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'next_due_at']);
        });

        Schema::create('tp_screening_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('check_id')->constrained('tp_screening_checks')->cascadeOnDelete();

            $table->string('list_name', 120);
            $table->string('matched_name', 255);
            $table->decimal('match_score', 5, 2)->nullable();
            $table->json('entity_details')->nullable();

            // Never set by a driver. A true match is a person's decision with
            // a rationale, and AC-08 hangs off it: suspend every engagement,
            // force RR to 100, notify AML, open a 24-hour STR task.
            $table->string('decision', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('rationale')->nullable();

            $table->boolean('escalated')->default(false);
            $table->unsignedBigInteger('str_task_id')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'decision']);
            $table->index(['organization_id', 'check_id']);
        });

        /* ------------------------------------------------------------------ */
        /*  Financial reviews                                                  */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_financial_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('third_party_id')->constrained('tp_third_parties')->cascadeOnDelete();

            $table->string('period_label', 40);
            $table->date('period_end');
            $table->string('source', 40);
            $table->string('provider', 120)->nullable();

            // Minor units and a currency code, per the product's money rule.
            $table->bigInteger('revenue_minor')->nullable();
            $table->bigInteger('net_assets_minor')->nullable();
            $table->string('currency', 3)->nullable();

            $table->decimal('current_ratio', 8, 3)->nullable();
            $table->decimal('debt_equity', 8, 3)->nullable();
            $table->decimal('external_score', 8, 2)->nullable();
            $table->string('external_band', 30)->nullable();

            $table->boolean('going_concern_flag')->default(false);
            $table->string('auditor_name', 200)->nullable();
            $table->string('auditor_opinion', 60)->nullable();

            // Set by comparing against the prior period's row for the same
            // third party. It drives the `financial_distress` signal, which is
            // worth 8 points of SU — so it is a stored, reviewable conclusion
            // rather than something recomputed differently on each screen.
            $table->boolean('deterioration_flag')->default(false);

            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'third_party_id', 'period_end'], 'tp_fin_review_period_idx');
            $table->index(['organization_id', 'deterioration_flag']);
        });

        /* ------------------------------------------------------------------ */
        /*  Site visits                                                        */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_site_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('third_party_id')->constrained('tp_third_parties')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('tp_locations')->nullOnDelete();
            $table->foreignId('engagement_id')->nullable()->constrained('tp_engagements')->nullOnDelete();

            $table->date('visit_date');

            // `datacentre` is the CBN Cyber Framework Appendix II §1.4 data
            // centre inspection. It is a visit type rather than a separate
            // table because everything else about it — attendees, scope,
            // observations, outcome, report — is identical to any other visit.
            $table->string('visit_type', 30);

            $table->json('attendees')->nullable();
            $table->text('scope')->nullable();
            $table->text('observations')->nullable();
            $table->string('outcome', 40)->nullable();
            $table->unsignedBigInteger('report_document_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'third_party_id', 'visit_date']);
            $table->index(['organization_id', 'visit_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_site_visits');
        Schema::dropIfExists('tp_financial_reviews');
        Schema::dropIfExists('tp_screening_matches');
        Schema::dropIfExists('tp_screening_checks');
        Schema::dropIfExists('tp_due_diligence_items');
        Schema::dropIfExists('tp_due_diligence_checklists');
    }
};

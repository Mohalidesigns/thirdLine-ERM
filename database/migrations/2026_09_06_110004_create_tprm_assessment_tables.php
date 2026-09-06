<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 0, part 4 of 9 — frameworks, questionnaires and assessments
 * (TRD §8.4).
 *
 * THE PRODUCT ALREADY HAS A QUESTIONNAIRE MODEL — `questionnaires`,
 * `questionnaire_sections`, `questions`, `question_library`, driven by
 * `AssessmentCampaign` and `CampaignResponse` — and this migration does not
 * reuse it. That is a deviation from the "reuse core where core exists" rule
 * the RCSA universe migration applied to `business_processes`, so it needs a
 * reason rather than a preference.
 *
 * The reason is that four columns on `tp_questions` and three on
 * `tp_assessment_responses` are not additions to the campaign model, they are
 * the model. `risk_weight`, `is_critical`, `min_assurance_level` and
 * `visibility_rule` on the question, and `assurance_level`, `computed_conf`
 * and `carried_forward_from_response_id` on the response, exist so that TRD
 * §7.4 can score two identical answer sets differently according to what
 * evidences them. Adding seven columns to the campaign tables would put the
 * TPRM scoring vocabulary onto every internal campaign in the product, where
 * it means nothing and where a null in `assurance_level` would have to be
 * silently interpreted. The campaign module asks "did they answer"; TPRM asks
 * "and why should we believe them". Those are different questions and they get
 * different tables.
 *
 * `tp_frameworks` and `tp_framework_controls` are created here because core
 * has no control-framework library: `risk_taxonomies` carries a free-text
 * `framework` string for Basel/COSO/ISO 31000 tagging, which is a taxonomy
 * label rather than an enumerated control set. TRD §4.3 requires mappings to
 * be stored against identifiers that do not drift — ISO 27002:2022 control
 * IDs, NIST 800-53r5 IDs, CSF 2.0 subcategory IDs, CCM v4 IDs, TSC criteria —
 * and every one of those needs a version stamp, because SIG domain letters and
 * DORA template numbering are explicitly NOT stable between releases.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------------------------------ */
        /*  Framework libraries — system-owned reference data                  */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_frameworks', function (Blueprint $table) {
            $table->id();

            // NULLABLE tenant. A framework with no organisation is a system
            // library shipped with the product — ISO 27002, PCI DSS, the CBN
            // Cyber Framework — readable by every tenant and editable by none.
            // A tenant may add its own, which carries its organisation id.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('code', 40);
            $table->string('name', 200);
            $table->string('version', 40);
            $table->string('publisher', 120)->nullable();
            $table->text('description')->nullable();

            // False for the frameworks whose identifiers drift between
            // releases — SIG domain letters, DORA register template codes.
            // A mapping stored against an unstable key must be re-verified on
            // every version bump, and the UI has to be able to say so.
            $table->boolean('has_stable_keys')->default(true);

            /*
             * How much of the standard is actually in `tp_framework_controls`.
             *
             * These two columns exist because the alternative was worse. Some
             * of these libraries are freely enumerable (ISO 27002 control IDs,
             * NIST CSF subcategories, PCI requirement numbers); others are
             * licensed content whose control text we may not reproduce, and
             * some run to hundreds of objectives. Seeding a plausible-looking
             * title for a control we have not verified would put invented
             * regulatory text into a compliance product, which is the one
             * thing this product must never do.
             *
             * So a framework declares how many controls the standard contains
             * and whether our catalogue of it is `complete` or `partial`. A
             * questionnaire mapped to a partial framework still works — the
             * control ID is the stable join key and is what matters — but the
             * UI can say "we hold 14 of 197 CCM objectives" instead of letting
             * a user believe the library is exhaustive.
             */
            $table->unsignedSmallInteger('declared_control_count')->nullable();
            $table->string('catalogue_status', 20)->default('partial');
            $table->text('catalogue_note')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['code', 'version']);
        });

        Schema::create('tp_framework_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('framework_id')->constrained('tp_frameworks')->cascadeOnDelete();

            $table->string('control_id', 60);
            $table->string('title', 400);
            $table->text('description')->nullable();
            $table->string('domain', 200)->nullable();
            $table->string('parent_control_id', 60)->nullable();

            // ISO 27002:2022 A.5.19–A.5.23 and their equivalents elsewhere:
            // the subset a supplier questionnaire should be mapping to. The
            // questionnaire builder offers these first.
            $table->boolean('supplier_relevant')->default(false);

            $table->timestamps();

            $table->unique(['framework_id', 'control_id']);
            $table->index(['framework_id', 'supplier_relevant']);
        });

        /* ------------------------------------------------------------------ */
        /*  Questionnaire templates                                            */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_questionnaire_templates', function (Blueprint $table) {
            $table->id();
            // Null for the packs shipped with the product (TRD Appendix B).
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('code', 60);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->json('framework_tags')->nullable();
            $table->string('version', 20)->default('1.0');
            $table->string('status', 20)->default('draft');

            // Engagement types, tiers and flags this template is offered for.
            $table->json('applies_to')->nullable();
            $table->string('scoring_mode', 20)->default('weighted');

            $table->timestamp('published_at')->nullable();
            $table->foreignId('parent_template_id')->nullable()->constrained('tp_questionnaire_templates')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code', 'version']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('tp_questionnaire_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('tp_questionnaire_templates')->cascadeOnDelete();

            $table->string('code', 60);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('weight', 6, 3)->default(1);
            $table->string('domain_tag', 60)->nullable();

            // A rule in the Phase 0 DSL. Null means always visible.
            $table->json('visibility_rule')->nullable();

            $table->timestamps();

            $table->unique(['template_id', 'code']);
        });

        Schema::create('tp_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('tp_questionnaire_sections')->cascadeOnDelete();

            $table->string('code', 60);
            $table->text('text');
            $table->text('help_text')->nullable();
            $table->string('type', 30);
            $table->json('options')->nullable();

            $table->boolean('is_required')->default(true);

            // A critical question answered non-compliant caps the whole
            // assessment's AC at 0.5 regardless of everything else, and
            // auto-raises a High or Critical finding (TRD §7.4).
            $table->boolean('is_critical')->default(false);

            // `weight` is the question's share of its section. `risk_weight` is
            // w_q in the AC and EC formulae AND the default severity of a
            // finding raised from a non-compliant answer. They are separate
            // because a question can be central to the questionnaire's
            // structure and minor in risk terms, or the reverse.
            $table->decimal('weight', 6, 3)->default(1);
            $table->decimal('risk_weight', 6, 3)->default(1);

            $table->boolean('evidence_required')->default(false);
            $table->json('evidence_types')->nullable();

            // The floor below which an answer to this question does not count
            // as evidenced, however the vendor rates it.
            $table->string('min_assurance_level', 30)->nullable();

            $table->json('visibility_rule')->nullable();
            $table->json('scoring_map')->nullable();
            $table->json('auto_answer_rule')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['section_id', 'code']);
        });

        Schema::create('tp_question_control_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('tp_questions')->cascadeOnDelete();

            $table->string('framework', 40);
            $table->string('framework_version', 40);
            $table->string('control_id', 60);
            $table->string('relationship', 20)->default('primary');

            // Optional link to THIS institution's own control library, so that
            // a vendor control gap surfaces against the control it undermines
            // on our side (TRD §15, control library contract).
            $table->foreignId('internal_control_id')->nullable()->constrained('controls')->nullOnDelete();

            $table->timestamps();

            $table->unique(['question_id', 'framework', 'framework_version', 'control_id'], 'tp_qcm_unique');
            $table->index(['framework', 'control_id']);
        });

        /* ------------------------------------------------------------------ */
        /*  Assessments                                                        */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained('tp_engagements')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('tp_questionnaire_templates');

            // The template's version AT ISSUE. A published template is
            // immutable, but a successor may be published while this cycle is
            // still open, and the assessment has to keep saying which one it
            // was answered against.
            $table->string('template_version', 20);

            $table->string('cycle_label', 60)->nullable();
            $table->string('assessment_type', 30)->default('initial');
            $table->string('trigger_source', 60)->nullable();
            $table->string('status', 30)->default('draft');

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->foreignId('assigned_portal_contact_id')->nullable()->constrained('tp_contacts')->nullOnDelete();
            $table->foreignId('internal_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('analyst_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_assessment_id')->nullable()->constrained('tp_assessments')->nullOnDelete();

            $table->decimal('raw_score', 6, 3)->nullable();
            $table->decimal('ac', 4, 3)->nullable();
            $table->decimal('ec', 4, 3)->nullable();
            $table->json('section_scores')->nullable();
            $table->json('domain_scores')->nullable();

            $table->unsignedInteger('question_count')->default(0);
            $table->unsignedInteger('applicable_count')->default(0);
            $table->unsignedInteger('answered_count')->default(0);

            // Per question: was it included, and WHICH RULE decided. This is
            // the supervisory defence for a short questionnaire — without it,
            // "we only asked forty of the two hundred questions" is an
            // assertion rather than a record (FR-ASM-03).
            $table->json('scoping_trace')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'engagement_id', 'status']);
            $table->index(['organization_id', 'status', 'due_at']);
        });

        Schema::create('tp_assessment_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained('tp_assessments')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('tp_questions');

            $table->json('value')->nullable();
            $table->string('assurance_level', 30)->nullable();
            $table->string('compliance', 20)->default('unanswered');

            $table->boolean('is_auto_answered')->default(false);
            $table->json('auto_answer_source')->nullable();

            $table->foreignId('carried_forward_from_response_id')->nullable()
                ->constrained('tp_assessment_responses')->nullOnDelete();

            // How many cycles this answer has been carried without being
            // re-evidenced. Drives the ×0.9-per-cycle decay with its 0.5 floor
            // in TRD §7.4, and the carry-forward limit in FR-ASM-11.
            $table->unsignedSmallInteger('carry_forward_cycles')->default(0);

            $table->text('vendor_comment')->nullable();
            $table->string('reviewer_status', 30)->default('pending');
            $table->text('reviewer_comment')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->json('quality_flags')->nullable();

            // conf_q after every modifier. Stored rather than recomputed on
            // read for the same reason the engagement's scores are: the
            // derivation shown beside a score has to be the one that produced
            // it, not a fresh one computed against today's evidence dates.
            $table->decimal('computed_conf', 4, 3)->nullable();

            $table->timestamps();

            $table->unique(['assessment_id', 'question_id']);
            $table->index(['organization_id', 'assessment_id', 'reviewer_status'], 'tp_response_review_idx');
        });

        Schema::create('tp_response_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('response_id')->constrained('tp_assessment_responses')->cascadeOnDelete();

            // Constrained in part 5, where tp_documents is created.
            $table->unsignedBigInteger('document_id');

            $table->string('page_reference', 60)->nullable();
            $table->text('extract_text')->nullable();
            $table->boolean('added_by_portal')->default(false);

            $table->timestamps();

            $table->index(['organization_id', 'response_id']);
            $table->index('document_id');
        });

        Schema::create('tp_assessment_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained('tp_assessments')->cascadeOnDelete();
            $table->foreignId('response_id')->nullable()->constrained('tp_assessment_responses')->cascadeOnDelete();

            // `internal` or `vendor`. Not a foreign key to `users`, because a
            // vendor author is a portal user in a different table under a
            // different guard — see part 9.
            $table->string('author_type', 20);
            $table->unsignedBigInteger('author_id')->nullable();

            $table->text('body');
            $table->json('attachments')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'assessment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_assessment_messages');
        Schema::dropIfExists('tp_response_evidence');
        Schema::dropIfExists('tp_assessment_responses');
        Schema::dropIfExists('tp_assessments');
        Schema::dropIfExists('tp_question_control_maps');
        Schema::dropIfExists('tp_questions');
        Schema::dropIfExists('tp_questionnaire_sections');
        Schema::dropIfExists('tp_questionnaire_templates');
        Schema::dropIfExists('tp_framework_controls');
        Schema::dropIfExists('tp_frameworks');
    }
};

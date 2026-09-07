<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 0, part 5 of 9 — the evidence library and document intelligence
 * (TRD §8.5).
 *
 * `tp_documents` is a separate store from the product's existing attachment
 * tables (`issue_attachments`, `loss_event_attachments`,
 * `control_test_evidence`) and the reason is on the table: `valid_from`,
 * `valid_to`, `scope_text`, `issuer`, `is_superseded` and `extraction_status`.
 * An attachment is a file someone put on a record. Evidence is a claim with an
 * issuer, a scope and an expiry date, and the module's central mechanism —
 * that a control's assurance decays when its evidence expires, and that a
 * certificate whose scope does not name the service consumed scores ×0.7 — is
 * unimplementable without those columns. TRD §15 keeps the door open to
 * putting the BYTES in the core document store later; the metadata stays here
 * either way.
 *
 * THE FOUR SOC 2 TABLES ARE THE DEMO. A SOC 2 Type II is the densest evidence
 * artefact a vendor produces and every competitor treats it as a PDF
 * attachment. Decomposed into `tp_soc2_details`, `_exceptions`, `_cuecs` and
 * `_subservice_orgs`, one upload cascades into pre-answered questions with
 * citations, a finding per Section 4 exception, an internal obligation per
 * complementary user entity control, and a proposed sub-processor edge per
 * carve-out (AC-04). The CUEC table is the one to notice: a CUEC is an
 * obligation the report places on US, and a bank that files the SOC 2 without
 * reading them has accepted duties it does not know it has.
 *
 * NOTHING AN EXTRACTOR PRODUCES IS APPLIED WITHOUT A HUMAN CONFIRMING IT.
 * `tp_document_extractions.status` starts `pending` and the cascade generates
 * PROPOSALS requiring a second confirmation. That is a rule in the code, and
 * the `confirmed_by` / `confirmed_at` columns are how it is auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_document_types', function (Blueprint $table) {
            $table->id();
            // Null for the system catalogue; set for a tenant's own additions.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('code', 60);
            $table->string('name', 200);
            $table->string('category', 60)->nullable();

            $table->boolean('has_expiry')->default(false);
            $table->unsignedSmallInteger('default_validity_months')->nullable();

            $table->string('extractor', 30)->default('generic');

            // Whether a document of this type can raise an answer's assurance
            // level at all, and how far. A signed NDA is a document; it is not
            // evidence that a control operates.
            $table->boolean('is_assurance_evidence')->default(false);
            $table->string('default_assurance_level', 30)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('tp_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Polymorphic owner: third_party, engagement, assessment, contract,
            // finding or incident. A morph pair rather than six nullable keys.
            $table->string('owner_type', 30);
            $table->unsignedBigInteger('owner_id');

            $table->foreignId('document_type_id')->nullable()->constrained('tp_document_types')->nullOnDelete();
            $table->string('title', 255);

            $table->string('file_path', 500);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();

            // SHA-256 of the stored bytes. Two vendors uploading the same
            // certificate should be detectable, and a file that changes under
            // an unchanged record should not be.
            $table->string('hash', 64)->nullable();

            $table->unsignedInteger('version')->default(1);

            $table->string('issuer', 200)->nullable();

            // The certificate's own scope wording. FR-DDL-07's scope-mismatch
            // check compares this against the engagement's service
            // description; a mismatch applies the ×0.7 modifier in TRD §7.4.
            // Free text because that is what a certificate contains.
            $table->text('scope_text')->nullable();

            $table->date('issue_date')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            $table->string('confidentiality', 30)->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploaded_via', 20)->default('internal');

            $table->string('virus_scan_status', 20)->default('pending');
            $table->string('extraction_status', 20)->default('none');

            $table->boolean('is_superseded')->default(false);
            $table->foreignId('superseded_by_id')->nullable()->constrained('tp_documents')->nullOnDelete();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'owner_type', 'owner_id']);
            // TRD §8.10: the expiry monitor and the evidence heat map both
            // read this one.
            $table->index(['organization_id', 'valid_to']);
            $table->index(['organization_id', 'document_type_id']);
            $table->index(['organization_id', 'extraction_status']);
        });

        Schema::create('tp_document_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('tp_documents')->cascadeOnDelete();

            $table->string('extractor', 30);

            // The model and prompt version that produced this. Without both,
            // an extraction cannot be reproduced or explained, and a prompt
            // improvement cannot be told apart from a model change.
            $table->string('model', 120)->nullable();
            $table->string('prompt_version', 40)->nullable();

            $table->json('extracted');
            $table->decimal('confidence', 4, 3)->nullable();

            // Every quoted string, with its location in the source text.
            // CitationVerifier rejects an extraction whose quotes cannot be
            // found in the document and downgrades it to low confidence — the
            // one defence against a confident invention.
            $table->json('citations')->nullable();

            $table->string('status', 20)->default('pending');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            // What the human changed. The correction record is the training
            // signal and the honesty check on the extractor's accuracy claim.
            $table->json('corrections')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'document_id']);
        });

        /* ------------------------------------------------------------------ */
        /*  SOC 2 decomposition */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_soc2_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('tp_documents')->cascadeOnDelete();

            $table->string('report_type', 20);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('service_auditor', 200)->nullable();
            $table->text('scope_description')->nullable();
            $table->json('tsc_categories')->nullable();
            $table->string('opinion_type', 40)->nullable();
            $table->text('qualification_basis')->nullable();
            $table->unsignedInteger('exception_count')->default(0);

            // carve_out | inclusive | none. A carve-out means the report says
            // nothing about the subservice organisations it names — which is
            // exactly why each one becomes a proposed nth-party edge.
            $table->string('subservice_method', 20)->nullable();

            // AC-05: a control evidenced only by a bridge letter for the gap
            // period is capped at `documented` and scores 0.60, not 0.85. A
            // bridge letter is the vendor's assertion that nothing changed; it
            // is not an auditor's opinion that nothing did.
            $table->foreignId('bridge_letter_document_id')->nullable()->constrained('tp_documents')->nullOnDelete();
            $table->date('bridge_covers_to')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'document_id']);
            $table->index(['organization_id', 'period_end']);
        });

        Schema::create('tp_soc2_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('soc2_id')->constrained('tp_soc2_details')->cascadeOnDelete();

            $table->string('control_reference', 120)->nullable();
            $table->text('description');
            $table->string('population', 120)->nullable();
            $table->string('exceptions_noted', 200)->nullable();
            $table->text('management_response')->nullable();
            $table->string('severity_assessment', 20)->nullable();

            // Constrained in part 7, where tp_findings is created.
            $table->unsignedBigInteger('linked_finding_id')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'soc2_id']);
        });

        Schema::create('tp_soc2_cuecs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('soc2_id')->constrained('tp_soc2_details')->cascadeOnDelete();

            $table->string('cuec_reference', 60)->nullable();
            $table->text('description');

            // A CUEC is a control the report assumes WE operate. Unowned, it
            // is an assumption the auditor made on our behalf and nobody here
            // agreed to.
            $table->foreignId('internal_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('internal_control_id')->nullable()->constrained('controls')->nullOnDelete();

            $table->string('attestation_status', 20)->default('pending');
            $table->timestamp('last_attested_at')->nullable();
            $table->date('next_due_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'soc2_id']);
            $table->index(['organization_id', 'attestation_status']);
        });

        Schema::create('tp_soc2_subservice_orgs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('soc2_id')->constrained('tp_soc2_details')->cascadeOnDelete();

            $table->string('name', 255);
            $table->text('services')->nullable();
            $table->string('method', 20);

            // Constrained in part 8, where tp_nth_party_edges is created.
            $table->unsignedBigInteger('proposed_nth_party_edge_id')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'soc2_id']);
        });

        /* ------------------------------------------------------------------ */
        /*  Deferred foreign keys from earlier parts */
        /* ------------------------------------------------------------------ */

        // SQLite cannot add a foreign key to an existing table, and this
        // product's test suite runs on SQLite (phpunit.xml). Adding the
        // constraint only where the driver supports it keeps the referential
        // guarantee in production without making the suite unrunnable. The
        // application-level guarantee — a nullable document reference is
        // either null or a real document — is the model's job on both drivers.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('tp_due_diligence_items', function (Blueprint $table) {
                $table->foreign('evidence_document_id')->references('id')->on('tp_documents')->nullOnDelete();
            });

            Schema::table('tp_financial_reviews', function (Blueprint $table) {
                $table->foreign('document_id')->references('id')->on('tp_documents')->nullOnDelete();
            });

            Schema::table('tp_site_visits', function (Blueprint $table) {
                $table->foreign('report_document_id')->references('id')->on('tp_documents')->nullOnDelete();
            });

            Schema::table('tp_response_evidence', function (Blueprint $table) {
                $table->foreign('document_id')->references('id')->on('tp_documents')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('tp_response_evidence', function (Blueprint $table) {
                $table->dropForeign(['document_id']);
            });
            Schema::table('tp_site_visits', function (Blueprint $table) {
                $table->dropForeign(['report_document_id']);
            });
            Schema::table('tp_financial_reviews', function (Blueprint $table) {
                $table->dropForeign(['document_id']);
            });
            Schema::table('tp_due_diligence_items', function (Blueprint $table) {
                $table->dropForeign(['evidence_document_id']);
            });
        }

        Schema::dropIfExists('tp_soc2_subservice_orgs');
        Schema::dropIfExists('tp_soc2_cuecs');
        Schema::dropIfExists('tp_soc2_exceptions');
        Schema::dropIfExists('tp_soc2_details');
        Schema::dropIfExists('tp_document_extractions');
        Schema::dropIfExists('tp_documents');
        Schema::dropIfExists('tp_document_types');
    }
};

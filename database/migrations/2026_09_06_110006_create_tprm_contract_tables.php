<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 0, part 6 of 9 — contracts, clauses, obligations and SLAs
 * (TRD §8.6).
 *
 * The design goal of this group is stated in the phase prompt: the contract
 * stops being a PDF and becomes a set of enforceable, monitored obligations.
 * Three columns carry that.
 *
 * `tp_clause_library.is_blocking` is the gate no competitor ships (FR-CTR-05,
 * AC-06): an engagement whose contract lacks an applicable blocking clause —
 * audit rights, for instance, which CBN Cyber §2.3 requires — cannot reach
 * `active`. The error names the clause and cites the source. A waiver needs
 * the risk function's permission, a rationale and an expiry, and appears on
 * the override register.
 *
 * `tp_clause_library.applicability_rule` holds a Phase 0 DSL rule, so that
 * "which clauses does THIS contract need" is answered by the engagement's own
 * attributes rather than by a checklist somebody maintains by hand. A clause
 * required only of cross-border personal-data processors should not be a gap
 * on a stationery contract.
 *
 * `tp_contracts.notice_period_days_entity` exists because FR-CTR-02 keys
 * renewal alerting to the NOTICE PERIOD, not the expiry date. A contract that
 * expires in ninety days with a hundred-and-twenty-day notice period has
 * already auto-renewed by the time an expiry-based alert fires, and that is
 * the single most common way an institution finds itself locked into a vendor
 * it had decided to leave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained('tp_engagements')->cascadeOnDelete();

            // An amendment or SOW under an MSA. The effective clause set is
            // resolved down this chain with amendment precedence, so a clause
            // added by an amendment beats the MSA's silence on it.
            $table->foreignId('parent_contract_id')->nullable()->constrained('tp_contracts')->nullOnDelete();

            $table->string('contract_type', 40);
            $table->string('reference', 80);
            $table->string('title', 255);

            $table->string('counterparty_signatory', 200)->nullable();
            $table->foreignId('internal_signatory_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('effective_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('renewal_type', 20)->default('none');
            $table->unsignedSmallInteger('renewal_term_months')->nullable();

            // Two notice periods, because they are routinely different and the
            // one that matters for our alerting is ours.
            $table->unsignedSmallInteger('notice_period_days_entity')->nullable();
            $table->unsignedSmallInteger('notice_period_days_provider')->nullable();

            $table->string('governing_law_country', 2)->nullable();
            $table->string('dispute_forum', 200)->nullable();

            $table->unsignedBigInteger('value_minor')->nullable();
            $table->string('currency', 3)->nullable();

            $table->string('status', 30)->default('draft');
            $table->unsignedBigInteger('document_id')->nullable();

            $table->string('clause_analysis_status', 20)->default('not_started');

            // Denormalised count of applicable blocking clauses that are absent
            // or partial without a waiver. The activation guard reads the
            // clause rows, not this — this is for the register list, which must
            // not join three tables per row to colour a badge.
            $table->unsignedSmallInteger('blocking_gaps_count')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'engagement_id']);
            $table->index(['organization_id', 'expiry_date']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('tp_clause_library', function (Blueprint $table) {
            $table->id();
            // Null for system-owned regulatory clauses.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('code', 40);
            $table->string('title', 255);
            $table->string('category', 60)->nullable();
            $table->string('regulatory_source', 120)->nullable();

            // The citation shown to a user when the clause is missing. Stored
            // per clause because a wrong citation on a compliance screen is
            // worse than none: it tells a client it must do something a
            // regulator never said.
            $table->string('citation', 255)->nullable();

            $table->json('applicability_rule')->nullable();
            $table->boolean('is_blocking')->default(false);

            $table->text('model_text')->nullable();
            $table->text('guidance')->nullable();
            $table->json('framework_maps')->nullable();

            $table->string('version', 20)->default('1.0');
            $table->string('status', 20)->default('published');

            // A tenant may add clauses and edit model text, but may not delete
            // a system regulatory clause — only waive it per instance, with
            // approval. This flag is what the policy checks.
            $table->boolean('is_system_owned')->default(false);

            $table->timestamps();

            $table->unique(['organization_id', 'code', 'version']);
            $table->index(['organization_id', 'is_blocking']);
        });

        Schema::create('tp_contract_clauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('tp_contracts')->cascadeOnDelete();
            $table->foreignId('clause_library_id')->constrained('tp_clause_library');

            $table->string('presence', 30)->default('absent');
            $table->text('located_text')->nullable();
            $table->string('page_reference', 60)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();

            // ai | manual. An AI detection is a proposal until a reviewer
            // accepts it; `reviewer_status` is what the activation gate reads,
            // never `presence` alone.
            $table->string('detected_by', 20)->default('manual');
            $table->string('reviewer_status', 20)->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->unsignedBigInteger('gap_finding_id')->nullable();

            // A waiver on a blocking gap. Nullable FK added in part 9 with the
            // waiver register; the rationale, approver and expiry live there so
            // that every override in the module — tier, clause, due diligence,
            // access — appears on one report.
            $table->unsignedBigInteger('waiver_id')->nullable();

            $table->timestamps();

            $table->unique(['contract_id', 'clause_library_id']);
            $table->index(['organization_id', 'presence']);
        });

        Schema::create('tp_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('tp_contracts')->nullOnDelete();
            $table->foreignId('engagement_id')->constrained('tp_engagements')->cascadeOnDelete();

            // contract | regulation | policy | assessment | finding. A CUEC
            // from a SOC 2 arrives here as source `assessment`, which is how a
            // duty the auditor assumed we perform becomes a duty someone owns.
            $table->string('source', 30);
            $table->string('source_reference', 200)->nullable();

            $table->string('title', 255);
            $table->text('description')->nullable();

            // Which side owes it. Obligations run both ways, and the ones WE
            // owe are the ones an institution discovers it has breached.
            $table->string('obligor', 20);

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('frequency', 20)->default('one_off');
            $table->date('due_date')->nullable();
            $table->date('next_due_date')->nullable();

            $table->boolean('evidence_required')->default(false);
            $table->unsignedBigInteger('evidence_document_id')->nullable();

            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('breach_count')->default(0);
            $table->string('citation', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'engagement_id', 'status']);
            $table->index(['organization_id', 'next_due_date']);
            $table->index(['organization_id', 'obligor', 'status']);
        });

        Schema::create('tp_slas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('tp_contracts')->nullOnDelete();
            $table->foreignId('engagement_id')->constrained('tp_engagements')->cascadeOnDelete();

            $table->string('metric_code', 60);
            $table->string('metric_name', 200);
            $table->string('unit', 40)->nullable();

            // gte | lte | eq. The operator is stored rather than inferred from
            // the metric name: 99.9% availability is a floor and 4 hours
            // resolution time is a ceiling, and guessing which from the label
            // is how a breach detector reports the opposite of the truth.
            $table->string('target_operator', 10);
            $table->decimal('target_value', 12, 4);

            $table->string('measurement_window', 20)->default('monthly');
            $table->string('data_source', 20)->default('manual');
            $table->text('penalty_terms')->nullable();
            $table->text('credit_formula')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'engagement_id', 'is_active']);
        });

        Schema::create('tp_sla_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sla_id')->constrained('tp_slas')->cascadeOnDelete();

            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('actual_value', 12, 4);

            // Computed on write by comparing against the SLA's operator and
            // target, then stored — so that changing a target next year does
            // not retrospectively un-breach last year.
            $table->boolean('is_breach')->default(false);
            $table->string('breach_severity', 20)->nullable();

            $table->unsignedBigInteger('credit_claimed_minor')->nullable();
            $table->unsignedBigInteger('credit_received_minor')->nullable();
            $table->string('currency', 3)->nullable();

            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('evidence_document_id')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['sla_id', 'period_start', 'period_end'], 'tp_sla_period_unique');
            $table->index(['organization_id', 'is_breach']);
        });

        Schema::create('tp_pci_responsibility_matrix', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained('tp_engagements')->cascadeOnDelete();

            // The PCI DSS v4.0.1 requirement, e.g. "3.5.1" or "12.8.2".
            $table->string('pci_requirement', 20);

            // tpsp | entity | shared | na — PCI DSS 12.8.5, the matrix a QSA
            // asks for and almost nobody has.
            $table->string('responsibility', 20);
            $table->text('notes')->nullable();

            // manual | caiq_ssrm. Pre-population from a CAIQ SSRM answer is a
            // proposal like every other machine-derived value; the source
            // column is what lets a QSA see which rows a person confirmed.
            $table->string('source', 20)->default('manual');
            $table->timestamp('last_confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['engagement_id', 'pci_requirement'], 'tp_pci_matrix_unique');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('tp_contracts', function (Blueprint $table) {
                $table->foreign('document_id')->references('id')->on('tp_documents')->nullOnDelete();
            });
            Schema::table('tp_obligations', function (Blueprint $table) {
                $table->foreign('evidence_document_id')->references('id')->on('tp_documents')->nullOnDelete();
            });
            Schema::table('tp_sla_measurements', function (Blueprint $table) {
                $table->foreign('evidence_document_id')->references('id')->on('tp_documents')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('tp_sla_measurements', function (Blueprint $table) {
                $table->dropForeign(['evidence_document_id']);
            });
            Schema::table('tp_obligations', function (Blueprint $table) {
                $table->dropForeign(['evidence_document_id']);
            });
            Schema::table('tp_contracts', function (Blueprint $table) {
                $table->dropForeign(['document_id']);
            });
        }

        Schema::dropIfExists('tp_pci_responsibility_matrix');
        Schema::dropIfExists('tp_sla_measurements');
        Schema::dropIfExists('tp_slas');
        Schema::dropIfExists('tp_obligations');
        Schema::dropIfExists('tp_contract_clauses');
        Schema::dropIfExists('tp_clause_library');
        Schema::dropIfExists('tp_contracts');
    }
};

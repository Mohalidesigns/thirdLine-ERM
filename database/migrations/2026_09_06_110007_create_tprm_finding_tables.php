<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 0, part 7 of 9 — findings, risk acceptances, incidents,
 * connections and access grants (TRD §8.7).
 *
 * `tp_findings` IS NOT `issues`, AND THE TWO ARE LINKED RATHER THAN MERGED.
 * The product's `issues` table is the institution's own issue and action
 * register, with its own escalation rules, progress updates and closure
 * approval chain. A TPRM finding is a gap in a THIRD PARTY's control
 * environment: it is owned internally but remediated by somebody outside the
 * organisation, it carries a vendor-facing owner and a vendor response, and it
 * contributes points to a residual score through the FU term in TRD §7.5. Put
 * it in `issues` and every issue report in the product starts counting other
 * companies' control gaps as the bank's own open issues.
 *
 * So `erm_issue_id` is a link, and the sync is deliberately narrow: a finding
 * posts to the issue register with a back-link, and CLOSURE syncs both ways.
 * Everything else stays one-way. Two registers each free to reopen the other's
 * records is a loop, not an integration.
 *
 * `tp_incidents` carries the two regulatory clocks (AC-07) as stored deadline
 * columns rather than computed ones. `ndpc_deadline_at` is set once, when the
 * clock starts, from the recorded start event — and never recomputed, because
 * a countdown that shifts when somebody edits a timestamp is not a countdown.
 * NOTHING IN THIS MODULE EVER AUTO-SUBMITS TO A REGULATOR: the drafts are
 * pre-filled, and `*_reported_at` is set by an explicit approval action taken
 * by a named, permissioned officer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_findings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->nullable()->constrained('tp_engagements')->cascadeOnDelete();
            $table->foreignId('third_party_id')->constrained('tp_third_parties')->cascadeOnDelete();

            // assessment | evidence | contract | monitoring | incident |
            // site_visit | audit | manual, with the id of whatever produced it.
            $table->string('source', 30);
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('reference', 30);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('severity', 20);

            $table->json('control_refs')->nullable();
            $table->string('regulatory_citation', 255)->nullable();

            $table->timestamp('identified_at');

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('vendor_owner_contact_id')->nullable()->constrained('tp_contacts')->nullOnDelete();

            // `target_date` is set from the tier policy's SLA for this
            // severity at the moment the finding is raised, and then left
            // alone. Recomputing it when a policy changes would move every
            // historic finding's overdue status, and "we were compliant under
            // the old policy" is a claim a supervisor is entitled to test.
            $table->date('target_date')->nullable();
            $table->unsignedSmallInteger('sla_days')->nullable();

            $table->string('status', 30)->default('open');
            $table->text('remediation_plan')->nullable();
            $table->text('vendor_response')->nullable();
            $table->unsignedBigInteger('evidence_document_id')->nullable();

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('closure_type', 30)->nullable();
            $table->unsignedBigInteger('risk_acceptance_id')->nullable();

            /* --- Suite links (TRD §15) ------------------------------------ */

            // The issue this finding is mirrored as in the ERM issue register.
            $table->foreignId('erm_issue_id')->nullable()->constrained('issues')->nullOnDelete();
            $table->timestamp('erm_synced_at')->nullable();

            // ThirdLine internal audit: an audit finding against a vendor
            // creates a TPRM finding, and a TPRM finding can be flagged for
            // audit verification. Behind a feature flag; a TPRM-only
            // deployment leaves this null forever and nothing notices.
            $table->unsignedBigInteger('thirdline_issue_id')->nullable();
            $table->boolean('flagged_for_audit_verification')->default(false);

            $table->unsignedSmallInteger('escalation_level')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            // TRD §8.10 names this one. It is the Kanban board's query and the
            // FU recomputation's query.
            $table->index(['organization_id', 'status', 'target_date']);
            $table->index(['organization_id', 'engagement_id', 'status']);
            $table->index(['organization_id', 'severity', 'status']);
        });

        Schema::create('tp_risk_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finding_id')->constrained('tp_findings')->cascadeOnDelete();

            $table->text('justification');
            $table->text('compensating_controls')->nullable();
            $table->text('residual_impact')->nullable();

            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approver_role', 120)->nullable();
            $table->timestamp('approved_at')->nullable();

            // MANDATORY, not nullable in practice — the Form Request requires
            // it. A risk acceptance without an expiry is a decision nobody
            // revisits, and a scheduled job reopens the finding when this
            // passes. Nullable in the column only so that a draft acceptance
            // can exist before it is approved.
            $table->date('expires_at')->nullable();

            $table->text('review_notes')->nullable();
            $table->string('status', 20)->default('draft');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'finding_id']);
            $table->index(['organization_id', 'expires_at']);
        });

        Schema::create('tp_incidents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('third_party_id')->constrained('tp_third_parties')->cascadeOnDelete();

            // An incident at one vendor can hit several engagements at once.
            $table->json('engagement_ids')->nullable();

            $table->string('reference', 30);
            $table->string('type', 60);
            $table->string('title', 255);
            $table->text('description')->nullable();

            $table->timestamp('detected_at')->nullable();

            // The clock-start evidence. NDPA §40(1) makes the processor's
            // notification to us the event that starts our own 72 hours, so
            // this timestamp is the legally significant one — not when we got
            // round to recording it.
            $table->timestamp('reported_to_us_at')->nullable();
            $table->string('reported_by', 30)->nullable();

            $table->string('severity', 20)->nullable();
            $table->boolean('customer_impact')->default(false);
            $table->unsignedBigInteger('customers_affected')->nullable();
            $table->boolean('personal_data_involved')->default(false);
            $table->unsignedBigInteger('data_subjects_affected')->nullable();

            $table->unsignedBigInteger('estimated_loss_minor')->nullable();
            $table->string('currency', 3)->nullable();

            /* --- CBN 24-hour clock ---------------------------------------- */
            $table->boolean('cbn_reportable')->default(false);
            $table->timestamp('cbn_deadline_at')->nullable();
            $table->timestamp('cbn_reported_at')->nullable();
            $table->string('cbn_reference', 120)->nullable();

            /* --- NDPC 72-hour clock --------------------------------------- */
            $table->boolean('ndpc_reportable')->default(false);
            $table->timestamp('ndpc_deadline_at')->nullable();
            $table->timestamp('ndpc_reported_at')->nullable();
            $table->boolean('data_subject_notification_required')->default(false);
            $table->timestamp('data_subject_notified_at')->nullable();

            $table->text('root_cause')->nullable();
            $table->string('status', 30)->default('open');

            // The loss event this incident posted to the ERM register, with
            // its estimated loss and recovery.
            $table->foreignId('erm_loss_event_id')->nullable()->constrained('loss_events')->nullOnDelete();
            $table->timestamp('erm_synced_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'third_party_id']);
            // The two clock queries: what is running and what is nearly up.
            $table->index(['organization_id', 'ndpc_deadline_at']);
            $table->index(['organization_id', 'cbn_deadline_at']);
        });

        Schema::create('tp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained('tp_engagements')->cascadeOnDelete();

            $table->string('type', 30);
            $table->string('name', 200);
            $table->string('endpoint', 255)->nullable();
            $table->string('direction', 20)->default('bidirectional');
            $table->text('data_flows')->nullable();
            $table->string('encryption', 120)->nullable();
            $table->string('authentication_method', 120)->nullable();
            $table->string('firewall_rule_ref', 120)->nullable();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->string('status', 20)->default('requested');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closure_evidence_document_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // AC-10 reads this: an engagement cannot reach `terminated` while
            // a connection here is open.
            $table->index(['organization_id', 'engagement_id', 'status']);
        });

        Schema::create('tp_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained('tp_engagements')->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained('tp_connections')->nullOnDelete();

            $table->string('grantee_name', 200);
            $table->string('grantee_email', 255)->nullable();
            $table->string('system_name', 200);
            $table->string('access_level', 20);
            $table->text('justification')->nullable();

            // CBN Cyber Framework Appendix III §1.3 requires senior-management
            // approval, a validity window, an escort where physical, and a
            // stated monitoring method. All four are columns because all four
            // are what an examiner asks to see.
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('escort_required')->default(false);
            $table->string('monitoring_method', 200)->nullable();

            $table->string('status', 20)->default('active');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('revocation_evidence_document_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'engagement_id', 'status']);
            // The reconciliation report: grants still active past their end
            // date, or against engagements that are not.
            $table->index(['organization_id', 'status', 'valid_to']);
        });

        /* ------------------------------------------------------------------ */
        /*  CBN Cyber §2.3 recurring obligations, each with a next-due date    */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_awareness_attestations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('third_party_id')->constrained('tp_third_parties')->cascadeOnDelete();
            $table->foreignId('engagement_id')->nullable()->constrained('tp_engagements')->nullOnDelete();

            $table->string('programme_name', 200);
            $table->date('delivered_at')->nullable();
            $table->unsignedInteger('participants')->nullable();
            $table->unsignedBigInteger('evidence_document_id')->nullable();

            // CBN Cyber §2.3(ii): annual. The next-due date is what makes the
            // requirement monitorable rather than a box ticked once in 2023.
            $table->date('next_due_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'third_party_id']);
            $table->index(['organization_id', 'next_due_at']);
        });

        Schema::create('tp_bcp_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained('tp_engagements')->cascadeOnDelete();

            $table->date('test_date');
            $table->string('test_type', 30);
            $table->text('scope')->nullable();

            // CBN Cyber §2.3(vii) expects the institution to participate, not
            // merely to receive a report. A test we did not attend is weaker
            // evidence and the column is what lets the score say so.
            $table->boolean('our_participation')->default(false);

            $table->unsignedInteger('rto_achieved_hours')->nullable();
            $table->unsignedInteger('rpo_achieved_hours')->nullable();
            $table->string('outcome', 40)->nullable();
            $table->json('findings_raised')->nullable();
            $table->unsignedBigInteger('evidence_document_id')->nullable();
            $table->date('next_due_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'engagement_id', 'test_date']);
            $table->index(['organization_id', 'next_due_at']);
        });

        Schema::create('tp_insurance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('third_party_id')->constrained('tp_third_parties')->cascadeOnDelete();

            $table->string('cover_type', 40);
            $table->string('insurer', 200)->nullable();
            $table->string('policy_number', 120)->nullable();
            $table->unsignedBigInteger('limit_of_indemnity_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('expires_at')->nullable();
            $table->unsignedBigInteger('evidence_document_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'third_party_id', 'cover_type'], 'tp_insurance_cover_idx');
            $table->index(['organization_id', 'expires_at']);
        });

        /* ------------------------------------------------------------------ */
        /*  Deferred foreign keys                                              */
        /* ------------------------------------------------------------------ */

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('tp_soc2_exceptions', function (Blueprint $table) {
                $table->foreign('linked_finding_id')->references('id')->on('tp_findings')->nullOnDelete();
            });
            Schema::table('tp_contract_clauses', function (Blueprint $table) {
                $table->foreign('gap_finding_id')->references('id')->on('tp_findings')->nullOnDelete();
            });
            Schema::table('tp_findings', function (Blueprint $table) {
                $table->foreign('evidence_document_id')->references('id')->on('tp_documents')->nullOnDelete();
                $table->foreign('risk_acceptance_id')->references('id')->on('tp_risk_acceptances')->nullOnDelete();
            });
            foreach ([
                'tp_connections' => 'closure_evidence_document_id',
                'tp_access_grants' => 'revocation_evidence_document_id',
                'tp_awareness_attestations' => 'evidence_document_id',
                'tp_bcp_tests' => 'evidence_document_id',
                'tp_insurance_policies' => 'evidence_document_id',
            ] as $tableName => $column) {
                Schema::table($tableName, function (Blueprint $table) use ($column) {
                    $table->foreign($column)->references('id')->on('tp_documents')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            foreach ([
                'tp_insurance_policies' => 'evidence_document_id',
                'tp_bcp_tests' => 'evidence_document_id',
                'tp_awareness_attestations' => 'evidence_document_id',
                'tp_access_grants' => 'revocation_evidence_document_id',
                'tp_connections' => 'closure_evidence_document_id',
            ] as $tableName => $column) {
                Schema::table($tableName, function (Blueprint $table) use ($column) {
                    $table->dropForeign([$column]);
                });
            }
            Schema::table('tp_findings', function (Blueprint $table) {
                $table->dropForeign(['risk_acceptance_id']);
                $table->dropForeign(['evidence_document_id']);
            });
            Schema::table('tp_contract_clauses', function (Blueprint $table) {
                $table->dropForeign(['gap_finding_id']);
            });
            Schema::table('tp_soc2_exceptions', function (Blueprint $table) {
                $table->dropForeign(['linked_finding_id']);
            });
        }

        Schema::dropIfExists('tp_insurance_policies');
        Schema::dropIfExists('tp_bcp_tests');
        Schema::dropIfExists('tp_awareness_attestations');
        Schema::dropIfExists('tp_access_grants');
        Schema::dropIfExists('tp_connections');
        Schema::dropIfExists('tp_incidents');
        Schema::dropIfExists('tp_risk_acceptances');
        Schema::dropIfExists('tp_findings');
    }
};

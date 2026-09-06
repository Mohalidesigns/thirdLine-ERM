<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 0, part 8 of 9 — monitoring, the nth-party graph, concentration
 * and exit (TRD §8.8).
 *
 * `tp_monitoring_signals.dedupe_key` IS UNIQUE, and it carries more weight
 * than a housekeeping constraint. A monitoring console that shows the same
 * expiring certificate every night for ninety nights is a console people stop
 * reading, and a stream nobody reads is worse than no stream, because the
 * programme still claims continuous monitoring. The key is composed by the
 * generator from the subject, the signal type and the period it refers to, so
 * that the same fact observed twice is one row observed twice.
 *
 * `tp_nth_party_edges` is a graph, and the two things that make it useful
 * rather than decorative are `disclosure_source` and `confirmation_status`.
 * An edge known only from a SOC 2 carve-out or a discovery run, and never from
 * the vendor's own declaration, is an UNDECLARED sub-processor — and that is a
 * finding about the vendor's disclosure obligations, not a gap in our data. A
 * cycle guard belongs in the Action rather than the schema: no database in use
 * here can express "reject an edge creating a path from child back to parent".
 *
 * `tp_exit_plans.next_test_due` is what AC-11 hangs off. A Critical
 * engagement whose plan was last tested thirteen months ago against a
 * twelve-month policy goes `stale`, raises a High finding and adds 4 points of
 * SU. Exit is the lifecycle stage every competitor skips, and it is skipped
 * because untested plans look identical to tested ones until the day they are
 * needed. This column is the difference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_monitoring_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('driver', 60);
            $table->string('name', 200);
            $table->boolean('is_enabled')->default(false);

            // Encrypted at rest by the model's cast. Never rendered, never
            // logged, never returned to a screen — the settings form writes
            // and the source health panel reports only whether it is set.
            $table->text('credentials')->nullable();

            $table->json('config')->nullable();
            $table->json('signal_types')->nullable();
            $table->string('refresh_cron', 60)->nullable();

            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 30)->nullable();
            $table->text('last_error')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'driver', 'name'], 'tp_source_unique');
            $table->index(['organization_id', 'is_enabled']);
        });

        Schema::create('tp_monitoring_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Null for internally derived signals — they have no external
            // source and inventing a synthetic source row for them would put a
            // permanently green entry on the source-health panel that
            // represents nothing anyone configured.
            $table->foreignId('source_id')->nullable()->constrained('tp_monitoring_sources')->nullOnDelete();

            $table->foreignId('third_party_id')->nullable()->constrained('tp_third_parties')->cascadeOnDelete();
            $table->foreignId('engagement_id')->nullable()->constrained('tp_engagements')->cascadeOnDelete();

            $table->string('signal_type', 40);
            $table->string('severity', 20);
            $table->string('title', 255);
            $table->json('payload')->nullable();
            $table->string('url', 500)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();

            $table->timestamp('observed_at');
            $table->timestamp('ingested_at')->nullable();
            $table->boolean('is_processed')->default(false);

            // TRD §8.10 requires this unique.
            $table->string('dedupe_key', 191);

            // AI triage output on adverse media. Null when triage is off,
            // which is the default — and a null here means "not assessed",
            // never "not relevant".
            $table->decimal('ai_relevance', 4, 3)->nullable();
            $table->string('ai_materiality', 30)->nullable();

            $table->timestamps();

            $table->unique('dedupe_key');
            $table->index(['organization_id', 'third_party_id', 'observed_at'], 'tp_signal_party_idx');
            $table->index(['organization_id', 'signal_type', 'is_processed'], 'tp_signal_type_idx');
        });

        Schema::create('tp_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 200);
            $table->json('signal_types');

            // A Phase 0 DSL condition, and a scope limiting which engagements
            // the rule watches.
            $table->json('condition')->nullable();
            $table->json('scope')->nullable();

            // notify | create_task | create_finding | adjust_residual |
            // targeted_assessment | escalate | suspend_engagement.
            $table->json('actions');

            $table->string('severity', 20)->default('medium');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('cooldown_hours')->default(24);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'is_enabled']);
        });

        Schema::create('tp_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('tp_alert_rules')->nullOnDelete();
            $table->foreignId('signal_id')->nullable()->constrained('tp_monitoring_signals')->nullOnDelete();
            $table->foreignId('third_party_id')->nullable()->constrained('tp_third_parties')->cascadeOnDelete();
            $table->foreignId('engagement_id')->nullable()->constrained('tp_engagements')->cascadeOnDelete();

            $table->string('severity', 20);
            $table->string('status', 20)->default('new');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->json('actions_taken')->nullable();

            // Both mandatory when muting. A mute with no expiry is how a
            // monitoring programme quietly stops covering what it claims to.
            $table->timestamp('muted_until')->nullable();
            $table->text('mute_reason')->nullable();

            $table->foreignId('created_finding_id')->nullable()->constrained('tp_findings')->nullOnDelete();
            $table->foreignId('created_assessment_id')->nullable()->constrained('tp_assessments')->nullOnDelete();

            $table->timestamps();

            $table->index(['organization_id', 'status', 'severity']);
            $table->index(['organization_id', 'third_party_id']);
        });

        /* ------------------------------------------------------------------ */
        /*  Nth-party graph and concentration                                  */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_nth_party_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_third_party_id')->constrained('tp_third_parties')->cascadeOnDelete();

            // Null while the sub-processor is only a name. A vendor's SOC 2
            // naming "a large public cloud provider" is a real disclosure and
            // must be recordable before anyone matches it to a register row.
            $table->foreignId('child_third_party_id')->nullable()->constrained('tp_third_parties')->nullOnDelete();
            $table->string('child_name_raw', 255);

            $table->foreignId('engagement_id')->nullable()->constrained('tp_engagements')->nullOnDelete();

            // Distance from us: 1 is our vendor's vendor.
            $table->unsignedSmallInteger('rank')->default(1);

            $table->text('service_description')->nullable();
            $table->json('data_categories')->nullable();
            $table->string('country_of_processing', 2)->nullable();
            $table->string('criticality', 20)->nullable();

            $table->string('disclosure_source', 30);
            $table->date('disclosed_at')->nullable();
            $table->string('confirmation_status', 20)->default('proposed');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->date('ceased_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'parent_third_party_id', 'is_active'], 'tp_edge_parent_idx');
            $table->index(['organization_id', 'child_third_party_id'], 'tp_edge_child_idx');
            $table->index(['organization_id', 'confirmation_status']);
        });

        Schema::create('tp_concentration_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->timestamp('run_at');
            $table->string('dimension', 30);

            $table->json('results');
            $table->decimal('hhi', 8, 2)->nullable();
            $table->json('spof_list')->nullable();
            $table->json('threshold_breaches')->nullable();

            // A run is a snapshot. It is never updated: comparing this
            // quarter's concentration to last quarter's requires last
            // quarter's numbers to have survived unchanged.
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'dimension', 'run_at'], 'tp_conc_dim_idx');
        });

        /* ------------------------------------------------------------------ */
        /*  Exit                                                               */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_exit_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained('tp_engagements')->cascadeOnDelete();

            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);

            $table->json('trigger_scenarios')->nullable();
            $table->json('alternative_providers')->nullable();
            $table->text('in_house_option')->nullable();

            // Step, owner, duration_days, dependencies.
            $table->json('transition_steps')->nullable();
            $table->unsignedInteger('estimated_duration_days')->nullable();
            $table->unsignedBigInteger('estimated_cost_minor')->nullable();
            $table->string('currency', 3)->nullable();

            $table->string('data_extraction_format', 120)->nullable();

            // An untested extraction format is a plan to discover on the day
            // that the export is a PDF of a screen.
            $table->boolean('data_extraction_tested')->default(false);

            $table->text('communication_plan')->nullable();
            $table->text('residual_risk_during_transition')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->date('last_tested_at')->nullable();
            $table->date('next_test_due')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'engagement_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'next_test_due']);
        });

        Schema::create('tp_exit_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exit_plan_id')->constrained('tp_exit_plans')->cascadeOnDelete();

            $table->date('test_date');
            $table->string('test_type', 30);
            $table->json('participants')->nullable();
            $table->text('scenario')->nullable();
            $table->string('outcome', 40)->nullable();
            $table->json('gaps_identified')->nullable();
            $table->unsignedBigInteger('evidence_document_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'exit_plan_id', 'test_date'], 'tp_exit_test_idx');
        });

        Schema::create('tp_offboarding_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained('tp_engagements')->cascadeOnDelete();

            $table->string('status', 20)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'engagement_id']);
        });

        Schema::create('tp_offboarding_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_id')->constrained('tp_offboarding_checklists')->cascadeOnDelete();

            $table->string('code', 60);
            $table->string('title', 255);

            // data_return | data_destruction | access_revocation |
            // connection_closure | asset_return | licence_return |
            // final_reconciliation | records_retention | knowledge_transfer |
            // customer_communication.
            $table->string('item_type', 40);

            $table->boolean('is_mandatory')->default(true);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('evidence_document_id')->nullable();

            $table->text('exception_reason')->nullable();
            $table->foreignId('exception_approver_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'checklist_id', 'status'], 'tp_offb_item_idx');
        });

        /* ------------------------------------------------------------------ */
        /*  Deferred foreign keys                                              */
        /* ------------------------------------------------------------------ */

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('tp_soc2_subservice_orgs', function (Blueprint $table) {
                $table->foreign('proposed_nth_party_edge_id')->references('id')->on('tp_nth_party_edges')->nullOnDelete();
            });
            foreach ([
                'tp_exit_plans' => 'document_id',
                'tp_exit_tests' => 'evidence_document_id',
                'tp_offboarding_items' => 'evidence_document_id',
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
                'tp_offboarding_items' => 'evidence_document_id',
                'tp_exit_tests' => 'evidence_document_id',
                'tp_exit_plans' => 'document_id',
            ] as $tableName => $column) {
                Schema::table($tableName, function (Blueprint $table) use ($column) {
                    $table->dropForeign([$column]);
                });
            }
            Schema::table('tp_soc2_subservice_orgs', function (Blueprint $table) {
                $table->dropForeign(['proposed_nth_party_edge_id']);
            });
        }

        Schema::dropIfExists('tp_offboarding_items');
        Schema::dropIfExists('tp_offboarding_checklists');
        Schema::dropIfExists('tp_exit_tests');
        Schema::dropIfExists('tp_exit_plans');
        Schema::dropIfExists('tp_concentration_analyses');
        Schema::dropIfExists('tp_nth_party_edges');
        Schema::dropIfExists('tp_alerts');
        Schema::dropIfExists('tp_alert_rules');
        Schema::dropIfExists('tp_monitoring_signals');
        Schema::dropIfExists('tp_monitoring_sources');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-06 TASK 1 — the schema for one workflow engine.
 *
 * The platform had three approval mechanisms and no single answer to "who owes
 * a decision on this, and since when":
 *
 *   approval_requests        generic maker-checker, one reviewer, no stages
 *   per-module columns       risk_assessments.approved_by, treatment_plans.*,
 *                            control_tests.*, loss_events.approved_at
 *   workflow_instances       a linear cursor into a JSON array of stages,
 *                            whose escalation_rules were written and never
 *                            read, and whose delegate/escalate/return actions
 *                            were logged and changed nothing
 *
 * This migration gives the third one a graph instead of a cursor, and a task
 * table so a decision is a row somebody owns rather than an integer nobody
 * does.
 *
 * ADDITIVE. `stages` stays and stays populated for the definitions that have
 * it; `current_stage` stays and is maintained alongside `current_nodes` for a
 * release so the existing instance screen keeps rendering. Nothing is dropped
 * here — the follow-up release removes `stages`, `current_stage` and the
 * approval_requests write path once no code reads them.
 *
 * TWO ENUMS BECOME STRINGS. workflow_instances.status and
 * workflow_actions.action were MySQL enums, so the value set was enforced on
 * production and not on the SQLite the tests run against — the WP-01 lesson
 * from control_tests.status. The engine needs outcomes those enums never had
 * ('expired', 'auto_approved', 'joined'), and adding one should be a code
 * change, not an ALTER TABLE on an audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->upgradeDefinitions();
        $this->upgradeInstances();
        $this->upgradeActions();
        $this->createTasks();

        // The engine writes a loss_event_approvals row per stage decision, and
        // an SLA auto-approval has no actor. Attributing it to whoever the
        // sweeper happens to run as would put a name against a decision that
        // person did not make — in the one table a CBN examiner reads.
        Schema::table('loss_event_approvals', function (Blueprint $table) {
            $table->unsignedBigInteger('actioned_by')->nullable()->change();
        });
    }

    private function upgradeDefinitions(): void
    {
        Schema::table('workflow_definitions', function (Blueprint $table) {
            // A definition is identified by its code and versioned by publish.
            // Running instances pin the version they started on, so editing a
            // published process cannot change a decision already in flight.
            $table->string('code', 80)->nullable()->after('organization_id');
            $table->unsignedInteger('version')->default(1)->after('code');
            $table->boolean('is_published')->default(false)->after('is_active');
            // DATETIME, not TIMESTAMP — see the note on workflow_tasks below.
            $table->dateTime('published_at')->nullable()->after('is_published');
            $table->foreignId('published_by')->nullable()->after('published_at')
                ->constrained('users')->nullOnDelete();
            $table->boolean('is_system')->default(false)->after('published_by');

            // The subject of the process. entity_type (the morph alias) stays
            // authoritative for domain-backed subjects; object_type_id binds a
            // definition to a graph type for objects that have no table of
            // their own — a Policy, an Obligation, any type WP-05 lets a
            // configurer invent.
            $table->foreignId('object_type_id')->nullable()->after('entity_type')
                ->constrained('object_types')->nullOnDelete();

            $table->json('definition')->nullable()->after('stages');
            $table->string('trigger', 30)->default('manual')->after('escalation_rules');
            $table->json('trigger_config')->nullable()->after('trigger');
            $table->json('scope_filter')->nullable()->after('trigger_config');
            $table->longText('bpmn_xml')->nullable()->after('scope_filter');

            $table->index(['organization_id', 'code', 'version'], 'workflow_definitions_code_version_index');
            $table->index(['trigger', 'is_published'], 'workflow_definitions_trigger_index');
        });

        // A seeded system definition has no author. Same reasoning as
        // approval_requests.requested_by in WP-04: a compliance record naming
        // the wrong person is worse than one that admits the platform did it.
        Schema::table('workflow_definitions', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->change();
            $table->json('stages')->nullable()->change();
        });
    }

    private function upgradeInstances(): void
    {
        Schema::table('workflow_instances', function (Blueprint $table) {
            // The instance carried no organization_id and every query reached
            // it through whereHas('definition'), which is a join per read and
            // cannot be indexed. It is also why the model had no tenancy trait.
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
            $table->unsignedInteger('definition_version')->default(1)->after('definition_id');

            // An array, because a parallel gateway puts the instance in
            // several places at once. current_stage cannot express that, which
            // is the reason the old engine could only ever be linear.
            $table->json('current_nodes')->nullable()->after('current_stage');
            $table->json('context')->nullable()->after('current_nodes');

            $table->dateTime('sla_due_at')->nullable()->after('started_at');
            $table->dateTime('breached_at')->nullable()->after('sla_due_at');
            $table->string('correlation_key', 191)->nullable()->after('breached_at');
            $table->string('outcome', 30)->nullable()->after('status');
            $table->text('cancellation_reason')->nullable()->after('outcome');
            $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')
                ->constrained('users')->nullOnDelete();

            $table->index(['organization_id', 'status'], 'workflow_instances_org_status_index');
            $table->index('correlation_key', 'workflow_instances_correlation_index');
            $table->index('sla_due_at', 'workflow_instances_sla_index');
        });

        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->string('status', 30)->default('active')->change();
            $table->unsignedBigInteger('initiated_by')->nullable()->change();

            // started_at is the first TIMESTAMP column in this table, so on
            // MySQL with explicit_defaults_for_timestamp = OFF it carries an
            // implicit ON UPDATE CURRENT_TIMESTAMP. That was survivable while
            // the old engine wrote an instance twice in its life. The v2 engine
            // writes one on every step — entering a node, leaving it, merging
            // context — so every one of those would reset the moment the
            // process began, and "how long has this been in review" would
            // always read as minutes. DATETIME has no such behaviour.
            $table->dateTime('started_at')->nullable()->change();
            $table->dateTime('completed_at')->nullable()->change();
        });

        // Backfill the tenant from the definition rather than from the
        // authenticated user: these rows predate the request that runs the
        // migration, and attributing them to whoever deploys would be wrong.
        DB::table('workflow_instances')->whereNull('organization_id')->update([
            'organization_id' => DB::raw(
                '(select organization_id from workflow_definitions where workflow_definitions.id = workflow_instances.definition_id)'
            ),
        ]);
    }

    private function upgradeActions(): void
    {
        Schema::table('workflow_actions', function (Blueprint $table) {
            $table->string('node_code', 80)->nullable()->after('stage');
            $table->unsignedBigInteger('task_id')->nullable()->after('instance_id');
            $table->index('task_id', 'workflow_actions_task_index');
        });

        Schema::table('workflow_actions', function (Blueprint $table) {
            $table->string('action', 30)->default('approve')->change();
            // A timeout expiring a task is an action with no actor. The column
            // was NOT NULL, which is the reason the old engine could only
            // record things people did.
            $table->unsignedBigInteger('actor_id')->nullable()->change();
            $table->integer('stage')->nullable()->change();
        });
    }

    private function createTasks(): void
    {
        Schema::create('workflow_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->string('node_code', 80);
            $table->string('node_name')->nullable();
            $table->string('node_type', 30)->default('approval');

            // Either a named person or a role the task is offered to. Both may
            // be set: assignee_role records who it was offered to even after a
            // member of that role claims it, so "who could have acted" survives.
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assignee_role', 100)->nullable();

            // A node may offer one decision to several roles ("risk-manager or
            // CRO") or to a named shortlist. assignee_role holds the primary
            // one so the common case stays an indexed equality; these two hold
            // the rest, so the offer is a fact on the task rather than
            // something the reader has to go back to the definition for —
            // which matters once the definition has been versioned past it.
            $table->json('candidate_roles')->nullable();
            $table->json('candidate_user_ids')->nullable();

            $table->string('status', 30)->default('pending');

            // DATETIME, NOT TIMESTAMP — the WP-04 measure_breaches lesson.
            // With explicit_defaults_for_timestamp = OFF (the default on
            // MariaDB and many MySQL installs) the first TIMESTAMP column in a
            // table is silently given ON UPDATE CURRENT_TIMESTAMP. `due_at` is
            // that column here, and a due date that moved every time somebody
            // added a comment would make an SLA unmeasurable — nothing would
            // ever be overdue. escalated_at and completed_at carry the same
            // hazard for MTTR. DATETIME has no automatic behaviour under any
            // server setting. SQLite stores both as text, so the whole class of
            // bug is invisible to the test suite.
            $table->dateTime('due_at')->nullable();
            $table->dateTime('escalated_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('outcome', 30)->nullable();
            $table->text('comments')->nullable();
            $table->foreignId('delegated_from')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delegated_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('form_data')->nullable();
            $table->timestamps();

            // The My Responsibilities query (WP-08) is (assignee, status,
            // due_at) and nothing else, so it is the index.
            $table->index(['assignee_id', 'status', 'due_at'], 'workflow_tasks_assignee_index');
            $table->index(['organization_id', 'assignee_role', 'status'], 'workflow_tasks_role_index');
            $table->index('instance_id', 'workflow_tasks_instance_index');
            $table->index(['status', 'due_at'], 'workflow_tasks_sla_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_tasks');

        Schema::table('workflow_actions', function (Blueprint $table) {
            $table->dropIndex('workflow_actions_task_index');
            $table->dropColumn(['node_code', 'task_id']);
        });

        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->dropIndex('workflow_instances_org_status_index');
            $table->dropIndex('workflow_instances_correlation_index');
            $table->dropIndex('workflow_instances_sla_index');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn([
                'definition_version', 'current_nodes', 'context', 'sla_due_at',
                'breached_at', 'correlation_key', 'outcome', 'cancellation_reason',
            ]);
        });

        Schema::table('workflow_definitions', function (Blueprint $table) {
            $table->dropIndex('workflow_definitions_code_version_index');
            $table->dropIndex('workflow_definitions_trigger_index');
            $table->dropConstrainedForeignId('object_type_id');
            $table->dropConstrainedForeignId('published_by');
            $table->dropColumn([
                'code', 'version', 'is_published', 'published_at', 'is_system',
                'definition', 'trigger', 'trigger_config', 'scope_filter', 'bpmn_xml',
            ]);
        });
    }
};

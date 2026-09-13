<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RCSA v2, P5 — the workflow transition log, and escalation.
 *
 * §9.1: "every transition writes user, timestamp, from-state, to-state and
 * reason". P0 built `rcsa_line_revisions` for the before/after trail on a
 * LINE, and its comment says a status transition could live there too — but
 * that table's `line_id` is NOT NULL, and the transitions that matter most
 * (submit, validate, return, close) are properties of the ASSESSMENT with no
 * line to hang them on. Writing them against an arbitrary line would make the
 * audit read as though one risk had been validated.
 *
 * APPEND-ONLY, like the revisions table: no `updated_at`, no soft delete. A
 * workflow history somebody can edit is not a workflow history. §11 requires
 * audit views to be read-only and non-deletable, and the cheapest way to mean
 * it is a table with nowhere to write a change.
 *
 * P0 LEFT THE EXTENSION REQUEST HALF-BUILT, and P5 is where it showed. The
 * action-plan table has `extension_reason`, `extension_approved_by` and
 * `original_target_date` — the whole of an approved extension — but nowhere to
 * hold the date being ASKED FOR while the request is undecided. Writing it
 * into `target_date` would move the deadline before anyone agreed to it, which
 * is the exact behaviour an extension workflow exists to prevent; a register
 * whose dates slide on request reports 100% on time for ever. Three columns
 * finish it.
 *
 * ESCALATION IS A FLAG, NOT A STATE. §9.2 lists Escalate beside Validate and
 * Return as a reviewer action, but the state machine in §9.1 has no escalated
 * state and the enum has no room for one — an escalated assessment is still
 * under review, it is just under review with the Head of ORM watching. Three
 * columns on the assessment say so, and they are cleared by whichever decision
 * finally lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rcsa_assessment_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained('rcsa_assessments')->cascadeOnDelete();

            // nullOnDelete, not cascade: the scheduler and the cycle-close
            // sweep make transitions with no user, and deleting a leaver must
            // not delete the record of what they validated.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Plain strings, not the assessment's enum. An enum here would have
            // to be altered in lockstep with the status column forever, and a
            // history row's job is to record the name that was used at the
            // time — including one a later version stops offering.
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);

            // The event, where it is not simply the arrival in `to_status`:
            // `escalate` is a from == to row.
            $table->string('event', 32)->nullable();

            $table->text('reason')->nullable();

            // Correlates a transition back to the request that made it, per §11.
            $table->string('request_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'assessment_id']);
            $table->index(['assessment_id', 'created_at']);
        });

        Schema::table('rcsa_action_plans', function (Blueprint $table) {
            $table->date('proposed_target_date')->nullable()->after('original_target_date');
            $table->foreignId('extension_requested_by')->nullable()->after('proposed_target_date')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('extension_requested_at')->nullable()->after('extension_requested_by');
        });

        Schema::table('rcsa_assessments', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('returned_reason');
            $table->foreignId('escalated_by')->nullable()->after('escalated_at')
                ->constrained('users')->nullOnDelete();
            $table->text('escalation_reason')->nullable()->after('escalated_by');
        });
    }

    public function down(): void
    {
        Schema::table('rcsa_action_plans', function (Blueprint $table) {
            $table->date('proposed_target_date')->nullable()->after('original_target_date');
            $table->foreignId('extension_requested_by')->nullable()->after('proposed_target_date')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('extension_requested_at')->nullable()->after('extension_requested_by');
        });

        Schema::table('rcsa_assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('escalated_by');
            $table->dropColumn(['escalated_at', 'escalation_reason']);
        });

        Schema::dropIfExists('rcsa_assessment_transitions');
    }
};

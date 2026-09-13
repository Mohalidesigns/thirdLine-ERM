<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RCSA v2, P0 — cycles, assessments, lines, action plans and the audit tail.
 *
 * `rcsa_assessment_lines` IS A SNAPSHOT, NOT A VIEW. Every piece of master-data
 * text the workbook prints — the process name, the risk statement, the driver,
 * the category, the existing control — is COPIED onto the line when a cycle is
 * opened, alongside the foreign key it came from. This is the single most
 * important decision in the schema and it is not denormalisation for speed.
 *
 * A completed RCSA is a record of what a named person assessed, on a date,
 * about a risk as it was worded at the time. If the line read its text through
 * the foreign key, then editing a risk statement in the universe in November
 * would silently rewrite the assessment the Board Risk Committee signed in
 * June, and the June PDF would no longer match the June row. The foreign keys
 * are kept so that "show me every cycle for this risk" still works; the text is
 * kept so that history cannot be rewritten by a master-data edit.
 *
 * THE COMPUTED COLUMNS ARE STORED, NOT DERIVED ON READ. `inherent_score`,
 * `inherent_level`, `ce_modifier`, `residual_score`, `residual_level`,
 * `risk_treatment` and `appetite_status` are all written by
 * RcsaCalculationService on every save. They are stored for the same reason the
 * text is: the methodology can be re-versioned, and a stored score is what
 * makes a closed cycle reproducible. They are also what every dashboard,
 * filter and export reads, and recomputing five bands in PHP across a
 * 10,000-line export is not a thing to do per row.
 *
 * Deriving them on read would ALSO put the calculation in two places, which
 * §3 of the plan forbids: one service computes, everything else reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* -------------------------------------------------------------- */
        /*  Cycles — step 1 of the flow */
        /* -------------------------------------------------------------- */

        Schema::create('rcsa_cycles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 160);              // "RCSA 2026 H1"
            $table->text('description')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date')->nullable();

            // The methodology every line in this cycle is scored against.
            // restrictOnDelete: a methodology with assessments behind it cannot
            // be deleted out from under them, which is the database half of the
            // is_locked rule.
            $table->foreignId('methodology_id')->constrained('rcsa_methodologies')->restrictOnDelete();

            $table->enum('status', ['draft', 'open', 'in_review', 'closed'])->default('draft');

            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'period_start']);
        });

        /* -------------------------------------------------------------- */
        /*  Assessments — one per cycle × business unit */
        /* -------------------------------------------------------------- */

        Schema::create('rcsa_assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_id')->constrained('rcsa_cycles')->cascadeOnDelete();
            $table->foreignId('business_unit_id')->constrained('business_units')->cascadeOnDelete();

            // The state machine of §9.1. `bu_approval` is the optional BU Head
            // step; a tenant that has not enabled it never enters that state.
            $table->enum('status', [
                'draft', 'in_progress', 'bu_approval', 'submitted',
                'under_review', 'validated', 'returned', 'closed',
            ])->default('draft');

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();

            // Whole percent, recomputed on every line save. Stored so the
            // completion tracker does not count lines across every assessment
            // in the tenant on each dashboard render.
            $table->unsignedTinyInteger('completion_pct')->default(0);

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('returned_reason')->nullable();

            // The PDF rendered at submission — the immutable copy of what was
            // filed, independent of any later re-render.
            $table->string('snapshot_path', 512)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['cycle_id', 'business_unit_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'assigned_to', 'status']);
        });

        /* -------------------------------------------------------------- */
        /*  Lines — the 23 workbook columns plus the system fields */
        /* -------------------------------------------------------------- */

        Schema::create('rcsa_assessment_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained('rcsa_assessments')->cascadeOnDelete();

            // Provenance. nullOnDelete, not cascade: deleting a universe row
            // must never delete the history of it having been assessed.
            $table->foreignId('register_risk_id')->nullable()->constrained('rcsa_register_risks')->nullOnDelete();
            $table->foreignId('business_unit_id')->constrained('business_units')->cascadeOnDelete();
            $table->foreignId('process_id')->nullable()->constrained('business_processes')->nullOnDelete();
            $table->foreignId('sub_process_id')->nullable()->constrained('business_processes')->nullOnDelete();

            /* --- Snapshotted master data: columns A-I and N ------------- */

            $table->string('risk_no', 40);                        // A
            $table->string('business_unit_name', 160);            // B
            $table->string('process_name', 200)->nullable();      // C
            $table->string('sub_process_name', 200)->nullable();  // D
            $table->json('system_names')->nullable();             // E
            $table->text('potential_risk');                       // F
            $table->text('risk_driver')->nullable();              // G
            $table->string('risk_category', 64)->nullable();      // H
            $table->json('secondary_categories')->nullable();     // I
            $table->text('existing_control')->nullable();         // N

            /* --- Assessed: columns J, K, O ------------------------------ */

            $table->unsignedTinyInteger('inherent_likelihood')->nullable();  // J
            $table->unsignedTinyInteger('inherent_impact')->nullable();      // K

            // The control-effectiveness LABEL, not its scale value. The label
            // is what the workbook holds and what the export must write, and
            // the scale's `value` is an ordering (1 = best) that must never be
            // multiplied by anything. The modifier is resolved from the
            // methodology and stored beside it.
            $table->string('control_effectiveness', 64)->nullable();         // O

            /* --- Calculated: columns L, M, P, Q, R, S, T ---------------- */

            $table->unsignedTinyInteger('inherent_score')->nullable();       // L
            $table->string('inherent_level', 32)->nullable();                // M
            $table->unsignedTinyInteger('ce_modifier')->nullable();          // P

            // decimal(5,2), per defect D3: residual is fractional (12.5 is a
            // real value) and is banded on the raw number, never on a rounded
            // one. Display rounds to 1dp; storage does not.
            $table->decimal('residual_score', 5, 2)->nullable();             // Q
            $table->string('residual_level', 32)->nullable();                // R
            $table->string('risk_treatment', 32)->nullable();                // S
            $table->string('appetite_status', 160)->nullable();              // T

            // ASSESSED mode only: the assessor's own residual pair. Null in
            // calculated mode, which is the seeded default.
            $table->unsignedTinyInteger('residual_likelihood')->nullable();
            $table->unsignedTinyInteger('residual_impact')->nullable();

            // Set when the assessor overrides the calculated treatment. The
            // justification is mandatory when it differs — enforced by the
            // submission gate, not by the column, so that a half-filled draft
            // can still be saved.
            $table->string('treatment_override', 32)->nullable();
            $table->text('treatment_override_reason')->nullable();

            /* --- System fields (§4) ------------------------------------- */

            $table->foreignId('assessor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at')->nullable();
            $table->text('assessment_rationale')->nullable();
            $table->json('evidence_attachments')->nullable();

            $table->text('orm_comment')->nullable();
            $table->enum('orm_status', ['pending', 'accepted', 'flagged', 'challenged'])->default('pending');
            $table->foreignId('orm_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('orm_reviewed_at')->nullable();

            // The same risk's line in the previous cycle, so the workspace can
            // show last quarter's answer and flag material movement.
            $table->foreignId('prior_cycle_line_id')->nullable()->constrained('rcsa_assessment_lines')->nullOnDelete();

            $table->foreignId('methodology_id')->constrained('rcsa_methodologies')->restrictOnDelete();

            $table->string('row_hash', 64)->nullable();

            // Optimistic locking. Every PATCH carries the version it read; a
            // mismatch is a 409, not a silent overwrite. Two risk champions in
            // the same unit editing the same grid is the normal case, not the
            // edge case.
            $table->unsignedInteger('version')->default(1);

            // Soft, advisory lock while someone has the line open in guided
            // mode. Distinct from `locked_at`, which is permanent.
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lock_expires_at')->nullable();

            // Set at submission. A locked line is read-only until the ORM
            // returns it for rework.
            $table->timestamp('locked_at')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'assessment_id']);
            $table->index(['assessment_id', 'sort_order']);
            $table->index(['organization_id', 'residual_level']);
            $table->index(['organization_id', 'risk_treatment']);
            $table->index('register_risk_id');
            $table->index('prior_cycle_line_id');
        });

        /* -------------------------------------------------------------- */
        /*  Action plans — columns U, V, W */
        /* -------------------------------------------------------------- */

        Schema::create('rcsa_action_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Defect D5. The workbook has ONE "Control to be Implemented" cell
            // per risk; a risk above appetite routinely needs three actions
            // with three owners and three dates. This is a child table, and the
            // export flattens multiple rows back into the single column,
            // newline-separated, for template parity.
            $table->foreignId('line_id')->constrained('rcsa_assessment_lines')->cascadeOnDelete();

            $table->text('control_to_implement');                              // U
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete(); // V
            $table->date('target_date')->nullable();                           // W

            // `overdue` is NOT a stored status anyone sets by hand — it is what
            // the reminder job writes when target_date passes on an open plan,
            // so that "overdue" means one thing on the dashboard, in the export
            // and in the Board pack.
            $table->enum('status', ['open', 'in_progress', 'completed', 'overdue', 'closed'])
                ->default('open');

            $table->unsignedTinyInteger('progress_pct')->default(0);
            $table->text('completion_evidence')->nullable();
            $table->json('evidence_attachments')->nullable();

            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            // ORM verification of closure — a plan the owner marks done is not
            // a plan the second line has accepted.
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->date('original_target_date')->nullable();
            $table->text('extension_reason')->nullable();
            $table->foreignId('extension_approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'owner_id', 'status']);
            $table->index(['organization_id', 'target_date']);
            $table->index('line_id');
        });

        /* -------------------------------------------------------------- */
        /*  ORM challenge / response */
        /* -------------------------------------------------------------- */

        Schema::create('rcsa_line_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('line_id')->constrained('rcsa_assessment_lines')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('rcsa_line_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('type', ['comment', 'challenge', 'response'])->default('comment');
            $table->text('body');

            // A challenge may carry a suggested rating without applying it.
            $table->json('suggested_values')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'line_id']);
        });

        /* -------------------------------------------------------------- */
        /*  Revisions — rule 7 of the process flow */
        /* -------------------------------------------------------------- */

        Schema::create('rcsa_line_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('line_id')->constrained('rcsa_assessment_lines')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Which of the material fields changed: J, K, O, Q, S, U, V, W or a
            // status transition. Stored as the field name, not the column
            // letter, because the column letter is an export concern.
            $table->string('field', 64);
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('reason')->nullable();

            // Correlates a change back to the request that made it, per §11.
            $table->string('request_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();

            // No updated_at and no soft delete: this table is append-only. A
            // revision that can be edited or removed is not an audit trail.
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'line_id']);
            $table->index(['line_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rcsa_line_revisions');
        Schema::dropIfExists('rcsa_line_comments');
        Schema::dropIfExists('rcsa_action_plans');
        Schema::dropIfExists('rcsa_assessment_lines');
        Schema::dropIfExists('rcsa_assessments');
        Schema::dropIfExists('rcsa_cycles');
    }
};

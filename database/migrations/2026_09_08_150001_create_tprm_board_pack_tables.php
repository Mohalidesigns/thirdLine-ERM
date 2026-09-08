<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 10 — the Board and Risk Committee pack (FR-RPT-05).
 *
 * A BOARD PACK IS A SNAPSHOT, NOT A VIEW, and that is why it is a table
 * rather than a query. TRD §7.9 is explicit that a score is never recomputed
 * retrospectively: the pack the committee approved in March must reprint in
 * June exactly as it was read, or the minutes reference figures the system can
 * no longer produce. `figures` holds the numbers as at the pack's own as-at
 * date, and `engine_version` records the scoring rules they were computed
 * under, the same way `tp_score_runs` does.
 *
 * THE NARRATIVE IS THE OTHER REASON. FR-RPT-05 asks for an AI-drafted
 * narrative "the CRO edits before sign-off", and an edit that is not stored is
 * not an edit. `narrative_source` records whether the text on the page was
 * assembled deterministically, refined by a model, or written by a person —
 * because a committee reading a paragraph about its own third-party exposure
 * is entitled to know which.
 *
 * SIGN-OFF IS A SEPARATE ACT FROM PREPARATION, and both are named. The pattern
 * is the one Phase 9 used for regulatory notifications: assembling a document
 * and standing behind it are different decisions, held by different people,
 * and collapsing them into one timestamp loses the only fact an auditor asks
 * about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_board_packs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // "Q3 2026", "H1 2027" — a label the committee recognises, not a
            // date range this product invents.
            $table->string('period_label', 40);

            // The position the figures describe. Separate from created_at: a
            // pack produced in April for the March position must say March.
            $table->date('as_at');

            $table->string('status', 20)->default('draft');

            $table->longText('narrative')->nullable();
            $table->string('narrative_source', 20)->nullable();
            // dateTime rather than timestamp: MySQL gives only the FIRST
            // non-nullable TIMESTAMP an implicit default and rejects a second
            // under strict mode (Phase 9 found this the hard way).
            $table->dateTime('narrative_generated_at')->nullable();
            $table->foreignId('narrative_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('narrative_edited_at')->nullable();

            // The snapshot. Every figure the pack prints, as at `as_at`.
            $table->json('figures')->nullable();
            $table->string('engine_version', 20)->nullable();

            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('prepared_at')->nullable();

            $table->foreignId('signed_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('signed_off_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // One pack per period. A committee with two packs for Q3 has two
            // versions of the truth and no way to tell which was tabled.
            $table->unique(['organization_id', 'period_label'], 'tp_board_packs_period_unique');
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'as_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_board_packs');
    }
};

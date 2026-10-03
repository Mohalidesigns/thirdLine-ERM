<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BCMS Phase 9 — evidence gets one table (ADR 0019).
 *
 * `bcms_exercise_scores.evidence_file_id` and `bcms_readiness_tasks.
 * evidence_file_id` are retired IN PLACE, not dropped (ADR 0019 §3): nothing
 * writes them from this phase forward, but dropping is itself a structural
 * change with a rollback that loses data, and a nullable column nothing writes
 * costs nothing. They stay in the manifest, marked, until a single later
 * cleanup migration drops both.
 *
 * `occurrence_id` is NOT NULL — the single anchor path (ADR 0019 §4):
 * `orgAnchorPath()` = `occurrence.definition`, the same path
 * `ExerciseParticipant` and `ReadinessTask` take.
 *
 * SIX THINGS THIS TABLE DELIBERATELY DOES NOT HAVE, per ADR 0019 §1: no `disk`
 * column (one disk by construction, `FileUploadService::DISK`), no
 * `virus_scan_status` (a column that always says `pending` is the mock-tick
 * this module has twice refused to ship), no path for system-generated
 * evidence (`quantitative_results.sources[]` names table and row id instead),
 * no `owner_type` in the global morph map (a local kind registry, exactly as
 * `Document` and `tp_waivers.waivable_type` do), only four kinds (all of which
 * reach an occurrence — `call_tree_test` and `incident` are not in this
 * registry), and no supersession chain (a re-shot photograph is a new row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bcms_evidence', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('bcms_exercise_occurrences')->cascadeOnDelete();

            // Local kind registry, deliberately kept out of the enforced morph
            // map (ADR 0019 §1.4): occurrence | score | readiness_task | aar.
            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id');

            $table->string('kind', 20); // photo|file|screenshot
            $table->string('caption', 255)->nullable();
            $table->string('file_name', 190);
            $table->string('file_path', 255);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');

            // sha256 of the bytes AS STORED — what turns "we did not edit the
            // row" into "the bytes are the bytes" (ADR 0019 §2).
            $table->string('hash', 64);

            $table->timestamp('captured_at')->nullable();
            $table->string('iso_clause_ref', 60)->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'occurrence_id']);
            $table->index(['organization_id', 'owner_type', 'owner_id']);
            $table->index(['organization_id', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bcms_evidence');
    }
};

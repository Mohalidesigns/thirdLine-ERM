<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 1 — the tiering ruleset.
 *
 * THIS TABLE IS NOT IN TRD §8, AND ITS ABSENCE THERE IS A GAP RATHER THAN A
 * DECISION. `tp_inherent_assessments.ruleset_version` and
 * `tp_score_runs.ruleset_version` both exist in §8 and both record which
 * ruleset produced a score — but nothing in the data model holds a ruleset, so
 * as specified those two columns version something that is not stored. FR-TIER-09
 * then asks tenant administrators to edit factor weights, add factors and edit
 * knockout rules through an editor with a sandbox simulator, which needs
 * somewhere to put the draft.
 *
 * So: a ruleset is a VERSIONED, PUBLISHED DOCUMENT, not a settings row.
 *
 * The publish/draft split is the whole point. TRD §7.9 requires that scores are
 * never recomputed retrospectively — a rule change produces a new run and a
 * reportable diff. That is only possible if the old ruleset still exists to be
 * diffed against, so a published ruleset is immutable and superseding it writes
 * a new row rather than editing the old one. The engagement's score run cites
 * `ruleset_version`, and a reader six months later can fetch exactly the rules
 * that produced the number.
 *
 * The sandbox simulator (FR-TIER-09) runs a DRAFT against the live portfolio in
 * memory and shows the tier migration before anything is published. Without it,
 * changing the DATA weight from 25 to 30 is a change whose effect on 5,000
 * vendors nobody can see until it has already happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_rulesets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Human-facing version, e.g. "2026.1". Unique per tenant, and the
            // value written into every score run this ruleset produces.
            $table->string('version', 30);
            $table->string('name', 160)->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 20)->default('draft');

            /*
             * The factor model of TRD §7.2: an ordered map of factor code to
             * { label, weight, description, options: [{value, label, score}] }.
             *
             * JSON rather than two normalised tables because a ruleset is
             * read whole, written whole, versioned whole and diffed whole. A
             * factor row that could be edited independently of its ruleset
             * would break the immutability the version stamp promises.
             */
            $table->json('factors');

            // The knockout rules of TRD §7.3: code, name, citation, floor
            // tier, and a condition in the Phase 0 DSL.
            $table->json('knockouts');

            // Tier edges on the 0-100 weighted score. Per tenant, per
            // FR-TIER-03.
            $table->json('band_edges');

            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();

            // The ruleset this one replaced, so the diff report has both sides.
            $table->foreignId('supersedes_id')->nullable()->constrained('tp_rulesets')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'version'], 'tp_ruleset_version_unique');
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_rulesets');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-05 TASK 4 — configuration as a versioned artefact.
 *
 * The work package says this is where most "configurable" platforms fail, and
 * the failure is always the same shape: export works, import is a loop of
 * updateOrCreate, and the first time somebody imports a bundle into an
 * environment that has drifted it half-applies and leaves the tenant with a
 * configuration that is neither the old one nor the new one.
 *
 * Three things prevent that here.
 *
 *   DRY RUN IS THE DEFAULT. `config:import` produces a diff and writes
 *   nothing unless --apply is passed. You cannot accidentally apply.
 *
 *   APPLY IS ONE TRANSACTION with a snapshot taken first. The snapshot IS the
 *   rollback point — not a hope that the reverse operations are correct, but
 *   the actual prior state, stored as a bundle of the same shape.
 *
 *   THE APPLY LOG IS APPEND-ONLY. config_bundle_applications records who
 *   applied what, from where, with what result, and holds the pre-apply
 *   snapshot. Nothing rewrites a row's diff, outcome or timestamps; the single
 *   field ever written after insert is rolled_back_by_application_id, which
 *   points forward at the entry that undid it. The question this table answers
 *   — "who changed the definition of Critical, and when" — is asked after
 *   something has gone wrong, by somebody who does not trust the answer.
 *
 * WHY CHECKSUM. A bundle that has been hand-edited between export and import
 * is the most common cause of a half-applied configuration. The checksum is
 * over the canonical payload, so a change of key order does not trip it but a
 * change of value does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('config_bundles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 200);
            $table->unsignedInteger('version')->default(1);
            $table->text('description')->nullable();

            // The whole configuration, section by section. JSON rather than a
            // file so that a bundle is subject to the same tenancy scope,
            // backup and retention rules as the data it describes.
            $table->json('payload');

            // sha256 over the canonical (recursively key-sorted) payload.
            $table->string('checksum', 64);

            $table->timestamp('exported_at')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();

            // 'production', 'staging', a hostname — whatever the operator can
            // recognise six months later when asked where a bundle came from.
            $table->string('source_environment', 60)->nullable();

            // Set when this bundle was captured as the pre-apply state of
            // another bundle rather than exported deliberately. Snapshots are
            // hidden from the bundle list; they exist to be rolled back to.
            $table->boolean('is_snapshot')->default(false);

            $table->timestamps();

            $table->unique(['organization_id', 'code', 'version']);
            $table->index(['organization_id', 'is_snapshot']);
        });

        Schema::create('config_bundle_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            // Nullable: a dry run against a payload pasted from another
            // environment is worth logging even though no bundle row exists
            // for it here.
            $table->foreignId('config_bundle_id')->nullable()->constrained('config_bundles')->nullOnDelete();

            // The pre-apply snapshot. This is the rollback point.
            $table->foreignId('snapshot_bundle_id')->nullable()->constrained('config_bundles')->nullOnDelete();

            $table->enum('mode', ['dry_run', 'apply', 'rollback']);
            $table->enum('outcome', ['ok', 'failed', 'no_changes'])->default('ok');

            // The structured diff exactly as it was shown to the operator
            // before they confirmed. Storing the diff rather than recomputing
            // it later is the point: recomputation against today's data
            // answers a different question.
            $table->json('diff');

            $table->unsignedInteger('added_count')->default(0);
            $table->unsignedInteger('changed_count')->default(0);
            $table->unsignedInteger('removed_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);

            $table->text('error')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at');

            // Set when a later rollback undid this application, so the log
            // reads as a history rather than a list of unrelated events.
            $table->foreignId('rolled_back_by_application_id')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'applied_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_bundle_applications');
        Schema::dropIfExists('config_bundles');
    }
};

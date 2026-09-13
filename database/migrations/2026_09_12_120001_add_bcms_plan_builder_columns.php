<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BCMS Phase 3 — the plan builder's binding and review columns (ADR 0011).
 *
 * Five columns, no new tables. The acknowledgement table Phase 3 was expected to
 * need is `bcms_plan_attestations` with `attestation_type = 'read'`; ADR 0011
 * argues why, and why a second table would have been a second answer to "who has
 * signed what".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bcms_plans', function (Blueprint $table) {
            // Nullable: a plan with no declared cycle gets no review date,
            // rather than a cycle the system invented for it.
            $table->unsignedSmallInteger('review_frequency_months')->nullable()->after('next_review_date');

            // An `AudienceRule` in the grammar frozen by ADR 0003. This is the
            // DENOMINATOR of the acknowledgement percentage: the attestation
            // rows say who has read the plan, and this says who was meant to.
            $table->json('distribution_rule')->nullable()->after('content');
        });

        Schema::table('bcms_plan_sections', function (Blueprint $table) {
            // Null means never assembled, and the screen says exactly that
            // rather than stamping a section with today's date.
            $table->timestamp('last_verified_at')->nullable()->after('is_overridden');

            // SHA-256 of the canonical payload the binding resolved to at the
            // last assembly. Drift detection is a re-resolve and a string
            // compare — which sees a deleted dependency, and sees a change that
            // reverts, neither of which an `updated_at` watermark can.
            $table->string('source_fingerprint', 64)->nullable()->after('last_verified_at');

            $table->boolean('needs_review')->default(false)->after('source_fingerprint');

            $table->index(['organization_id', 'needs_review'], 'bcms_plan_sections_review_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bcms_plan_sections', function (Blueprint $table) {
            $table->dropIndex('bcms_plan_sections_review_idx');
            $table->dropColumn(['last_verified_at', 'source_fingerprint', 'needs_review']);
        });

        Schema::table('bcms_plans', function (Blueprint $table) {
            $table->dropColumn(['review_frequency_months', 'distribution_rule']);
        });
    }
};

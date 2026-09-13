<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BCMS Phase 2 — the BIA engine's columns, under ADR 0009.
 *
 * The second structural migration after the freeze and a much smaller one than
 * ADR 0008's: eight columns, no tables, nothing renamed. Every table this phase
 * needs came out of Phase 0 complete. What was missing is state the acceptance
 * criteria require to be PROVABLE rather than merely done — a chase that
 * happened, an escalation that reached a named manager, a model's reasoning
 * beside its numbers, and the difference between what the grid proposed and
 * what the assessor decided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bcms_bia_assessments', function (Blueprint $table) {
            // THE PROPOSAL, KEPT BESIDE THE ANSWER. `mtpd_hours` is the
            // assessor's and is what every downstream RTO is measured against;
            // this is what the impact grid derived. One column cannot hold
            // both, and overwriting the proposal with the answer would erase
            // the disagreement — which is the interesting part of a BIA review.
            $table->decimal('derived_mtpd_hours', 8, 2)->nullable()->after('mtpd_hours');

            // What the model said and WHY. A number a model produced and cannot
            // justify is worse than no number: a draft a human cannot
            // interrogate is one they will either accept blindly or bin.
            $table->json('ai_reasoning')->nullable()->after('ai_drafted_at');

            // Non-response chasing. Without a record of what was already sent,
            // the nightly sweep either chases every night or cannot tell whether
            // it has chased at all.
            $table->timestamp('chased_at')->nullable()->after('submitted_at');
            $table->unsignedSmallInteger('chase_count')->default(0)->after('chased_at');
            $table->timestamp('escalated_at')->nullable()->after('chase_count');

            // WHICH manager. "We escalated" is not evidence; "we escalated to
            // the Head of Operations on the 14th" is.
            $table->foreignId('escalated_to_user_id')->nullable()->after('escalated_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['organization_id', 'campaign_id', 'status'], 'bcms_bia_org_campaign_status_idx');
        });

        Schema::table('bcms_settings', function (Blueprint $table) {
            // The severity at which impact stops being tolerable — the point
            // MTPD derivation keys on. A TENANT judgement: one bank's 4-out-of-5
            // is another's 3, and hard-coding it would make the derived MTPD our
            // opinion rather than theirs.
            $table->unsignedTinyInteger('impact_intolerable_score')->default(4)->after('contact_verification_days');

            // NULLABLE, so a tenant that has not set a ceiling gets no ceiling
            // rather than one we invented.
            $table->decimal('critical_service_rto_ceiling_hours', 8, 2)->nullable()->after('impact_intolerable_score');
        });
    }

    public function down(): void
    {
        Schema::table('bcms_settings', function (Blueprint $table) {
            $table->dropColumn(['impact_intolerable_score', 'critical_service_rto_ceiling_hours']);
        });

        Schema::table('bcms_bia_assessments', function (Blueprint $table) {
            $table->dropIndex('bcms_bia_org_campaign_status_idx');
            $table->dropForeign(['escalated_to_user_id']);
            $table->dropColumn([
                'derived_mtpd_hours', 'ai_reasoning', 'chased_at', 'chase_count',
                'escalated_at', 'escalated_to_user_id',
            ]);
        });
    }
};

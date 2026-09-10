<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section delegation and finding threads — TPRM Phase 8, FR-PRT-03 and
 * FR-PRT-08/09.
 *
 * TWO SMALL ADDITIONS, both for things Phase 0 did not anticipate.
 *
 * DELEGATION IS PER (ASSESSMENT, SECTION), NOT PER SECTION. A questionnaire
 * section belongs to a TEMPLATE, which is shared by every assessment issued
 * from it and — for the packs we ship — by every tenant. Recording "Ada owns
 * the security section" on the section itself would put one vendor's staffing
 * on a row another vendor reads. It has to hang off the assessment.
 *
 * THE FINDING THREAD REUSES `tp_assessment_messages` rather than getting its
 * own table. The two are the same object: a conversation between a reviewer
 * and a vendor, attached to something, on the record instead of in an inbox.
 * A second table would mean a second unread count, a second notification path
 * and two screens to keep in step, which is how one of them stops working.
 * `assessment_id` becomes nullable because a finding thread has no assessment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_assessment_delegations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained('tp_assessments')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('tp_questionnaire_sections')->cascadeOnDelete();

            // The colleague it went to, and the colleague who sent it. Both are
            // portal users: delegation is between the vendor's own people, and
            // our staff are not part of it.
            $table->foreignId('delegated_to')->constrained('tp_portal_users')->cascadeOnDelete();
            $table->foreignId('delegated_by')->constrained('tp_portal_users')->cascadeOnDelete();

            $table->text('note')->nullable();
            $table->timestamp('delegated_at');
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // One owner per section per assessment. Re-delegating replaces.
            $table->unique(['assessment_id', 'section_id'], 'tp_delegation_unique');
            $table->index(['organization_id', 'delegated_to']);
        });

        Schema::table('tp_assessment_messages', function (Blueprint $table): void {
            $table->foreignId('finding_id')
                ->nullable()
                ->after('response_id')
                ->constrained('tp_findings')
                ->cascadeOnDelete();

            $table->index(['organization_id', 'finding_id']);
        });

        /*
         * A finding thread has no assessment, so the column has to allow one.
         *
         * `change()` NATIVELY, not raw SQL per driver. The first version of
         * this migration issued a MySQL `MODIFY` and did nothing at all on
         * SQLite, with a comment claiming a rebuild would relax the
         * constraint — which it does not: the original migration declares the
         * column NOT NULL and a fresh build declares it again. Every finding
         * thread would have failed to insert in the test suite, which is the
         * only place this project runs SQLite. Laravel 11 changes columns
         * without doctrine/dbal, so there is no reason to hand-roll it.
         */
        Schema::table('tp_assessment_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('assessment_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tp_assessment_messages', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'finding_id']);
            $table->dropConstrainedForeignId('finding_id');
        });

        Schema::dropIfExists('tp_assessment_delegations');
    }
};

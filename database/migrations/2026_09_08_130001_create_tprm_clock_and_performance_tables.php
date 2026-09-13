<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regulatory clocks, notification drafts and service reviews — TPRM Phase 9.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Per-tenant TPRM settings.
         *
         * IT EXISTS BECAUSE OF ONE FIGURE. The CBN cyber-incident definition
         * (Framework Appendix I) turns on financial loss exceeding 0.01% of
         * SHAREHOLDERS' FUNDS, and that figure is a property of the bank, not
         * of this product — it cannot live in `config/tprm.php` beside the
         * statutory hours. Without it the materiality test is uncomputable,
         * and the module must say so rather than guess: an incident wrongly
         * assessed as non-reportable is a missed 24-hour deadline.
         */
        Schema::create('tp_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Minor units, like every other money column in the module.
            $table->unsignedBigInteger('shareholders_funds_minor')->nullable();
            $table->string('shareholders_funds_currency', 3)->nullable();
            $table->date('shareholders_funds_as_at')->nullable();

            // Who to name on a regulatory notification draft. Not an email
            // recipient — nothing is ever sent from here.
            $table->string('regulatory_contact_name', 200)->nullable();
            $table->string('regulatory_contact_title', 200)->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('organization_id');
        });

        /*
         * A pre-filled regulatory notification — TRD §12.7.
         *
         * NOTHING IS EVER SUBMITTED FROM HERE. The draft is assembled from the
         * incident so a compliance officer starts from a document rather than
         * a blank page under a 24-hour clock; `submitted_at` is set by a
         * person with `tprm.incident.notify` recording that THEY sent it,
         * through whatever channel the regulator actually uses. A product that
         * transmitted to a regulator on a rule's say-so would be one bug away
         * from filing a notification that was not true.
         */
        Schema::create('tp_notification_drafts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('tp_incidents')->cascadeOnDelete();

            // `ndpc` or `cbn`. Two regulators, two deadlines, two documents —
            // never one draft with a recipient field, because the content
            // differs and one of them would end up wrong.
            $table->string('regulator', 20);

            $table->string('title', 255);
            $table->longText('body');

            // What the draft was built from, so a reviewer can see which facts
            // were known at the time and which were still blank.
            $table->json('facts')->nullable();
            $table->json('gaps')->nullable();

            $table->string('status', 20)->default('draft');

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('submission_reference', 120)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['incident_id', 'regulator'], 'tp_draft_unique');
            $table->index(['organization_id', 'status']);
        });

        /*
         * Escalations, recorded rather than merely sent.
         *
         * ONE ROW PER (incident, regulator, threshold), UNIQUE. The sweep runs
         * on a schedule and an escalation that fired at 50% must not fire
         * again at every subsequent run — an inbox full of the same warning is
         * an inbox in which the 80% one is not noticed.
         */
        Schema::create('tp_incident_escalations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('tp_incidents')->cascadeOnDelete();

            $table->string('regulator', 20);
            $table->unsignedTinyInteger('threshold_pct');

            /*
             * `datetime`, NOT `timestamp`. MySQL gives only the FIRST
             * non-nullable TIMESTAMP column an implicit default, so a second
             * one is rejected outright under strict mode — which is how this
             * was found. These are absolute points in time rather than
             * row-modification tracking, so datetime is also the more honest
             * type: no timezone conversion on write, and no 2038 ceiling.
             */
            $table->dateTime('fired_at');
            $table->dateTime('deadline_at');
            $table->json('notified_user_ids')->nullable();

            $table->timestamps();

            $table->unique(['incident_id', 'regulator', 'threshold_pct'], 'tp_escalation_unique');
        });

        /*
         * Scheduled service reviews — FR-PRF.
         *
         * `next_due_at` IS ON THE REVIEW, not derived from a policy at read
         * time, because "a review that does not happen becomes an overdue
         * obligation" needs a date something can be overdue against. The
         * schedule generates the next row when one is completed.
         */
        Schema::create('tp_service_reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained('tp_engagements')->cascadeOnDelete();

            $table->string('cadence', 20)->nullable();
            $table->date('scheduled_for');
            $table->date('held_on')->nullable();

            $table->json('agenda')->nullable();
            $table->json('attendees')->nullable();
            $table->longText('minutes')->nullable();

            // The SLA pack as it stood at the meeting. Snapshotted, because a
            // minute referring to "the performance pack" is worthless once the
            // underlying measurements have moved on.
            $table->json('performance_snapshot')->nullable();

            $table->string('status', 20)->default('scheduled');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'engagement_id', 'scheduled_for'], 'tp_review_idx');
            $table->index(['organization_id', 'status']);
        });

        Schema::create('tp_service_review_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_review_id')->constrained('tp_service_reviews')->cascadeOnDelete();

            $table->text('description');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('completed_at')->nullable();

            // Where an action is serious enough to be tracked as a finding,
            // this points at it rather than duplicating the remediation
            // machinery.
            $table->foreignId('finding_id')->nullable()->constrained('tp_findings')->nullOnDelete();

            $table->timestamps();

            // Named explicitly: the generated name exceeds MySQL's 64-character
            // identifier limit.
            $table->index(['organization_id', 'service_review_id'], 'tp_review_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_service_review_actions');
        Schema::dropIfExists('tp_service_reviews');
        Schema::dropIfExists('tp_incident_escalations');
        Schema::dropIfExists('tp_notification_drafts');
        Schema::dropIfExists('tp_settings');
    }
};

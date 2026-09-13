<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BCMS Phase 0, part 3 of 8 — the resilience calendar and exercise engine
 * (Blueprint §5 and §9.3). This is the heart of the module and the two demo
 * moments that close deals (Gates G1 and G2) both run through these tables.
 *
 * THREE REFERENCE TABLES HERE ARE SYSTEM-OWNED — `bcms_exercise_types`,
 * `bcms_readiness_templates` (with its tasks) and `bcms_blackout_periods` ship
 * with the product on `organization_id = null` and are copied or read by every
 * tenant. That nullable column is the trap the TPRM build lost two sessions to:
 * a null `organization_id` is invisible to `BelongsToOrganization`'s global
 * scope, so a seeder that writes one and a screen that reads it disagree
 * forever, silently. Every model over these tables sets `SYSTEM_OWNED = true`
 * and is covered by a Phase 0 test (ADR 0006).
 *
 * ONE DELIBERATE DEVIATION FROM BLUEPRINT §9.3: `bcms_exercise_occurrences`
 * carries NO `aar_id`. The blueprint lists `aar_id` on the occurrence and
 * `occurrence_id` on the AAR, which is two pointers at one relationship that
 * can disagree, and nothing in the database stops them. `bcms_aars.occurrence_id`
 * is unique and is the single edge; the occurrence reads its AAR through a
 * `hasOne`.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------------------------------ */
        /*  Readiness templates — the checklist an exercise type instantiates. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_readiness_templates', function (Blueprint $table) {
            $table->id();
            // NULLABLE: system-owned rows ship with the product. See the header.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_system_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('bcms_readiness_template_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('bcms_readiness_templates')->cascadeOnDelete();
            $table->string('title', 250);
            $table->text('description')->nullable();

            // Negative: days BEFORE the exercise. -8 means "due two days after
            // the T-10 ladder starts". Signed on purpose — a follow-up task at
            // +3 is a real case (return the DR site to production).
            $table->smallInteger('due_offset_days')->default(-5);

            // A blocking task that is not closed stops the exercise going ahead
            // (Blueprint §5.5). This is the gating feature no competitor has,
            // and it is worthless if it is advisory.
            $table->boolean('is_blocking')->default(false);

            $table->boolean('requires_evidence')->default(false);
            $table->string('default_owner_role', 80)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['template_id', 'sort_order']);
        });

        /* ------------------------------------------------------------------ */
        /*  Exercise types — the ISO 22398 ladder, not a dropdown. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_exercise_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->text('description')->nullable();

            // The rung on the ladder. `App\Enums\Bcms\LadderLevel::rank()` is
            // what makes "a full-scale exercise for a process that has never
            // had a successful tabletop" a question the engine can ask.
            $table->string('ladder_level', 20);

            $table->unsignedInteger('default_duration_minutes')->default(120);
            $table->unsignedSmallInteger('default_frequency_per_year')->default(1);
            $table->unsignedSmallInteger('default_lead_time_days')->default(10);
            $table->foreignId('readiness_template_id')->nullable()
                ->constrained('bcms_readiness_templates')->nullOnDelete();
            $table->json('objectives_template')->nullable();

            // The regulatory driver that sets the cadence, where there is one:
            // CBN Open Banking failover is 4×/year and DR test 2×/year, and a
            // customer who lowers it should be told which rule they are now
            // outside rather than allowed to do it quietly.
            $table->string('cadence_clause_ref', 60)->nullable();

            $table->boolean('is_system_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'ladder_level']);
        });

        /* ------------------------------------------------------------------ */
        /*  Blackout periods — when an exercise must not be scheduled. */
        /* */
        /*  The Nigerian calendar is the content pack: month-end (last three */
        /*  working days), year-end close, CBN returns deadlines, salary days, */
        /*  Eid/Christmas/Easter clusters, election days. A generator that */
        /*  books a full-scale failover on the 27th has produced a calendar */
        /*  nobody will run. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_blackout_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('category', 40)->nullable(); // month_end|year_end|regulatory|payroll|public_holiday|election|custom

            // Two ways to express a period, and both are needed. Fixed dates
            // for "15 Dec – 5 Jan"; a recurrence rule for "the last three
            // working days of every month", which has no fixed date.
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->json('recurrence')->nullable();

            // Advisory blackouts warn; hard ones make the generator place the
            // occurrence in `needs_scheduling` rather than book it anyway.
            $table->boolean('is_hard_block')->default(true);

            $table->foreignId('business_unit_id')->nullable()->constrained('business_units')->nullOnDelete();
            $table->boolean('is_system_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
            $table->index(['starts_on', 'ends_on']);
        });

        /* ------------------------------------------------------------------ */
        /*  The annual programme, its definitions and their occurrences. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_exercise_programmes', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('bcms_programmes')->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('name', 200);
            $table->string('status', 20)->default('draft'); // draft|approved|active|closed
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // STORED counts, maintained by the engine. Clause 8.5 evidence is
            // "the exercise programme as approved and as delivered"; a count
            // recomputed on read cannot show what was approved.
            $table->unsignedInteger('total_planned')->default(0);
            $table->unsignedInteger('total_completed')->default(0);

            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'year', 'name']);
        });

        Schema::create('bcms_exercise_definitions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_programme_id')->constrained('bcms_exercise_programmes')->cascadeOnDelete();
            $table->foreignId('exercise_type_id')->constrained('bcms_exercise_types')->restrictOnDelete();
            $table->string('name', 200);
            $table->foreignId('business_unit_id')->nullable()->constrained('business_units')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('bcms_sites')->nullOnDelete();
            $table->json('process_ids')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('facilitator_id')->nullable()->constrained('users')->nullOnDelete();

            // "Fire Drill × 2 per year" — the whole engine follows from this
            // number plus the distribution mode.
            $table->unsignedSmallInteger('frequency_per_year')->default(1);
            $table->string('distribution_mode', 20)->default('even'); // even|quarterly|manual|window
            $table->json('preferred_window')->nullable();
            $table->unsignedInteger('duration_minutes')->default(120);

            // The T-10 countdown. `lead_time_days` defaults to 10 and is the
            // number of daily alerts; `min_notice_days` is the floor below
            // which a reschedule is not permitted without an override.
            $table->unsignedSmallInteger('lead_time_days')->default(10);
            $table->unsignedSmallInteger('min_notice_days')->default(3);
            $table->boolean('daily_reminder_enabled')->default(true);
            $table->time('reminder_send_time')->nullable();
            $table->string('reminder_mode', 20)->default('digest'); // discrete|digest — standing rule 7

            $table->boolean('readiness_gating')->default(false);

            // An unannounced exercise still generates the readiness ladder for
            // the facilitator; it suppresses PARTICIPANT reminders only. The
            // two are different audiences of the same schedule.
            $table->boolean('unannounced')->default(false);
            $table->boolean('mandatory')->default(false);

            $table->json('regulatory_drivers')->nullable();
            $table->json('objectives')->nullable();
            $table->unsignedBigInteger('scenario_id')->nullable();
            $table->json('blackout_overrides')->nullable();
            $table->json('default_channel_set')->nullable();
            $table->json('default_audience_rule')->nullable();

            $table->string('status', 20)->default('draft'); // draft|active|suspended|retired
            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'exercise_programme_id', 'status'], 'bcms_ex_defs_org_prog_status_idx');
            $table->index(['organization_id', 'business_unit_id']);
        });

        Schema::create('bcms_exercise_occurrences', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('definition_id')->constrained('bcms_exercise_definitions')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence_no');

            // Nullable, because `needs_scheduling` is a real state: when every
            // candidate date is blacked out or collides, the occurrence is
            // placed on the calendar UNSCHEDULED rather than booked anyway or
            // dropped. A plan with a visible gap beats a plan that hides one.
            $table->date('scheduled_date')->nullable();
            $table->dateTime('scheduled_start')->nullable();
            $table->dateTime('scheduled_end')->nullable();

            $table->string('status', 20)->default('planned');
            $table->dateTime('actual_start')->nullable();
            $table->dateTime('actual_end')->nullable();
            $table->foreignId('facilitator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('bcms_sites')->nullOnDelete();
            $table->string('location', 250)->nullable();

            $table->unsignedSmallInteger('reschedule_count')->default(0);
            $table->date('originally_scheduled_date')->nullable();
            $table->text('cancellation_reason')->nullable();

            // STORED, maintained by the readiness service. The gate reads one
            // boolean rather than counting tasks on every render, and the
            // stored value is what the AAR reports as the state on the day.
            $table->boolean('readiness_complete')->default(false);
            $table->unsignedSmallInteger('blocking_tasks_open')->default(0);

            $table->string('outcome', 30)->nullable();
            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['definition_id', 'sequence_no']);
            $table->index(['organization_id', 'scheduled_date']);
            $table->index(['organization_id', 'status', 'scheduled_date'], 'bcms_ex_occ_org_status_date_idx');
        });

        Schema::create('bcms_exercise_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('bcms_exercise_occurrences')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Constrained in part 7 — `bcms_contacts` is created in part 6.
            // The contact, not the user, is what a reminder resolves against
            // (ADR 0003): a user has no channel.
            $table->unsignedBigInteger('contact_id')->nullable();

            $table->string('role', 20)->default('participant'); // participant|facilitator|observer|evaluator|sponsor|deputy
            $table->foreignId('business_unit_id')->nullable()->constrained('business_units')->nullOnDelete();
            $table->string('invitation_status', 20)->default('pending'); // pending|sent|accepted|declined|tentative
            $table->string('attendance_status', 20)->default('unknown'); // unknown|present|absent|excused|late
            $table->timestamp('checked_in_at')->nullable();
            $table->string('check_in_method', 20)->nullable(); // qr|sms|manual|geo
            $table->timestamps();

            $table->unique(['occurrence_id', 'user_id']);
            $table->index(['organization_id', 'occurrence_id']);
        });

        Schema::create('bcms_readiness_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('bcms_exercise_occurrences')->cascadeOnDelete();
            $table->foreignId('template_task_id')->nullable()
                ->constrained('bcms_readiness_template_tasks')->nullOnDelete();
            $table->string('title', 250);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->smallInteger('due_offset_days')->default(-5);
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('open'); // open|in_progress|complete|waived|overdue
            $table->boolean('is_blocking')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('evidence_file_id')->nullable();

            // A blocking task can be overridden, but never silently: the reason
            // and the person are recorded, and the AAR reports the override.
            $table->text('override_reason')->nullable();
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('overridden_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'occurrence_id', 'status']);
            $table->index(['organization_id', 'due_date', 'status']);
        });

        /* ------------------------------------------------------------------ */
        /*  The materialised reminder ladder. ADR 0005 is the reasoning. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_reminder_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('bcms_exercise_occurrences')->cascadeOnDelete();
            $table->dateTime('send_at');

            // -10 through +14: the countdown, the day itself, and the
            // post-exercise chasers for the AAR and its corrective actions.
            $table->smallInteger('day_offset');

            $table->json('audience_rule');
            $table->json('channel_set');
            $table->string('template_key', 80);
            $table->string('mode', 10)->default('digest'); // discrete|digest
            $table->string('status', 12)->default('pending'); // pending|sent|skipped|voided
            $table->timestamp('dispatched_at')->nullable();
            $table->text('skip_reason')->nullable();
            $table->unsignedInteger('recipient_count')->nullable();

            // UNIQUE, and enforced by the database rather than by a
            // check-then-insert. Gate G1 requires that a dispatcher re-run
            // sends nothing twice; two workers racing on the same hourly tick
            // must lose one insert, not both succeed.
            $table->string('idempotency_key', 64)->unique();

            $table->timestamps();

            $table->index(['status', 'send_at']);
            $table->index(['organization_id', 'occurrence_id', 'day_offset'], 'bcms_reminders_org_occ_offset_idx');
        });

        /* ------------------------------------------------------------------ */
        /*  Execution — injects, timeline, scores, AAR. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_exercise_injects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('bcms_exercise_occurrences')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->integer('release_offset_minutes')->default(0);
            $table->string('title', 250);
            $table->longText('content')->nullable();
            $table->string('delivery_channel', 20)->nullable();
            $table->json('target_rule')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('ai_generated')->default(false);
            $table->timestamps();

            $table->unique(['occurrence_id', 'sequence']);
            $table->index(['organization_id', 'occurrence_id']);
        });

        Schema::create('bcms_exercise_timeline', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('bcms_exercise_occurrences')->cascadeOnDelete();
            $table->timestamp('logged_at');
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entry_type', 20); // system|manual|inject|decision|milestone
            $table->longText('content')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'occurrence_id', 'logged_at'], 'bcms_ex_timeline_org_occ_at_idx');
        });

        Schema::create('bcms_exercise_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('bcms_exercise_occurrences')->cascadeOnDelete();
            $table->foreignId('objective_id')->nullable()->constrained('bcms_objectives')->nullOnDelete();

            // The objective as it was worded at the time, not only its id. An
            // objective edited after the exercise would otherwise rewrite what
            // the evaluator scored.
            $table->string('objective_text', 500)->nullable();

            $table->foreignId('evaluator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('score')->nullable(); // 1..5
            $table->text('commentary')->nullable();
            $table->unsignedBigInteger('evidence_file_id')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'occurrence_id']);
        });

        Schema::create('bcms_aars', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // UNIQUE — one AAR per occurrence, and the single edge between the
            // two tables (see the file header).
            $table->foreignId('occurrence_id')->unique()->constrained('bcms_exercise_occurrences')->cascadeOnDelete();

            $table->text('summary')->nullable();
            $table->longText('what_worked')->nullable();
            $table->longText('what_failed')->nullable();
            $table->json('quantitative_results')->nullable();
            $table->json('participant_feedback')->nullable();

            // Standing rule 4: an AI draft lands editable and flagged, and no
            // human action is implied by its existence.
            $table->boolean('ai_generated')->default(false);
            $table->timestamp('ai_draft_generated_at')->nullable();

            $table->string('status', 20)->default('draft'); // draft|review|final
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('distributed_at')->nullable();
            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bcms_aars');
        Schema::dropIfExists('bcms_exercise_scores');
        Schema::dropIfExists('bcms_exercise_timeline');
        Schema::dropIfExists('bcms_exercise_injects');
        Schema::dropIfExists('bcms_reminder_schedules');
        Schema::dropIfExists('bcms_readiness_tasks');
        Schema::dropIfExists('bcms_exercise_participants');
        Schema::dropIfExists('bcms_exercise_occurrences');
        Schema::dropIfExists('bcms_exercise_definitions');
        Schema::dropIfExists('bcms_exercise_programmes');
        Schema::dropIfExists('bcms_blackout_periods');
        Schema::dropIfExists('bcms_exercise_types');
        Schema::dropIfExists('bcms_readiness_template_tasks');
        Schema::dropIfExists('bcms_readiness_templates');
    }
};

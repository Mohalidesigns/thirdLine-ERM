<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BCMS Phase 0, part 7 of 8 — the supporting tables, and the foreign keys that
 * could not be declared where their column lives.
 *
 * The deferred keys are all forward references between subject areas: a plan is
 * activated by an incident, a finding is raised by a call-tree test, a
 * participant resolves to a contact. Splitting the migrations by subject rather
 * than by dependency order keeps each file readable as one thing; the cost is
 * this file, and the cost is worth paying once.
 *
 * TWO TABLES HERE ARE NOT IN BLUEPRINT §9 AND ARE REQUIRED BY IT ANYWAY:
 *
 *   `bcms_scenarios` — §9.3 gives `bcms_exercise_definitions.scenario_id` with
 *   nothing to point at, and the scenario library is a shipped content pack
 *   (compliance-analyst mandate 3). A dangling id column would be a structural
 *   migration in Phase 4.
 *
 *   `bcms_saved_groups` — ADR 0003's audience grammar has a `saved_group` leaf.
 *   A leaf the resolver cannot resolve is a rule a customer can build and the
 *   system cannot honour.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------------------------------ */
        /*  Clause reference table — the readable half of `IsoClauseRef`. */
        /* */
        /*  System-owned (`organization_id = null`). The enum is the closed set */
        /*  that stops an invented ref reaching the database; this table is what */
        /*  a screen joins to for the title, and what a regulator evidence pack */
        /*  groups by. Neither is derivable from the other (ADR 0006). */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_clause_refs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('standard', 60);
            $table->string('clause', 30)->nullable();
            $table->string('title', 250);
            $table->text('requirement')->nullable();
            $table->string('citation', 500)->nullable();

            // A mandatory documented-information clause. The evidence check
            // asks whether the artefact claiming it is a first-class row rather
            // than an uploaded file.
            $table->boolean('is_mandatory_record')->default(false);

            // Which regulator pack this ref is exported into: `iso22301`,
            // `cbn_csat`, `cbn_open_banking`, `board`. A ref in no pack is a
            // ref nobody can produce evidence for.
            $table->json('export_packs')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['standard', 'sort_order']);
        });

        /* ------------------------------------------------------------------ */
        /*  Tenant settings. */
        /* */
        /*  A ROW PER TENANT, not a config file and not a key/value bag. The */
        /*  acceptance criterion is that settings are read back "by a service, */
        /*  not by direct config access", and a typed row is what makes the */
        /*  service's return type mean something. Quiet hours and the reminder */
        /*  send time are decisions with legal weight in an emergency; they do */
        /*  not belong in a JSON blob nothing validates. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('timezone', 60)->default('Africa/Lagos');
            $table->unsignedSmallInteger('default_lead_time_days')->default(10);
            $table->time('reminder_send_time')->default('07:30:00');
            $table->string('default_reminder_mode', 10)->default('digest');

            // Quiet hours NEVER apply to life-safety or critical traffic
            // (standing rule 6). The columns configure routine reminders only,
            // and `AlertSeverity::respectsQuietHours()` is where that is
            // enforced rather than in whoever writes the next dispatcher.
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();

            // The day of the countdown on which an unclosed blocking readiness
            // task escalates to the owner's manager. -2 by default: late
            // enough that people have had a chance, early enough to fix.
            $table->smallInteger('escalation_day_offset')->default(-2);

            $table->json('default_channel_set')->nullable();
            $table->json('life_safety_channel_set')->nullable();

            // AI features off by default. Standing rule 4 makes every AI output
            // a draft; this makes the whole capability an opt-in per tenant,
            // which is what a bank's model-risk function will ask for.
            $table->boolean('ai_enabled')->default(false);
            $table->json('ai_capabilities')->nullable();

            $table->boolean('exercise_simulation_default')->default(true);
            $table->boolean('require_dual_approval_for_live')->default(true);
            $table->string('alert_currency', 3)->default('NGN');
            $table->unsignedSmallInteger('contact_verification_days')->default(180);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        /* ------------------------------------------------------------------ */
        /*  Scenario library — a shipped content pack plus tenant additions. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name', 200);
            $table->string('category', 40)->nullable(); // cyber|power|flood|fire|civil_unrest|supplier|pandemic|system
            $table->text('summary')->nullable();
            $table->longText('narrative')->nullable();
            $table->json('suggested_injects')->nullable();
            $table->json('suggested_objectives')->nullable();
            $table->string('ladder_level_min', 20)->nullable();
            $table->json('regulatory_drivers')->nullable();
            $table->boolean('is_system_default')->default(false);
            $table->boolean('ai_generated')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
        });

        /* ------------------------------------------------------------------ */
        /*  Saved audience groups — the `saved_group` leaf of ADR 0003. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_saved_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();

            // A group is either an explicit member list or a stored rule that
            // resolves at dispatch. Both are legitimate: "the crisis team" is a
            // list, "everyone at the Kano branch" is a rule that should pick up
            // a new joiner without anybody editing it.
            $table->json('rule')->nullable();
            $table->boolean('is_dynamic')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id']);
        });

        Schema::create('bcms_saved_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('bcms_saved_groups')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('bcms_contacts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'contact_id']);
        });

        /* ------------------------------------------------------------------ */
        /*  Audit log. Mirrors `tp_audit_logs` (ADR 0007, deviation 6): */
        /*  before/after on `getChanges()` only, secrets excluded, and the */
        /*  write never fails because of it. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('auditable_type', 120);
            $table->unsignedBigInteger('auditable_id');
            $table->string('event', 20); // created|updated|deleted|restored
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label', 150)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['auditable_type', 'auditable_id'], 'bcms_audit_logs_auditable_idx');
            $table->index(['organization_id', 'created_at']);
        });

        /* ------------------------------------------------------------------ */
        /*  The deferred foreign keys. */
        /* ------------------------------------------------------------------ */

        Schema::table('bcms_plan_activations', function (Blueprint $table) {
            $table->foreign('incident_id')->references('id')->on('bcms_incidents')->nullOnDelete();
            $table->foreign('occurrence_id')->references('id')->on('bcms_exercise_occurrences')->nullOnDelete();
        });

        Schema::table('bcms_exercise_participants', function (Blueprint $table) {
            $table->foreign('contact_id')->references('id')->on('bcms_contacts')->nullOnDelete();
        });

        Schema::table('bcms_exercise_definitions', function (Blueprint $table) {
            $table->foreign('scenario_id')->references('id')->on('bcms_scenarios')->nullOnDelete();
        });

        Schema::table('bcms_findings', function (Blueprint $table) {
            $table->foreign('incident_id')->references('id')->on('bcms_incidents')->nullOnDelete();
            $table->foreign('call_tree_test_id')->references('id')->on('bcms_call_tree_tests')->nullOnDelete();
            $table->foreign('dr_test_id')->references('id')->on('bcms_dr_tests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bcms_findings', function (Blueprint $table) {
            $table->dropForeign(['incident_id']);
            $table->dropForeign(['call_tree_test_id']);
            $table->dropForeign(['dr_test_id']);
        });

        Schema::table('bcms_exercise_definitions', function (Blueprint $table) {
            $table->dropForeign(['scenario_id']);
        });

        Schema::table('bcms_exercise_participants', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
        });

        Schema::table('bcms_plan_activations', function (Blueprint $table) {
            $table->dropForeign(['incident_id']);
            $table->dropForeign(['occurrence_id']);
        });

        Schema::dropIfExists('bcms_audit_logs');
        Schema::dropIfExists('bcms_saved_group_members');
        Schema::dropIfExists('bcms_saved_groups');
        Schema::dropIfExists('bcms_scenarios');
        Schema::dropIfExists('bcms_settings');
        Schema::dropIfExists('bcms_clause_refs');
    }
};

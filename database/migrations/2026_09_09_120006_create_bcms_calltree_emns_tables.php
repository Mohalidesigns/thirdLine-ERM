<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BCMS Phase 0, part 6 of 8 — call tree management and the emergency mass
 * notification system (Blueprint §6, §7 and §9.4).
 *
 * `bcms_contacts` IS THE SUBSTRATE both differentiators stand on, which is why
 * Orchestration §1 moved AD/Entra from Week 16 to Week 2. The contract in
 * Orchestration §5 is one sentence and it is enforced here by the shape of the
 * schema: **never query `users` directly for a channel.** A user has a name and
 * a login. A contact has a mobile number, a WhatsApp handle, a language, a
 * consent state and a verification date, and every one of those is personal
 * data under the NDPA with a different lawful basis.
 *
 * TWO NDPA RULES BUILT INTO THESE COLUMNS (docs/compliance/ndpa-register.md):
 *
 *   1. A personal mobile number is never directory-sourced. It arrives through
 *      the self-service emergency profile, carries `consent_status` and
 *      `consent_captured_at`, and is withdrawable — which is why consent is a
 *      column on the contact rather than an assumption about the roster.
 *
 *   2. Nothing is ever written back to Active Directory (standing rule 3).
 *      `ad_synced_at` records a read. There is no `ad_write_back_at` and there
 *      will not be one; the rule is checked at review.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------------------------------ */
        /*  Contacts — person → channel resolution, for the whole module. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // NULLABLE, and that is the point. A security guard, a cleaner, a
            // contractor and a next-of-kin all need to be reachable in an
            // evacuation and none of them has a platform login. A contacts
            // table that required a user row would exclude exactly the people
            // a fire drill is about.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('source', 20)->default('manual');
            $table->string('full_name', 200);
            $table->string('employee_id', 60)->nullable();
            $table->foreignId('business_unit_id')->nullable()->constrained('business_units')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('bcms_sites')->nullOnDelete();
            $table->string('title', 150)->nullable();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();

            // The channel addresses. E.164 throughout — a Nigerian mobile
            // stored as `08031234567` and as `+2348031234567` are two rows for
            // one person and the deduplication that follows is nobody's idea of
            // a good time. Normalisation happens on write, in one place.
            $table->string('email', 190)->nullable();
            $table->string('mobile_primary', 25)->nullable();
            $table->string('mobile_secondary', 25)->nullable();
            $table->string('whatsapp', 25)->nullable();
            $table->string('teams_id', 190)->nullable();
            $table->string('slack_id', 60)->nullable();
            $table->string('push_token', 255)->nullable();

            $table->json('next_of_kin')->nullable();

            // Blueprint §7.2: local-language alerts are a differentiator no
            // competitor ships. Hausa, Yoruba, Igbo and Nigerian Pidgin are
            // template locales, and the contact's preference decides which
            // rendering they receive.
            $table->string('preferred_language', 12)->default('en');

            $table->json('channel_preferences')->nullable();
            $table->json('geo_last_known')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // NDPA. `consent_status` covers the personal-phone channels only;
            // a corporate email needs no consent and conflating the two would
            // let a withdrawal silently remove somebody from a life-safety
            // roll-call, which is the opposite of protecting them.
            $table->string('consent_status', 20)->default('not_requested'); // not_requested|pending|granted|withdrawn
            $table->timestamp('consent_captured_at')->nullable();
            $table->timestamp('consent_withdrawn_at')->nullable();

            // Data hygiene (Blueprint §6.4): a number nobody has confirmed in
            // eighteen months is a broken branch waiting to happen, and the
            // hygiene report is built on these two columns.
            $table->string('verification_status', 20)->default('unverified'); // unverified|verified|bounced|invalid
            $table->timestamp('last_verified_at')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamp('ad_synced_at')->nullable();
            $table->string('ad_object_guid', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'employee_id']);
            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'business_unit_id']);
            $table->index(['organization_id', 'site_id']);
            $table->index(['organization_id', 'user_id']);
            $table->index(['organization_id', 'verification_status']);
        });

        /* ------------------------------------------------------------------ */
        /*  Call trees. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_call_trees', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained('business_units')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('bcms_sites')->nullOnDelete();
            $table->string('name', 200);
            $table->string('tree_type', 20)->default('department'); // department|crisis_team|site|it_dr|executive
            $table->string('version', 20)->default('1.0');

            // `stale` is a status the system writes, not one a user picks. A
            // tree whose review is overdue, or whose members' contact data has
            // decayed past a threshold, is stale — and a laminate on the wall
            // that nobody has checked in two years is precisely the failure
            // this module exists to replace.
            $table->string('status', 20)->default('draft'); // draft|approved|stale|archived

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->unsignedSmallInteger('review_frequency_days')->default(180);
            $table->string('source', 20)->default('manual'); // manual|ad_generated|hybrid
            $table->foreignId('activation_authority_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'business_unit_id']);
        });

        Schema::create('bcms_call_tree_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_tree_id')->constrained('bcms_call_trees')->cascadeOnDelete();
            $table->foreignId('parent_node_id')->nullable()->constrained('bcms_call_tree_nodes')->cascadeOnDelete();
            $table->unsignedTinyInteger('tier')->default(1);

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('bcms_contacts')->nullOnDelete();
            $table->string('role_label', 150)->nullable();

            // A must-reach node blocks the cascade from being scored complete
            // if it was never reached, however many people below it answered.
            $table->boolean('is_must_reach')->default(false);

            $table->string('primary_channel', 20)->nullable();
            $table->string('secondary_channel', 20)->nullable();
            $table->foreignId('deputy_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deputy_contact_id')->nullable()->constrained('bcms_contacts')->nullOnDelete();
            $table->unsignedSmallInteger('expected_response_minutes')->default(15);
            $table->unsignedInteger('sequence')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'call_tree_id', 'tier']);
            $table->index(['parent_node_id']);
        });

        Schema::create('bcms_call_tree_tests', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_tree_id')->constrained('bcms_call_trees')->cascadeOnDelete();
            $table->foreignId('occurrence_id')->nullable()
                ->constrained('bcms_exercise_occurrences')->nullOnDelete();

            $table->string('mode', 20)->default('automated'); // manual|automated|hybrid
            $table->boolean('announced')->default(true);
            $table->timestamp('initiated_at')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();

            // The scorecard numbers, STORED at completion. A test run in March
            // must reprint in December with March's roster and March's
            // failures, and every one of these recomputed from live nodes
            // would silently improve as the tree is repaired.
            $table->unsignedInteger('total_cascade_minutes')->nullable();
            $table->decimal('completion_rate', 5, 2)->nullable();
            $table->decimal('first_attempt_rate', 5, 2)->nullable();
            $table->decimal('deputy_activation_rate', 5, 2)->nullable();
            $table->unsignedInteger('data_quality_failures')->default(0);
            $table->unsignedInteger('nodes_total')->default(0);
            $table->unsignedInteger('nodes_reached')->default(0);
            $table->json('scorecard')->nullable();

            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'call_tree_id', 'initiated_at'], 'bcms_ct_tests_org_tree_at_idx');
        });

        Schema::create('bcms_call_tree_test_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('test_id')->constrained('bcms_call_tree_tests')->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained('bcms_call_tree_nodes')->nullOnDelete();

            // The node as it was, denormalised. A tree edited after a test
            // would otherwise rewrite who was on it — and "which branch broke"
            // is the screen that closes deals (Gate G2).
            $table->string('role_label_snapshot', 150)->nullable();
            $table->string('contact_name_snapshot', 200)->nullable();
            $table->unsignedTinyInteger('tier_snapshot')->nullable();

            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedInteger('response_minutes')->nullable();
            $table->string('channel_used', 20)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('outcome', 20)->nullable(); // reached|deputy|failed|timeout|wrong_contact

            // THE NUMBER ON THE BROKEN-BRANCH SCREEN. How many people below
            // this node were never contacted because this node never answered.
            // It is the difference between "one person missed the call" and
            // "forty-three people were never told".
            $table->unsignedInteger('downstream_blocked_count')->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'test_id']);
            $table->index(['test_id', 'outcome']);
        });

        /* ------------------------------------------------------------------ */
        /*  EMNS — templates, alerts, recipients, deliveries. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_alert_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name', 200);
            $table->string('category', 40)->nullable(); // evacuation|roll_call|it_outage|security|weather|exercise|…
            $table->string('severity', 20)->default('advisory');

            // One template row per locale. Not a JSON blob of locales, because
            // a WhatsApp Business template is APPROVED PER LOCALE by Meta and
            // the approval reference belongs on the row it approves.
            $table->string('locale', 12)->default('en');

            $table->string('subject', 250)->nullable();
            $table->text('body');

            // Per-channel renderings, because 160 characters of SMS, a voice
            // TTS script and a Teams adaptive card are three different texts
            // and a single body truncated three ways is how a life-safety
            // instruction loses its second sentence.
            $table->json('channel_renderings')->nullable();
            $table->string('whatsapp_template_name', 120)->nullable();

            $table->json('variables')->nullable();
            $table->boolean('requires_dual_approval')->default(false);
            $table->json('default_audience_rule')->nullable();
            $table->json('default_channel_set')->nullable();
            $table->boolean('is_life_safety')->default(false);
            $table->boolean('is_system_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code', 'locale']);
            $table->index(['organization_id', 'category']);
        });

        Schema::create('bcms_alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('bcms_alert_templates')->nullOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained('bcms_incidents')->nullOnDelete();
            $table->foreignId('occurrence_id')->nullable()
                ->constrained('bcms_exercise_occurrences')->nullOnDelete();

            $table->string('title', 250);
            $table->longText('message');
            $table->string('severity', 20)->default('advisory');

            // DEFAULTS TRUE. Standing rule 5: an exercise-linked alert is a
            // simulation unless somebody with dual approval says otherwise, and
            // it carries the "THIS IS AN EXERCISE" prefix. Defaulting the other
            // way means one mis-set flag sends a real evacuation order to four
            // thousand people.
            $table->boolean('is_simulation')->default(true);

            $table->json('audience_rule');
            $table->json('channels');
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('second_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('second_approved_at')->nullable();

            $table->string('status', 20)->default('draft'); // draft|pending_approval|dispatching|dispatched|cancelled|failed
            $table->timestamp('dispatched_at')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->bigInteger('estimated_cost_minor')->nullable();
            $table->bigInteger('actual_cost_minor')->nullable();
            $table->string('currency', 3)->default('NGN');

            $table->boolean('response_required')->default(false);
            $table->json('response_options')->nullable();
            $table->unsignedSmallInteger('ack_window_minutes')->nullable();
            $table->boolean('escalation_enabled')->default(false);

            $table->boolean('ai_generated')->default(false);
            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status', 'dispatched_at']);
            $table->index(['organization_id', 'incident_id']);
            $table->index(['organization_id', 'is_simulation']);
        });

        Schema::create('bcms_alert_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_id')->constrained('bcms_alerts')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('bcms_contacts')->cascadeOnDelete();

            // The audience SNAPSHOT (ADR 0003, rule 2). An examiner asking who
            // was told must get the list that was told, not the list the rule
            // would resolve to today.
            $table->json('resolved_channels');
            $table->string('contact_name_snapshot', 200)->nullable();

            $table->string('status', 20)->default('queued');
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('response_value', 60)->nullable();
            $table->text('response_text')->nullable();
            $table->foreignId('escalated_to_contact_id')->nullable()
                ->constrained('bcms_contacts')->nullOnDelete();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamps();

            $table->unique(['alert_id', 'contact_id']);
            $table->index(['organization_id', 'alert_id', 'status']);
        });

        Schema::create('bcms_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Two nullable FKs rather than a morph, and exactly one is set.
            // The database can check a foreign key; it cannot check that a
            // `deliverable_type` string names a real table (ADR 0005).
            $table->foreignId('alert_id')->nullable()->constrained('bcms_alerts')->cascadeOnDelete();
            $table->foreignId('reminder_schedule_id')->nullable()
                ->constrained('bcms_reminder_schedules')->cascadeOnDelete();

            $table->foreignId('recipient_contact_id')->nullable()->constrained('bcms_contacts')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('address', 190)->nullable();
            $table->string('provider', 60)->nullable();
            $table->string('provider_message_id', 190)->nullable();

            // Written as `queued` BEFORE the provider is called. Standing rule
            // 8, and the reason the watchdog can find a lost send.
            $table->string('status', 20)->default('queued');

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->text('failed_reason')->nullable();

            // NULLABLE, never defaulted to zero. A channel that cannot price a
            // send returns null; zero is a claim that it was free.
            $table->bigInteger('cost_minor')->nullable();
            $table->string('currency', 3)->nullable();

            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'created_at'], 'bcms_deliveries_org_status_at_idx');
            $table->index(['alert_id', 'channel']);
            $table->index(['reminder_schedule_id']);
            $table->index(['provider', 'provider_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bcms_notification_deliveries');
        Schema::dropIfExists('bcms_alert_recipients');
        Schema::dropIfExists('bcms_alerts');
        Schema::dropIfExists('bcms_alert_templates');
        Schema::dropIfExists('bcms_call_tree_test_nodes');
        Schema::dropIfExists('bcms_call_tree_tests');
        Schema::dropIfExists('bcms_call_tree_nodes');
        Schema::dropIfExists('bcms_call_trees');
        Schema::dropIfExists('bcms_contacts');
    }
};

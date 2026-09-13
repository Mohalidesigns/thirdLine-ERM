<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-07 TASK 3 — outbound webhooks.
 *
 * A webhook is the platform making an outbound HTTP request to a URL somebody
 * typed into a form. Two consequences shape this schema.
 *
 * THE SECRET IS PER SUBSCRIPTION. Every payload is signed with it, so the
 * receiver can tell a genuine delivery from anyone who learned the URL. A
 * shared platform-wide secret would mean one customer's integrator could forge
 * deliveries to another's endpoint.
 *
 * EVERY ATTEMPT IS RECORDED, including the failures. "Did you send it?" is the
 * first question in every integration incident, and an answer of "the job ran"
 * is not one. The delivery log holds the request, the response and the timing,
 * which is also what makes a manual replay possible.
 *
 * SUBSCRIPTIONS DISABLE THEMSELVES. An endpoint that has failed twenty times
 * running is gone, not busy, and retrying it forever turns a dead integration
 * into a permanent load on the queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->text('url');

            // Encrypted at rest: it is the shared secret that authenticates
            // every payload this platform sends to that endpoint.
            $table->text('secret');

            $table->json('events');
            $table->boolean('is_active')->default(true);

            // Optional narrowing, same shape as a workflow's scope_filter:
            // {"basel_l1_category": "EXTERNAL_FRAUD"}.
            $table->json('filters')->nullable();

            $table->dateTime('last_delivered_at')->nullable();
            $table->dateTime('last_failed_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->dateTime('disabled_at')->nullable();
            $table->string('disabled_reason', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active'], 'webhook_subs_org_active_index');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('webhook_subscriptions')->cascadeOnDelete();

            $table->string('event', 100);
            $table->json('payload');

            $table->unsignedTinyInteger('attempt')->default(1);
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('next_retry_at')->nullable();

            // A replay points at what it is replaying, so the log reads as a
            // history rather than as a pile of near-identical rows.
            $table->foreignId('replay_of_id')->nullable()->constrained('webhook_deliveries')->nullOnDelete();
            $table->foreignId('replayed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['organization_id', 'created_at'], 'webhook_deliveries_org_index');
            $table->index(['subscription_id', 'status'], 'webhook_deliveries_sub_status_index');
            $table->index('next_retry_at', 'webhook_deliveries_retry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
    }
};

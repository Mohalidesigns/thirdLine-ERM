<?php

namespace Tests\Feature\Integrations;

use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\Http\OutboundUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-07 TASK 3 acceptance: a webhook fires on risk.created, is signed, and
 * retries on failure.
 *
 * The SSRF tests are the ones that would matter most in an incident. A webhook
 * URL is typed into a form and fetched BY THE SERVER, so without a guard it is
 * a way for anyone who can create a subscription to read whatever the
 * application server can reach — and the response is stored in the delivery log
 * where they can read it.
 */
class WebhookTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();
        Cache::flush();

        // The guard resolves DNS, which a test must not depend on.
        config(['webhooks.allowed_hosts' => ['receiver.example.test', 'slow.example.test']]);
    }

    /* ================================================================== */
    /*  Publishing */
    /* ================================================================== */

    #[Test]
    public function creating_a_risk_fires_a_webhook(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->subscribe(['risk.created']);

        $risk = $this->makeRisk(['title' => 'Something happened']);

        $delivery = WebhookDelivery::withoutGlobalScopes()->latest('id')->first();

        $this->assertNotNull($delivery, 'A subscription to risk.created must produce a delivery.');
        $this->assertSame('risk.created', $delivery->event);
        $this->assertSame((string) $risk->id, (string) $delivery->payload['subject']['id']);
        $this->assertSame(WebhookDelivery::STATUS_DELIVERED, $delivery->fresh()->status);
    }

    #[Test]
    public function a_subscription_only_receives_the_events_it_asked_for(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->subscribe(['loss_event.*']);

        $this->makeRisk(['title' => 'Not interesting to this subscriber']);

        $this->assertSame(0, WebhookDelivery::withoutGlobalScopes()->count());

        $this->makeLossEvent();

        $this->assertGreaterThan(0, WebhookDelivery::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_wildcard_matches_by_prefix(): void
    {
        $subscription = $this->subscribe(['risk.*']);

        $this->assertTrue($subscription->wants('risk.created'));
        $this->assertTrue($subscription->wants('risk.state_changed'));
        $this->assertFalse($subscription->wants('loss_event.created'));
    }

    #[Test]
    public function a_filter_narrows_what_is_delivered(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->subscribe(['loss_event.created'], ['subject.status' => 'ESCALATED']);

        $this->makeLossEvent(['current_status' => 'NEW']);

        $this->assertSame(0, WebhookDelivery::withoutGlobalScopes()->count(),
            'Without filters, "tell me about loss events" means every loss event.');

        $this->makeLossEvent(['current_status' => 'ESCALATED']);

        $this->assertSame(1, WebhookDelivery::withoutGlobalScopes()->count());
    }

    /* ================================================================== */
    /*  Signing */
    /* ================================================================== */

    #[Test]
    public function every_payload_is_signed_with_the_subscriptions_own_secret(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $subscription = $this->subscribe(['risk.created']);
        $this->makeRisk();

        Http::assertSent(function ($request) use ($subscription) {
            $timestamp = $request->header(config('webhooks.timestamp_header'))[0] ?? null;
            $signature = $request->header(config('webhooks.signature_header'))[0] ?? null;

            $this->assertNotNull($timestamp);
            $this->assertNotNull($signature);

            // The timestamp is signed WITH the body: a signature over the body
            // alone stays valid forever, so a captured payload could be
            // replayed at the receiver indefinitely.
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), $subscription->secret);

            $this->assertSame($expected, $signature);

            return true;
        });
    }

    /* ================================================================== */
    /*  Failure and retry */
    /* ================================================================== */

    #[Test]
    public function a_server_error_schedules_another_attempt(): void
    {
        Http::fake(['*' => Http::response('upstream is unwell', 503)]);

        $this->subscribe(['risk.created']);
        $this->makeRisk();

        $first = WebhookDelivery::withoutGlobalScopes()->where('attempt', 1)->first();

        $this->assertSame(WebhookDelivery::STATUS_RETRYING, $first->status);
        $this->assertSame(503, $first->status_code);
        $this->assertNotNull($first->next_retry_at);

        // Each attempt is its OWN row: "we tried four times, here is what came
        // back each time" is the answer an integration incident needs.
        $second = WebhookDelivery::withoutGlobalScopes()->where('attempt', 2)->first();

        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->replay_of_id);
    }

    #[Test]
    public function a_client_error_is_not_retried(): void
    {
        Http::fake(['*' => Http::response('no such endpoint', 404)]);

        $this->subscribe(['risk.created']);
        $this->makeRisk();

        $delivery = WebhookDelivery::withoutGlobalScopes()->where('attempt', 1)->first();

        $this->assertSame(WebhookDelivery::STATUS_FAILED, $delivery->status);
        $this->assertNull($delivery->next_retry_at,
            'A 4xx means understood and refused; retrying tells the same wrong endpoint the same wrong thing.');
        $this->assertSame(0, WebhookDelivery::withoutGlobalScopes()->where('attempt', 2)->count());
    }

    #[Test]
    public function a_subscription_disables_itself_after_repeated_failures(): void
    {
        $subscription = $this->subscribe(['risk.created']);

        $subscription->forceFill(['consecutive_failures' => WebhookSubscription::FAILURE_LIMIT - 1])->save();
        $subscription->recordFailure('still dead');

        $this->assertNotNull($subscription->fresh()->disabled_at,
            'An endpoint that has failed twenty times running is gone, not busy.');
        $this->assertTrue($subscription->fresh()->isDisabled());
    }

    /* ================================================================== */
    /*  Replay */
    /* ================================================================== */

    #[Test]
    public function a_replay_sends_the_original_payload_again(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->subscribe(['risk.created']);
        $risk = $this->makeRisk(['title' => 'As it was']);

        $original = WebhookDelivery::withoutGlobalScopes()->latest('id')->first();

        // The record changes after the fact.
        $risk->update(['title' => 'As it is now']);

        $replay = app(WebhookDispatcher::class)->replay($original, $this->actor);

        $this->assertSame($original->id, $replay->replay_of_id);
        $this->assertSame(
            'As it was',
            $replay->payload['subject']['title'],
            'A replay is "send that event again". Re-reading the record would send today\'s state under a past timestamp.'
        );
    }

    /* ================================================================== */
    /*  SSRF */
    /* ================================================================== */

    #[Test]
    public function a_loopback_url_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        // http://localhost:6379 is a way to talk to Redis from a form field.
        OutboundUrlGuard::assertSafe('https://127.0.0.1/hook');
    }

    #[Test]
    public function the_cloud_metadata_address_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        // The one that returns cloud credentials.
        OutboundUrlGuard::assertSafe('https://169.254.169.254/latest/meta-data/');
    }

    #[Test]
    public function a_private_range_address_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        OutboundUrlGuard::assertSafe('https://10.0.0.5/internal');
    }

    #[Test]
    public function a_non_http_scheme_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        OutboundUrlGuard::assertSafe('file:///etc/passwd');
    }

    #[Test]
    public function credentials_in_the_url_are_refused(): void
    {
        $this->expectException(RuntimeException::class);

        // A URL ends up in logs and in the delivery history.
        OutboundUrlGuard::assertSafe('https://user:secret@receiver.example.test/hook');
    }

    #[Test]
    public function an_allowlisted_host_is_permitted(): void
    {
        // On-premise receivers on the internal network are common in a bank,
        // which is why the allowlist exists rather than an all-or-nothing switch.
        $this->assertTrue(OutboundUrlGuard::isSafe('https://receiver.example.test/hook'));
    }

    #[Test]
    public function the_delivery_job_refuses_a_url_that_became_unsafe(): void
    {
        Http::fake();

        $subscription = $this->subscribe(['risk.created']);

        // DNS changes. A host that resolved publicly last month can resolve to
        // 169.254.169.254 today, so the check nearest the request is the one
        // that counts.
        $subscription->forceFill(['url' => 'https://169.254.169.254/hook'])->save();

        $delivery = WebhookDelivery::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'subscription_id' => $subscription->id,
            'event' => 'risk.created',
            'payload' => ['event' => 'risk.created'],
            'attempt' => 1,
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        (new DeliverWebhookJob($delivery->id))->handle();

        $this->assertSame(WebhookDelivery::STATUS_FAILED, $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    /* ================================================================== */

    private function subscribe(array $events, array $filters = []): WebhookSubscription
    {
        Cache::forget('webhooks:any:'.$this->organization->id);

        return WebhookSubscription::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test receiver',
            'url' => 'https://receiver.example.test/hook',
            'events' => $events,
            'filters' => $filters ?: null,
            'is_active' => true,
        ]);
    }
}

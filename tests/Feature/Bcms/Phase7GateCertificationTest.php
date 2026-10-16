<?php

namespace Tests\Feature\Bcms;

use App\Contracts\Bcms\DeliveryReceipt;
use App\Contracts\Bcms\NotificationChannel;
use App\Contracts\Bcms\Recipient;
use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\ContactSource;
use App\Enums\Bcms\DeliveryStatus;
use App\Jobs\Bcms\DispatchAlertChunkJob;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\AuditLog;
use App\Models\Bcms\Contact;
use App\Models\Bcms\NotificationDelivery;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Emns\AlertDispatcher;
use App\Services\Bcms\Emns\AlertService;
use App\Services\Bcms\Emns\InboundResponseHandler;
use App\Services\Bcms\Emns\RollCallService;
use App\Services\Bcms\Notification\ChannelRegistry;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * GATE 1 (qa-engineer) CERTIFICATION TESTS for BCMS Phase 7 — the gaps the
 * implementer's own suite left, found by reading it against the handoff's
 * acceptance criteria and its "two regression tests the gate must not pass
 * without".
 *
 * What is here and why it is not elsewhere:
 *  - the throttle ACTUALLY FIRING (the existing test only compares two config
 *    numbers),
 *  - replay / idempotency of both callbacks,
 *  - status() under a configured secret (missing / wrong / right / stale),
 *  - tenant resolution under an AMBIENT tenant (the thing
 *    ClearAmbientTenantContext exists for) instead of by reading the stack,
 *  - the not_on_site regression on REAL minted tokens, end to end through the
 *    signed route (the existing one pins a hand-typed bare-hex fixture),
 *  - the dispatch route putting each severity on ITS queue (the existing test
 *    pushes the jobs itself, so it can never fail),
 *  - MFA on dispatch as behaviour, not as the word "mfa" in a middleware list,
 *  - write-ahead: a send that dies mid-flight leaves the queued row behind.
 */
class Phase7GateCertificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS',
            'name' => 'Operations', 'is_active' => true,
        ]);

        $this->operator = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Crisis Manager',
            'email' => 'crisis@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ================================================================== */
    /*  Scope 1 — the unauthenticated callbacks
    /* ================================================================== */

    #[Test]
    public function a_reply_with_a_secret_configured_but_the_wrong_signature_is_refused_and_changes_nothing(): void
    {
        [$recipient, $token] = $this->openRecipient();
        config()->set('bcms-gateways.webhook_secrets.termii', 'the-real-secret');

        $payload = ['body' => 'SAFE '.$token];
        $timestamp = (string) time();

        $this->postJson(route('bcms.alerts.reply', ['provider' => 'termii']), $payload, [
            'X-BCMS-Signature' => hash_hmac('sha256', $timestamp.'.'.json_encode($payload), 'a-different-secret'),
            'X-BCMS-Timestamp' => $timestamp,
        ])->assertStatus(403);

        // Right signature, but the timestamp header was altered after signing.
        $this->postJson(route('bcms.alerts.reply', ['provider' => 'termii']), $payload, [
            'X-BCMS-Signature' => hash_hmac('sha256', $timestamp.'.'.json_encode($payload), 'the-real-secret'),
            'X-BCMS-Timestamp' => (string) ((int) $timestamp + 1),
        ])->assertStatus(403);

        // Signature present, timestamp header absent.
        $this->postJson(route('bcms.alerts.reply', ['provider' => 'termii']), $payload, [
            'X-BCMS-Signature' => hash_hmac('sha256', '.'.json_encode($payload), 'the-real-secret'),
        ])->assertStatus(403);

        $this->assertNull($recipient->refresh()->acknowledged_at);
        $this->assertSame(0, $this->responseAudits($recipient));
    }

    #[Test]
    public function a_correctly_signed_reply_is_accepted_whether_the_prefix_is_present_or_not_and_via_form_encoding(): void
    {
        [$recipient, $token] = $this->openRecipient();

        $this->signedPost('bcms.alerts.reply', ['body' => 'SAFE '.$token], 'termii', prefix: 'sha256=')
            ->assertOk()->assertJson(['matched' => true, 'response' => RollCallService::SAFE]);

        [$second, $secondToken] = $this->openRecipient();

        // Aggregators mostly post application/x-www-form-urlencoded. The
        // signature covers the raw body, so sign the encoded string.
        $form = http_build_query(['body' => 'SAFE '.$secondToken]);
        $timestamp = (string) time();
        config()->set('bcms-gateways.webhook_secrets.termii', 'form-secret');

        $this->call('POST', route('bcms.alerts.reply', ['provider' => 'termii']), ['body' => 'SAFE '.$secondToken], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_X_BCMS_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$form, 'form-secret'),
            'HTTP_X_BCMS_TIMESTAMP' => $timestamp,
        ], $form)->assertOk()->assertJson(['matched' => true, 'response' => RollCallService::SAFE]);

        $this->assertNotNull($second->refresh()->acknowledged_at);
    }

    #[Test]
    public function a_malformed_or_empty_signed_body_is_a_validation_error_and_changes_nothing(): void
    {
        [$recipient] = $this->openRecipient();
        config()->set('bcms-gateways.webhook_secrets.termii', 'malformed-secret');

        $send = function (string $raw) {
            $timestamp = (string) time();

            return $this->call('POST', route('bcms.alerts.reply', ['provider' => 'termii']), [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_BCMS_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$raw, 'malformed-secret'),
                'HTTP_X_BCMS_TIMESTAMP' => $timestamp,
            ], $raw);
        };

        $send('{not json')->assertStatus(422);
        $send('')->assertStatus(422);
        $send('[]')->assertStatus(422);
        $send(json_encode(['body' => ['an' => 'array']]))->assertStatus(422);
        $send(json_encode(['body' => str_repeat('x', 1001)]))->assertStatus(422);

        $this->assertNull($recipient->refresh()->acknowledged_at);
    }

    #[Test]
    public function the_same_signed_reply_delivered_twice_changes_nothing_the_second_time(): void
    {
        [$recipient, $token] = $this->openRecipient();

        $payload = ['body' => 'SAFE '.$token];

        $this->signedPost('bcms.alerts.reply', $payload, 'termii')
            ->assertOk()->assertJson(['matched' => true, 'response' => RollCallService::SAFE]);

        $first = $recipient->refresh();
        $firstAt = $first->acknowledged_at;
        $this->assertSame(1, $this->responseAudits($recipient));

        $this->travel(2)->seconds();

        // A gateway retry: same body, fresh timestamp and signature.
        $this->signedPost('bcms.alerts.reply', $payload, 'termii')->assertOk();

        $again = $recipient->refresh();
        $this->assertEquals($firstAt, $again->acknowledged_at, 'A replay must not re-stamp the acknowledgement.');
        $this->assertSame(RollCallService::SAFE, $again->response_value);
        $this->assertSame(1, $this->responseAudits($recipient), 'A replay must not write a second audit row.');
        $this->assertSame(1, AlertRecipient::query()->where('alert_id', $recipient->alert_id)
            ->whereNotNull('acknowledged_at')->count());
    }

    #[Test]
    public function a_second_signed_reply_cannot_turn_a_safe_into_not_on_site(): void
    {
        [$recipient, $token] = $this->openRecipient();

        $this->signedPost('bcms.alerts.reply', ['body' => 'SAFE '.$token], 'termii')->assertOk();
        $this->signedPost('bcms.alerts.reply', ['body' => '3 '.$token], 'termii')->assertOk();

        $this->assertSame(RollCallService::SAFE, $recipient->refresh()->response_value);
    }

    #[Test]
    public function the_reply_throttle_actually_fires_and_is_bucketed_per_provider(): void
    {
        $limit = (int) config('bcms-gateways.alert_reply_rate_limit_per_minute');

        // Unsigned posts: each costs a 403 and no database work, which is the
        // whole point of the throttle sitting ahead of everything else.
        for ($i = 0; $i < $limit; $i++) {
            $status = $this->postJson(route('bcms.alerts.reply', ['provider' => 'infobip']), ['body' => 'x'])->status();

            if ($status !== 403) {
                $this->fail("Request {$i} of {$limit} was throttled early with {$status}.");
            }
        }

        $this->postJson(route('bcms.alerts.reply', ['provider' => 'infobip']), ['body' => 'x'])
            ->assertStatus(429);

        // Another provider's bucket is untouched.
        $this->postJson(route('bcms.alerts.reply', ['provider' => 'africastalking']), ['body' => 'x'])
            ->assertStatus(403);
    }

    #[Test]
    public function the_status_throttle_actually_fires(): void
    {
        $limit = (int) config('bcms-gateways.provider_status_rate_limit_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $status = $this->postJson(route('bcms.alerts.provider-status', ['provider' => 'nope']), [])->status();

            if ($status !== 403) {
                $this->fail("Request {$i} of {$limit} was throttled early with {$status}.");
            }
        }

        $this->postJson(route('bcms.alerts.provider-status', ['provider' => 'nope']), [])->assertStatus(429);
    }

    #[Test]
    public function a_gateway_callback_starts_no_session_and_sets_no_cookie(): void
    {
        [, $token] = $this->openRecipient();

        $response = $this->signedPost('bcms.alerts.reply', ['body' => 'SAFE '.$token], 'termii')->assertOk();

        $this->assertSame([], $response->headers->getCookies(), 'No session cookie, no XSRF cookie.');
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('sessions')->count(), 'No session row is written.');
    }

    #[Test]
    public function status_with_a_configured_secret_refuses_missing_wrong_and_stale_signatures_and_accepts_the_right_one(): void
    {
        $delivery = $this->sentDelivery('termii', 'msg-77');
        config()->set('bcms-gateways.webhook_secrets.termii', 'status-secret');

        $payload = ['message_id' => 'msg-77', 'status' => 'delivered'];
        $timestamp = (string) time();
        $body = json_encode($payload);

        // Missing.
        $this->postJson(route('bcms.alerts.provider-status', ['provider' => 'termii']), $payload)->assertStatus(403);

        // Wrong.
        $this->postJson(route('bcms.alerts.provider-status', ['provider' => 'termii']), $payload, [
            'X-BCMS-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, 'other'),
            'X-BCMS-Timestamp' => $timestamp,
        ])->assertStatus(403);

        // Stale (valid HMAC over an hour-old timestamp).
        $stale = (string) (time() - 3600);
        $this->postJson(route('bcms.alerts.provider-status', ['provider' => 'termii']), $payload, [
            'X-BCMS-Signature' => hash_hmac('sha256', $stale.'.'.$body, 'status-secret'),
            'X-BCMS-Timestamp' => $stale,
        ])->assertStatus(403);

        // Query string on an otherwise valid request.
        $this->postJson(route('bcms.alerts.provider-status', ['provider' => 'termii']).'?x=1', $payload, [
            'X-BCMS-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, 'status-secret'),
            'X-BCMS-Timestamp' => $timestamp,
        ])->assertStatus(403);

        $this->assertSame(DeliveryStatus::Sent, $delivery->refresh()->status, 'None of the refusals moved the row.');

        $this->signedPost('bcms.alerts.provider-status', $payload, 'termii', secret: 'status-secret')
            ->assertOk()->assertJson(['matched' => true, 'status' => 'delivered']);

        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status);
    }

    #[Test]
    public function status_is_the_one_route_that_accepts_an_unsigned_callback_when_no_secret_is_configured(): void
    {
        // The documented asymmetry (AlertWebhookController docblock). Pinned
        // so that it can only change on purpose.
        config()->set('bcms-gateways.webhook_secrets.termii', null);
        $delivery = $this->sentDelivery('termii', 'msg-unsigned');

        $this->postJson(route('bcms.alerts.provider-status', ['provider' => 'termii']), [
            'message_id' => 'msg-unsigned', 'status' => 'delivered',
        ])->assertOk()->assertJson(['matched' => true]);

        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status);

        // ...and an unmatched receipt is a 200 matched:false, never an error a
        // gateway will retry for ever.
        $this->postJson(route('bcms.alerts.provider-status', ['provider' => 'termii']), [
            'message_id' => 'no-such-message', 'status' => 'delivered',
        ])->assertOk()->assertJson(['matched' => false]);

        // A body with no message id at all.
        $this->postJson(route('bcms.alerts.provider-status', ['provider' => 'termii']), [])
            ->assertOk()->assertJson(['matched' => false]);
    }

    #[Test]
    public function the_same_status_callback_twice_does_not_restamp_the_delivery(): void
    {
        $delivery = $this->sentDelivery('termii', 'msg-replay');
        config()->set('bcms-gateways.webhook_secrets.termii', 'replay-status-secret');
        $payload = ['message_id' => 'msg-replay', 'status' => 'delivered'];

        $this->signedPost('bcms.alerts.provider-status', $payload, 'termii', secret: 'replay-status-secret')->assertOk();
        $stamped = $delivery->refresh()->delivered_at;
        $this->assertNotNull($stamped);

        $this->travel(5)->seconds();

        $this->signedPost('bcms.alerts.provider-status', $payload, 'termii', secret: 'replay-status-secret')->assertOk();

        $this->assertEquals($stamped, $delivery->refresh()->delivered_at, 'Evidence must not be re-stamped by a retry.');
        $this->assertSame(1, NotificationDelivery::query()->where('provider_message_id', 'msg-replay')->count());
    }

    #[Test]
    public function an_ambient_tenant_does_not_hide_the_recipient_a_valid_token_names(): void
    {
        // The state ClearAmbientTenantContext exists to defeat: some earlier
        // work in this process left ANOTHER tenant resolved. Without the clear,
        // OrganizationScope would filter the lookup to that tenant and a
        // genuine reply from this one would be dropped as "unmatched" (or,
        // the other way, scoped wrongly). Behaviour, not stack inspection.
        [$recipient, $token] = $this->openRecipient();

        $other = Organization::create([
            'name' => 'Lagos Trust MFB', 'short_name' => 'LTM',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        TenantContext::set($other->id);

        $this->signedPost('bcms.alerts.reply', ['body' => 'SAFE '.$token], 'termii')
            ->assertOk()->assertJson(['matched' => true]);

        $this->assertNotNull(AlertRecipient::query()->withoutGlobalScopes()->find($recipient->getKey())->acknowledged_at);
    }

    #[Test]
    public function a_reply_acknowledges_exactly_the_recipient_its_token_names_and_no_other_tenants_row(): void
    {
        [$mine, $myToken] = $this->openRecipient();

        $theirs = $this->openRecipientInAnotherTenant();

        $this->signedPost('bcms.alerts.reply', ['body' => 'SAFE '.$myToken], 'termii')->assertOk();

        $this->assertNotNull($mine->refresh()->acknowledged_at);
        $this->assertNull(
            AlertRecipient::query()->withoutGlobalScopes()->find($theirs->getKey())->acknowledged_at,
            "Another tenant's recipient must never move on this tenant's token.",
        );

        // And a token with the other tenant's id but THIS tenant's tag is
        // worthless: the tag is bound to the id.
        $forged = 'r-'.$theirs->getKey().'-'.substr(app(AlertDispatcher::class)->tagFor((int) $mine->getKey()), 0, 16);
        $this->signedPost('bcms.alerts.reply', ['body' => 'SAFE '.$forged], 'termii')
            ->assertOk()->assertJson(['matched' => false]);
        $this->assertNull(AlertRecipient::query()->withoutGlobalScopes()->find($theirs->getKey())->acknowledged_at);
    }

    /* ================================================================== */
    /*  Scope 6a — the not_on_site regression, on REAL tokens
    /* ================================================================== */

    #[Test]
    public function real_minted_tokens_never_change_what_a_reply_means_including_those_containing_a_three(): void
    {
        $this->contacts(60);
        $alert = $this->cleared($this->alert(AlertSeverity::Urgent, ['sms']));
        app(AlertService::class)->release($alert, $this->operator->id);

        $dispatcher = app(AlertDispatcher::class);
        $handler = app(InboundResponseHandler::class);

        $withThreeInTag = 0;
        $withThreeInId = 0;
        $checked = 0;

        foreach (AlertRecipient::query()->where('alert_id', $alert->getKey())->get() as $recipient) {
            $token = $dispatcher->tokenFor($recipient);
            $this->assertMatchesRegularExpression('/^r-\d+-[0-9a-f]{16}$/', $token, 'A real token, not a fixture.');

            $tag = substr($token, strrpos($token, '-') + 1);
            $withThreeInTag += str_contains($tag, '3') ? 1 : 0;
            $withThreeInId += str_contains((string) $recipient->getKey(), '3') ? 1 : 0;
            $checked++;

            foreach ([
                'SAFE '.$token => RollCallService::SAFE,
                $token.' safe' => RollCallService::SAFE,
                strtoupper('safe '.$token) => RollCallService::SAFE,
                '1 '.$token => RollCallService::SAFE,
                '2 '.$token => RollCallService::NEEDS_HELP,
                '3 '.$token => RollCallService::NOT_ON_SITE,
                'help '.$token => RollCallService::NEEDS_HELP,
                'ok '.$token => RollCallService::SAFE,
            ] as $body => $expected) {
                $this->assertSame(
                    $expected,
                    $handler->interpret($body),
                    "Reply [{$body}] was misread: a token must be noise, never a keyword.",
                );
            }
        }

        $this->assertSame(60, $checked);
        // 60 minted tags of 16 hex characters: P(no tag contains a 3) is
        // astronomically small. If this ever fails the sample, not the code,
        // is wrong — and the test must say so rather than pass vacuously.
        $this->assertGreaterThan(0, $withThreeInTag, 'Sample contained no tag with a 3; the test would prove nothing.');
        $this->assertGreaterThan(0, $withThreeInId, 'Sample contained no id with a 3.');
    }

    #[Test]
    public function a_safe_reply_carrying_a_real_token_with_a_three_is_recorded_safe_over_the_signed_route(): void
    {
        $this->contacts(40);
        $alert = $this->cleared($this->alert(AlertSeverity::Urgent, ['sms']));
        app(AlertService::class)->release($alert, $this->operator->id);

        $dispatcher = app(AlertDispatcher::class);

        $picked = AlertRecipient::query()->where('alert_id', $alert->getKey())->get()
            ->first(fn (AlertRecipient $r) => str_contains(substr($dispatcher->tokenFor($r), strrpos($dispatcher->tokenFor($r), '-') + 1), '3')
                && str_contains((string) $r->getKey(), '3'));

        $this->assertNotNull($picked, 'Need a recipient whose id and tag both contain a 3.');

        $this->signedPost('bcms.alerts.reply', ['body' => 'SAFE '.$dispatcher->tokenFor($picked)], 'termii')
            ->assertOk()->assertJson(['matched' => true, 'response' => RollCallService::SAFE]);

        $this->assertSame(RollCallService::SAFE, $picked->refresh()->response_value);
    }

    /* ================================================================== */
    /*  Scope 2 — dispatch route: queue, MFA, write-ahead
    /* ================================================================== */

    #[Test]
    public function the_dispatch_route_puts_each_severity_on_its_own_queue(): void
    {
        Queue::fake();
        $this->actingAs($this->dispatcher());

        $this->contacts(3);

        $lifeSafety = $this->cleared($this->alert(AlertSeverity::LifeSafety, ['sms']));
        $this->post(route('bcms.alerts.dispatch', $lifeSafety))->assertRedirect();

        Queue::assertPushedOn('bcms-lifesafety', DispatchAlertChunkJob::class);
        Queue::assertNotPushed(DispatchAlertChunkJob::class, fn ($job, $queue) => $queue === 'bcms-alerts');

        $advisory = $this->alert(AlertSeverity::Advisory, ['sms']);
        $this->post(route('bcms.alerts.dispatch', $advisory))->assertRedirect();

        Queue::assertPushedOn('bcms-alerts', DispatchAlertChunkJob::class);
    }

    #[Test]
    public function with_mfa_on_an_unverified_session_cannot_dispatch_and_nothing_is_released(): void
    {
        config()->set('features.mfa_totp', true);
        $dispatcher = $this->dispatcher();
        $dispatcher->forceFill(['mfa_enabled' => true])->save();
        $this->contacts(2);
        $alert = $this->alert(AlertSeverity::Advisory, ['sms']);

        Queue::fake();

        $this->actingAs($dispatcher)->post(route('bcms.alerts.dispatch', $alert))
            ->assertRedirect(route('mfa.verify'));

        $this->assertSame(0, AlertRecipient::query()->where('alert_id', $alert->getKey())->count());
        $this->assertNotSame('dispatching', $alert->refresh()->status);
        Queue::assertNothingPushed();

        // The same user once the second factor is asserted.
        $this->actingAs($dispatcher)->withSession(['mfa_verified' => true])
            ->post(route('bcms.alerts.dispatch', $alert))->assertRedirect(route('bcms.alerts.show', $alert));

        $this->assertSame(2, AlertRecipient::query()->where('alert_id', $alert->getKey())->count());
    }

    #[Test]
    public function a_send_that_dies_after_the_row_is_written_leaves_the_queued_row_behind(): void
    {
        $this->contacts(1);
        $alert = $this->cleared($this->alert(AlertSeverity::Urgent, ['sms']));
        app(AlertService::class)->release($alert, $this->operator->id);
        $ids = AlertRecipient::query()->where('alert_id', $alert->getKey())->pluck('id')->map(fn ($i) => (int) $i)->all();

        app(ChannelRegistry::class)->swap(ChannelKey::Sms, new class implements NotificationChannel
        {
            public function key(): ChannelKey
            {
                return ChannelKey::Sms;
            }

            public function provider(): string
            {
                return 'crashy';
            }

            public function supports(Recipient $to): bool
            {
                return true;
            }

            public function send(Recipient $to, RenderedMessage $message): DeliveryReceipt
            {
                throw new RuntimeException('worker killed mid-send');
            }

            public function estimateCostMinor(Recipient $to, RenderedMessage $message): ?int
            {
                return null;
            }
        });

        try {
            (new DispatchAlertChunkJob((int) $alert->getKey(), (int) $this->organization->id, $ids))
                ->handle(app(AlertDispatcher::class));
            $this->fail('The crash should have propagated so the queue can retry.');
        } catch (RuntimeException) {
            // expected
        }

        $row = NotificationDelivery::query()->where('alert_id', $alert->getKey())->first();
        $this->assertNotNull($row, 'Write-ahead: the attempt was persisted before the provider was called.');
        $this->assertSame(DeliveryStatus::Queued, $row->status);
        $this->assertSame(0, (int) $row->attempts);
    }

    /* ================================================================== */
    /*  Scope 7 — every Phase 7 alert route against another tenant's uuid
    /*  (the existing suite covered show / live / roll-call / respond only).
    /* ================================================================== */

    #[Test]
    public function no_alert_route_reaches_another_tenants_alert_and_none_changes_it(): void
    {
        $user = $this->dispatcher();
        foreach (['bcms.alert.approve', 'bcms.report.export', 'bcms.contact.export'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
        }

        $other = Organization::create([
            'name' => 'Lagos Trust MFB', 'short_name' => 'LTM',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        TenantContext::set($other->id);
        $foreign = Alert::query()->create([
            'organization_id' => $other->id, 'title' => 'Theirs', 'message' => 'x',
            'severity' => 'urgent', 'status' => 'draft', 'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => 1], 'is_simulation' => true,
        ]);
        TenantContext::set($this->organization->id);

        $this->actingAs($user);
        Queue::fake();

        $this->post(route('bcms.alerts.approve', $foreign))->assertNotFound();
        $this->post(route('bcms.alerts.dispatch', $foreign))->assertNotFound();
        $this->post(route('bcms.alerts.escalate-live', $foreign))->assertNotFound();
        $this->post(route('bcms.alerts.estimate', $foreign))->assertNotFound();
        $this->get(route('bcms.alerts.evidence', $foreign))->assertNotFound();

        Queue::assertNothingPushed();
        $fresh = Alert::query()->withoutGlobalScopes()->findOrFail($foreign->getKey());
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->approved_by);
        $this->assertSame(0, AlertRecipient::query()->withoutGlobalScopes()->where('alert_id', $foreign->getKey())->count());
    }

    #[Test]
    public function an_alert_is_routed_by_uuid_not_by_its_numeric_id(): void
    {
        $user = $this->dispatcher();
        $this->actingAs($user);
        $alert = $this->alert(AlertSeverity::Advisory, ['sms']);

        $this->get(route('bcms.alerts.live', $alert))->assertOk();
        $numeric = str_replace((string) $alert->uuid, (string) $alert->getKey(), route('bcms.alerts.live', $alert));
        $this->assertNotSame(route('bcms.alerts.live', $alert), $numeric);
        $this->get($numeric)->assertNotFound();
    }

    /* ================================================================== */
    /*  HELP ALWAYS WINS (owner ruling 2026-10-16) - D1
    /* ================================================================== */

    private function reply(string $body, string $token)
    {
        return $this->signedPost('bcms.alerts.reply', ['body' => $body.' '.$token], 'termii');
    }

    #[Test]
    public function a_received_reply_then_help_lands_needs_help(): void
    {
        [$recipient, $token] = $this->openRecipient();

        $this->reply('received', $token)->assertOk();
        $this->assertNotNull($recipient->refresh()->acknowledged_at);
        $this->assertNull($recipient->response_value);

        $this->reply('HELP I am trapped', $token)->assertOk()
            ->assertJson(['matched' => true, 'response' => RollCallService::NEEDS_HELP]);

        $this->assertSame(RollCallService::NEEDS_HELP, $recipient->refresh()->response_value);
        $this->assertSame(2, $this->responseAudits($recipient));

        $summary = app(RollCallService::class)->summary($recipient->alert);
        $this->assertSame(1, $summary['needs_help']);
        $this->assertCount(1, $summary['help_needed']);
        $this->assertSame(0, $summary['unaccounted_for']);
    }

    #[Test]
    public function safe_then_help_lands_needs_help_with_an_audit_row_carrying_the_previous_value(): void
    {
        [$recipient, $token] = $this->openRecipient();

        $this->reply('SAFE', $token)->assertOk();
        $this->reply('HELP', $token)->assertOk()->assertJson(['response' => RollCallService::NEEDS_HELP]);

        $this->assertSame(RollCallService::NEEDS_HELP, $recipient->refresh()->response_value);

        $audit = AuditLog::query()
            ->where('auditable_type', Alert::class)
            ->where('auditable_id', $recipient->alert_id)
            ->where('event', 'alert.response')
            ->orderByDesc('id')->first();

        $this->assertSame(2, $this->responseAudits($recipient));
        $after = $audit->after;
        $after = is_array($after) ? $after : json_decode((string) $after, true);
        $this->assertSame('safe', $after['previous_response']);
        $this->assertSame('needs_help', $after['response']);
    }

    #[Test]
    public function not_on_site_then_help_lands_needs_help(): void
    {
        [$recipient, $token] = $this->openRecipient();

        $this->reply('3', $token)->assertOk();
        $this->reply('help', $token)->assertOk();

        $this->assertSame(RollCallService::NEEDS_HELP, $recipient->refresh()->response_value);
    }

    #[Test]
    public function help_then_safe_stays_needs_help_and_writes_no_audit_row(): void
    {
        [$recipient, $token] = $this->openRecipient();

        $this->reply('HELP', $token)->assertOk();
        $this->reply('SAFE', $token)->assertOk();
        $this->reply('3', $token)->assertOk();

        $this->assertSame(RollCallService::NEEDS_HELP, $recipient->refresh()->response_value);
        $this->assertSame(1, $this->responseAudits($recipient));
    }

    #[Test]
    public function help_twice_gives_one_audit_row(): void
    {
        [$recipient, $token] = $this->openRecipient();

        $this->reply('HELP', $token)->assertOk();
        $this->reply('HELP', $token)->assertOk();

        $this->assertSame(1, $this->responseAudits($recipient));
    }

    #[Test]
    public function a_reply_after_the_response_window_closes_still_matches_nothing(): void
    {
        [$recipient, $token] = $this->openRecipient();

        $this->reply('SAFE', $token)->assertOk();
        $recipient->alert->forceFill(['dispatched_at' => now()->subDays(3)])->save();

        $this->reply('HELP', $token)->assertOk()->assertJson(['matched' => false]);
        $this->assertSame(RollCallService::SAFE, $recipient->refresh()->response_value);
    }

    #[Test]
    public function only_an_operator_on_the_board_can_clear_needs_help(): void
    {
        [$recipient, $token] = $this->openRecipient();
        $this->reply('HELP', $token)->assertOk();

        $this->actingAs($this->dispatcher())
            ->post(route('bcms.alerts.respond', [$recipient->alert, $recipient]), ['response' => 'safe'])
            ->assertRedirect();

        $this->assertSame(RollCallService::SAFE, $recipient->refresh()->response_value);
    }

    private function viewOnly(): User
    {
        $user = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Viewer',
            'email' => 'viewer@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $user->givePermissionTo(Permission::findOrCreate('bcms.alert.view', 'web'));

        return $user;
    }

    #[Test]
    public function a_view_only_user_cannot_clear_needs_help_from_the_board(): void
    {
        [$recipient, $token] = $this->openRecipient();
        $this->reply('HELP', $token)->assertOk();

        $this->actingAs($this->viewOnly())
            ->post(route('bcms.alerts.respond', [$recipient->alert, $recipient]), ['response' => 'safe'])
            ->assertForbidden();

        $this->assertSame(RollCallService::NEEDS_HELP, $recipient->refresh()->response_value);
        $this->assertSame(1, $this->responseAudits($recipient));
    }

    #[Test]
    public function a_view_only_user_cannot_set_a_status_on_an_unanswered_recipient(): void
    {
        [$recipient] = $this->openRecipient();

        $this->actingAs($this->viewOnly())
            ->post(route('bcms.alerts.respond', [$recipient->alert, $recipient]), ['response' => 'safe'])
            ->assertForbidden();

        $recipient->refresh();
        $this->assertNull($recipient->acknowledged_at);
        $this->assertNull($recipient->response_value);
    }

    #[Test]
    public function a_dispatcher_can_record_a_response_from_the_board(): void
    {
        [$recipient] = $this->openRecipient();

        $this->actingAs($this->dispatcher())
            ->post(route('bcms.alerts.respond', [$recipient->alert, $recipient]), ['response' => 'safe'])
            ->assertRedirect();

        $this->assertSame(RollCallService::SAFE, $recipient->refresh()->response_value);
    }

    /* ================================================================== */
    /*  MFA on dispatch with the flag ON (strict) - D2
    /* ================================================================== */

    #[Test]
    public function with_mfa_on_an_unenrolled_user_without_required_roles_cannot_dispatch(): void
    {
        config()->set('features.mfa_totp', true);
        $user = $this->dispatcher();
        $this->contacts(2);
        $alert = $this->alert(AlertSeverity::Advisory, ['sms']);
        Queue::fake();

        $this->actingAs($user)->post(route('bcms.alerts.dispatch', $alert))
            ->assertRedirect(route('mfa.setup'));

        $this->actingAs($user)->postJson(route('bcms.alerts.dispatch', $alert))->assertForbidden();

        $this->assertSame(0, AlertRecipient::query()->where('alert_id', $alert->getKey())->count());
        $this->assertNotSame('dispatching', $alert->refresh()->status);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function with_mfa_on_a_verified_session_can_dispatch_even_if_unenrolled_roles_say_nothing(): void
    {
        config()->set('features.mfa_totp', true);
        $user = $this->dispatcher();
        $this->contacts(2);
        $alert = $this->alert(AlertSeverity::Advisory, ['sms']);

        $this->actingAs($user)->withSession(['mfa_verified' => true])
            ->post(route('bcms.alerts.dispatch', $alert))->assertRedirect(route('bcms.alerts.show', $alert));

        $this->assertSame(2, AlertRecipient::query()->where('alert_id', $alert->getKey())->count());
    }

    #[Test]
    public function plain_mfa_alias_keeps_its_app_wide_behaviour_for_an_unenrolled_user(): void
    {
        config()->set('features.mfa_totp', true);

        $this->actingAs($this->dispatcher())->get(route('bcms.emns.index'))->assertOk();
    }

    /* ================================================================== */
    /*  Double-click on Dispatch - D3
    /* ================================================================== */

    #[Test]
    public function dispatching_twice_is_refused_cleanly_with_one_audit_and_no_second_set_of_rows(): void
    {
        Queue::fake();
        $this->actingAs($this->dispatcher());
        $this->contacts(3);
        $alert = $this->alert(AlertSeverity::Advisory, ['sms']);

        $this->post(route('bcms.alerts.dispatch', $alert))->assertRedirect(route('bcms.alerts.show', $alert));
        $this->assertSame(3, AlertRecipient::query()->where('alert_id', $alert->getKey())->count());

        $this->post(route('bcms.alerts.dispatch', $alert))->assertSessionHasErrors('dispatch');
        $this->postJson(route('bcms.alerts.dispatch', $alert))->assertStatus(422);

        $this->assertSame(3, AlertRecipient::query()->where('alert_id', $alert->getKey())->count());
        $this->assertSame(0, NotificationDelivery::query()->where('alert_id', $alert->getKey())->count());
        Queue::assertPushed(DispatchAlertChunkJob::class, 1);

        $refused = AuditLog::query()->where('auditable_type', Alert::class)
            ->where('auditable_id', $alert->getKey())->where('event', 'alert.dispatch_refused')->count();
        $this->assertSame(2, $refused, 'One refusal audit row per refused attempt.');
    }

    #[Test]
    public function release_refuses_an_alert_a_concurrent_request_released_after_the_unlocked_check(): void
    {
        $this->contacts(2);
        $alert = $this->alert(AlertSeverity::Advisory, ['sms']);

        // The "other request": a second in-memory copy that still reads draft.
        $stale = Alert::query()->findOrFail($alert->getKey());
        app(AlertService::class)->release($alert, $this->operator->id);

        $this->assertSame('draft', $stale->status);
        $stale->dispatched_at = null;

        $this->expectException(\InvalidArgumentException::class);

        try {
            app(AlertService::class)->release($stale, $this->operator->id);
        } finally {
            $this->assertSame(2, AlertRecipient::query()->where('alert_id', $alert->getKey())->count());
        }
    }

    /* ================================================================== */
    /*  Cycle 2 - D5 (retry must not re-send a channel), bare-number HELP,
    /*  and mfa:strict with the flag off.
    /* ================================================================== */

    #[Test]
    public function a_retry_after_a_crash_on_channel_two_does_not_resend_channel_one(): void
    {
        $this->contacts(1);
        $alert = $this->cleared($this->alert(AlertSeverity::Urgent, ['sms', 'email']));
        app(AlertService::class)->release($alert, $this->operator->id);
        $ids = AlertRecipient::query()->where('alert_id', $alert->getKey())->pluck('id')->map(fn ($i) => (int) $i)->all();

        $log = new \ArrayObject(['sms' => 0, 'email' => 0, 'email_fail' => true]);

        foreach ([ChannelKey::Sms, ChannelKey::Email] as $key) {
            app(ChannelRegistry::class)->swap($key, new class($key, $log) implements NotificationChannel
            {
                public function __construct(private ChannelKey $key, private \ArrayObject $log) {}

                public function key(): ChannelKey
                {
                    return $this->key;
                }

                public function provider(): string
                {
                    return 'counting';
                }

                public function supports(Recipient $to): bool
                {
                    return true;
                }

                public function send(Recipient $to, RenderedMessage $message): DeliveryReceipt
                {
                    $k = $this->key->value;

                    if ($k === 'email' && $this->log['email_fail']) {
                        throw new RuntimeException('worker killed after sms');
                    }

                    $this->log[$k] = $this->log[$k] + 1;

                    return DeliveryReceipt::sent('counting', $k.'-'.bin2hex(random_bytes(6)));
                }

                public function estimateCostMinor(Recipient $to, RenderedMessage $message): ?int
                {
                    return null;
                }
            });
        }

        $job = fn () => (new DispatchAlertChunkJob((int) $alert->getKey(), (int) $this->organization->id, $ids))
            ->handle(app(AlertDispatcher::class));

        try {
            $job();
            $this->fail('The crash should propagate so the queue retries.');
        } catch (RuntimeException) {
            // expected: sms left, email died
        }

        $this->assertSame(1, $log['sms']);

        $log['email_fail'] = false;
        $job();

        $this->assertSame(1, $log['sms'], 'The retry must not send the SMS a second time.');
        $this->assertSame(1, $log['email'], 'The retry sends the channel that never left.');
        $this->assertSame(1, NotificationDelivery::query()->where('alert_id', $alert->getKey())
            ->where('channel', 'sms')->count(), 'One SMS delivery row, not two.');
        $this->assertSame(1, NotificationDelivery::query()->where('alert_id', $alert->getKey())
            ->where('channel', 'email')->where('status', DeliveryStatus::Sent->value)->count());
    }

    #[Test]
    public function a_bare_number_help_after_an_earlier_safe_still_lands_needs_help(): void
    {
        [$recipient] = $this->openRecipient();
        $number = $recipient->contact->mobile_primary;

        $this->signedPost('bcms.alerts.reply', ['from' => $number, 'body' => 'SAFE'], 'termii')
            ->assertOk()->assertJson(['matched' => true]);
        $this->signedPost('bcms.alerts.reply', ['from' => $number, 'body' => 'HELP'], 'termii')
            ->assertOk()->assertJson(['matched' => true, 'response' => RollCallService::NEEDS_HELP]);

        $this->assertSame(RollCallService::NEEDS_HELP, $recipient->refresh()->response_value);
        $this->assertSame(2, $this->responseAudits($recipient));
    }

    #[Test]
    public function a_bare_number_reply_prefers_the_alert_still_waiting_and_never_touches_the_answered_one(): void
    {
        [$first] = $this->openRecipient();
        $number = $first->contact->mobile_primary;
        $this->signedPost('bcms.alerts.reply', ['from' => $number, 'body' => 'SAFE'], 'termii')->assertOk();

        // A second alert to the same person, unanswered.
        $second = $this->cleared($this->alert(AlertSeverity::Urgent, ['sms']));
        app(AlertService::class)->release($second, $this->operator->id);
        $waiting = AlertRecipient::query()->where('alert_id', $second->getKey())->firstOrFail();

        // A NON-help bare reply goes to the alert still waiting. (A bare HELP
        // would mark both - see a_bare_number_help_marks_every_open_match...)
        $this->signedPost('bcms.alerts.reply', ['from' => $number, 'body' => 'not on site'], 'termii')
            ->assertOk()->assertJson(['matched' => true]);

        $this->assertSame(RollCallService::NOT_ON_SITE, $waiting->refresh()->response_value);
        $this->assertSame(RollCallService::SAFE, $first->refresh()->response_value);
    }

    #[Test]
    public function a_bare_number_help_marks_every_open_match_and_each_tenant_only_its_own_row(): void
    {
        [$first] = $this->openRecipient();
        $number = $first->contact->mobile_primary;
        $this->signedPost('bcms.alerts.reply', ['from' => $number, 'body' => 'SAFE'], 'termii')->assertOk();

        $second = $this->cleared($this->alert(AlertSeverity::Urgent, ['sms']));
        app(AlertService::class)->release($second, $this->operator->id);
        $other = AlertRecipient::query()->where('alert_id', $second->getKey())->firstOrFail();
        $this->signedPost('bcms.alerts.reply', ['from' => $number, 'body' => 'SAFE'], 'termii')->assertOk();

        // Another tenant whose own contact record carries the same number.
        $foreign = $this->openRecipientInAnotherTenant();
        $foreign->contact()->withoutGlobalScopes()->update(['mobile_primary' => $number]);
        $foreignBefore = $foreign->refresh()->response_value;

        $this->signedPost('bcms.alerts.reply', ['from' => $number, 'body' => 'HELP'], 'termii')
            ->assertOk()->assertJson(['matched' => true]);

        TenantContext::set($this->organization->id);
        $this->assertSame(RollCallService::NEEDS_HELP, $first->refresh()->response_value);
        $this->assertSame(RollCallService::NEEDS_HELP, $other->refresh()->response_value);
        $this->assertSame(2, $this->helpAudits($first), 'safe, then the superseding help');
        $this->assertSame(2, $this->helpAudits($other));

        // The foreign tenant's own row is flagged in ITS organisation, on its own audit trail.
        $this->assertNull($foreignBefore);
        $this->assertSame(RollCallService::NEEDS_HELP, $foreign->refresh()->response_value);
        $this->assertSame((int) $foreign->organization_id, (int) $foreign->alert()->withoutGlobalScopes()->first()->organization_id);
    }

    #[Test]
    public function a_bare_number_non_help_reply_is_still_unmatched_when_two_open_alerts_fit(): void
    {
        [$first] = $this->openRecipient();
        $number = $first->contact->mobile_primary;

        $second = $this->cleared($this->alert(AlertSeverity::Urgent, ['sms']));
        app(AlertService::class)->release($second, $this->operator->id);
        $other = AlertRecipient::query()->where('alert_id', $second->getKey())->firstOrFail();

        $this->signedPost('bcms.alerts.reply', ['from' => $number, 'body' => 'SAFE'], 'termii')
            ->assertOk()->assertJson(['matched' => false]);

        $this->assertNull($first->refresh()->response_value);
        $this->assertNull($other->refresh()->response_value);
    }

    private function helpAudits(AlertRecipient $recipient): int
    {
        return AuditLog::query()->where('auditable_type', Alert::class)
            ->where('auditable_id', $recipient->alert_id)->where('event', 'alert.response')->count();
    }

    #[Test]
    public function a_stale_copy_cannot_clear_needs_help_that_a_concurrent_reply_wrote(): void
    {
        [$recipient] = $this->openRecipient();
        $a = AlertRecipient::query()->findOrFail($recipient->getKey());
        $b = AlertRecipient::query()->findOrFail($recipient->getKey());

        $rollCall = app(RollCallService::class);
        $rollCall->record($a, RollCallService::NEEDS_HELP, 'HELP trapped', 'sms');
        $rollCall->record($b, RollCallService::SAFE, 'SAFE', 'sms');

        $this->assertSame(RollCallService::NEEDS_HELP, $recipient->refresh()->response_value);
        $this->assertSame(RollCallService::NEEDS_HELP, $b->response_value, 'The stale caller instance is brought up to date.');
        $this->assertSame(1, $this->responseAudits($recipient));
    }

    #[Test]
    public function the_other_order_records_safe_then_a_superseding_help_with_the_flag(): void
    {
        [$recipient] = $this->openRecipient();
        $a = AlertRecipient::query()->findOrFail($recipient->getKey());
        $b = AlertRecipient::query()->findOrFail($recipient->getKey());

        $rollCall = app(RollCallService::class);
        $rollCall->record($a, RollCallService::SAFE, 'SAFE', 'sms');
        $rollCall->record($b, RollCallService::NEEDS_HELP, 'HELP', 'sms');

        $this->assertSame(RollCallService::NEEDS_HELP, $recipient->refresh()->response_value);
        $this->assertSame(2, $this->responseAudits($recipient));
    }

    #[Test]
    public function dispatch_does_not_overwrite_an_acknowledged_status_with_its_stale_sent(): void
    {
        [$recipient, $token] = $this->openRecipient();
        $alert = $recipient->alert;

        // Loaded as queued, exactly as the chunk job does...
        $batch = AlertRecipient::query()->whereKey($recipient->getKey())->with('contact')->get();
        $this->assertSame('queued', $batch->first()->status->value);

        // ...and a reply lands before the batch writes its status.
        $this->reply('SAFE', $token)->assertOk();

        app(AlertDispatcher::class)->dispatchBatch($alert, $batch);

        $this->assertSame('acknowledged', $recipient->refresh()->status->value);
        $this->assertSame(RollCallService::SAFE, $recipient->response_value);
    }

    #[Test]
    public function an_operator_clearing_needs_help_keeps_the_persons_own_words(): void
    {
        [$recipient, $token] = $this->openRecipient();
        $this->reply('HELP trapped 3rd floor', $token)->assertOk();

        $this->actingAs($this->dispatcher())
            ->post(route('bcms.alerts.respond', [$recipient->alert, $recipient]), ['response' => 'safe'])
            ->assertRedirect();

        $recipient->refresh();
        $this->assertSame(RollCallService::SAFE, $recipient->response_value);
        $this->assertStringContainsString('trapped 3rd floor', (string) $recipient->response_text);
    }

    #[Test]
    public function an_inbound_help_over_safe_stores_the_help_text(): void
    {
        [$recipient, $token] = $this->openRecipient();
        $this->reply('SAFE', $token)->assertOk();
        $this->reply('HELP trapped in lift', $token)->assertOk();

        $this->assertStringContainsString('trapped in lift', (string) $recipient->refresh()->response_text);
    }

    private function evidenceUser(array $permissions): User
    {
        $user = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Examiner '.uniqid(),
            'email' => 'ex'.uniqid().'@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
        }

        return $user;
    }

    #[Test]
    public function exporting_evidence_is_audited_with_and_without_contact_data_and_the_audit_holds_no_personal_data(): void
    {
        [$recipient] = $this->openRecipient();
        $alert = $recipient->alert;

        $this->actingAs($this->evidenceUser(['bcms.report.export']))
            ->get(route('bcms.alerts.evidence', $alert))->assertOk();
        $this->actingAs($this->evidenceUser(['bcms.report.export', 'bcms.contact.export']))
            ->get(route('bcms.alerts.evidence', $alert))->assertOk();

        $rows = AuditLog::query()->where('auditable_type', Alert::class)->where('auditable_id', $alert->getKey())
            ->where('event', 'alert.evidence_exported')->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $decode = fn ($r) => is_array($r->after) ? $r->after : json_decode((string) $r->after, true);
        $this->assertFalse($decode($rows[0])['include_contact_data']);
        $this->assertTrue($decode($rows[1])['include_contact_data']);
        $this->assertSame(1, $decode($rows[1])['rows']);
        $this->assertStringNotContainsString($recipient->contact->mobile_primary, json_encode($rows->toArray()));
    }

    #[Test]
    public function an_unsigned_status_callback_can_never_fail_a_delivery_but_can_still_confirm_one(): void
    {
        config()->set('bcms-gateways.webhook_secrets.termii', null);
        $delivery = $this->sentDelivery('termii', 'msg-unsigned-fail');

        $this->postJson(route('bcms.alerts.provider-status', ['provider' => 'termii']), [
            'message_id' => 'msg-unsigned-fail', 'status' => 'failed', 'reason' => 'Invalid recipient 2348031234567',
        ])->assertOk();

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Sent, $delivery->status);
        $this->assertNull($delivery->failed_reason);

        $this->postJson(route('bcms.alerts.provider-status', ['provider' => 'termii']), [
            'message_id' => 'msg-unsigned-fail', 'status' => 'delivered',
        ])->assertOk();
        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status);
    }

    #[Test]
    public function a_signed_failure_stores_a_closed_category_and_never_the_gateways_prose_or_number(): void
    {
        config()->set('bcms-gateways.webhook_secrets.termii', 'fail-secret');
        $delivery = $this->sentDelivery('termii', 'msg-signed-fail');

        $this->signedPost('bcms.alerts.provider-status', [
            'message_id' => 'msg-signed-fail', 'status' => 'failed', 'reason' => 'Invalid recipient 2348031234567',
        ], 'termii', secret: 'fail-secret')->assertOk();

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertStringContainsString('invalid_recipient', (string) $delivery->failed_reason);
        $this->assertStringNotContainsString('2348031234567', (string) $delivery->failed_reason);

        $other = $this->sentDelivery('termii', 'msg-signed-fail-2');
        $this->signedPost('bcms.alerts.provider-status', [
            'message_id' => 'msg-signed-fail-2', 'status' => 'failed', 'error' => 'Weird text for +2348031234567',
        ], 'termii', secret: 'fail-secret')->assertOk();

        $this->assertStringContainsString('not classified', (string) $other->refresh()->failed_reason);
        $this->assertStringNotContainsString('2348031234567', (string) $other->failed_reason);
    }

    #[Test]
    public function a_bare_number_help_is_capped_at_fifty_open_matches_and_the_fifty_first_is_left_alone(): void
    {
        $this->contacts(51);
        $alert = $this->cleared($this->alert(AlertSeverity::Urgent, ['sms']));
        app(AlertService::class)->release($alert, $this->operator->id);

        $number = '+2348155550000';
        Contact::query()->update(['mobile_primary' => $number]);

        $this->signedPost('bcms.alerts.reply', ['from' => $number, 'body' => 'HELP'], 'termii')
            ->assertOk()->assertJson(['matched' => true]);

        $this->assertSame(51, AlertRecipient::query()->where('alert_id', $alert->getKey())->count());
        $this->assertSame(50, AlertRecipient::query()->where('alert_id', $alert->getKey())
            ->where('response_value', RollCallService::NEEDS_HELP)->count(), 'Cap: exactly fifty are flagged.');
        $this->assertSame(1, AlertRecipient::query()->where('alert_id', $alert->getKey())
            ->whereNull('response_value')->count(), 'The fifty-first is not flagged.');
    }

    #[Test]
    public function with_mfa_off_the_strict_dispatch_route_does_not_strand_the_user(): void
    {
        config()->set('features.mfa_totp', false);
        $this->contacts(1);
        $alert = $this->alert(AlertSeverity::Advisory, ['sms']);
        Queue::fake();

        $this->actingAs($this->dispatcher())->post(route('bcms.alerts.dispatch', $alert))
            ->assertRedirect(route('bcms.alerts.show', $alert));
    }

    /* ================================================================== */
    /*  Helpers
    /* ================================================================== */

    private function responseAudits(AlertRecipient $recipient): int
    {
        return AuditLog::query()
            ->where('auditable_type', Alert::class)
            ->where('auditable_id', $recipient->alert_id)
            ->where('event', 'alert.response')
            ->count();
    }

    /** @return array{0: AlertRecipient, 1: string} */
    private function openRecipient(): array
    {
        $this->contact('Person '.uniqid());
        $alert = $this->cleared($this->alert(AlertSeverity::Urgent, ['sms']));
        app(AlertService::class)->release($alert, $this->operator->id);

        $recipient = AlertRecipient::query()->where('alert_id', $alert->getKey())->orderByDesc('id')->firstOrFail();

        return [$recipient, app(AlertDispatcher::class)->tokenFor($recipient)];
    }

    private function openRecipientInAnotherTenant(): AlertRecipient
    {
        $other = Organization::create([
            'name' => 'Lagos Trust MFB', 'short_name' => 'LTM',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);

        $foreignAlert = Alert::query()->create([
            'organization_id' => $other->id, 'title' => 'Theirs', 'message' => 'x',
            'severity' => 'urgent', 'status' => 'dispatching', 'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => 1], 'dispatched_at' => now(),
        ]);
        $foreignContact = Contact::query()->create([
            'organization_id' => $other->id, 'source' => ContactSource::Manual->value,
            'full_name' => 'Theirs', 'employee_id' => 'LTM-1', 'email' => 'theirs@ltm.test',
            'mobile_primary' => '+2348099990000', 'preferred_language' => 'en',
            'consent_status' => 'granted', 'verification_status' => 'verified',
            'last_verified_at' => now(), 'is_active' => true,
        ]);
        $recipient = AlertRecipient::query()->create([
            'organization_id' => $other->id, 'alert_id' => $foreignAlert->getKey(),
            'contact_id' => $foreignContact->getKey(), 'resolved_channels' => ['sms'],
            'contact_name_snapshot' => 'Theirs', 'status' => 'queued',
        ]);

        TenantContext::set($this->organization->id);

        return $recipient;
    }

    private function sentDelivery(string $provider, string $messageId): NotificationDelivery
    {
        [$recipient] = $this->openRecipient();

        return NotificationDelivery::query()->create([
            'organization_id' => $this->organization->id,
            'alert_id' => $recipient->alert_id,
            'recipient_contact_id' => $recipient->contact_id,
            'channel' => ChannelKey::Sms->value,
            'address' => '+2348000000001',
            'status' => DeliveryStatus::Sent->value,
            'provider' => $provider,
            'provider_message_id' => $messageId,
            'attempts' => 1,
            'sent_at' => now(),
        ]);
    }

    private function signedPost(string $route, array $payload, string $provider, string $secret = 'test-provider-secret', string $prefix = '')
    {
        config()->set('bcms-gateways.webhook_secrets.'.$provider, $secret);

        $timestamp = (string) time();
        $signature = $prefix.hash_hmac('sha256', $timestamp.'.'.json_encode($payload), $secret);

        return $this->postJson(route($route, ['provider' => $provider]), $payload, [
            'X-BCMS-Signature' => $signature,
            'X-BCMS-Timestamp' => $timestamp,
        ]);
    }

    private function dispatcher(): User
    {
        $user = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Dispatcher',
            'email' => 'dispatcher@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);

        foreach (['bcms.alert.view', 'bcms.alert.compose', 'bcms.alert.dispatch', 'bcms.alert.life_safety'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
        }

        return $user;
    }

    private function cleared(Alert $alert): Alert
    {
        $service = app(AlertService::class);

        if (! $service->requiresDualApproval($alert)) {
            return $alert;
        }

        $second = User::query()->firstOrCreate(
            ['email' => 'second@khb.test'],
            ['organization_id' => $this->organization->id, 'name' => 'Second Authoriser',
                'password' => bcrypt('secret'), 'is_active' => true],
        );

        $service->approve($alert, $this->operator);

        return $service->approve($alert->refresh(), $second);
    }

    private function alert(AlertSeverity $severity, array $channels): Alert
    {
        return app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Test alert',
            'message' => 'Please respond.',
            'severity' => $severity->value,
            'channels' => $channels,
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);
    }

    private function contacts(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->contact('Person '.$i);
        }
    }

    private function contact(string $name): Contact
    {
        static $n = 0;
        $n++;

        return Contact::query()->create([
            'organization_id' => $this->organization->id,
            'source' => ContactSource::Manual->value,
            'full_name' => $name,
            'employee_id' => 'G-'.$n.'-'.uniqid(),
            'business_unit_id' => $this->unit->id,
            'email' => 'gate'.$n.'-'.uniqid().'@khb.test',
            'mobile_primary' => '+2348100'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'preferred_language' => 'en',
            'consent_status' => 'granted',
            'verification_status' => 'verified',
            'last_verified_at' => now(),
            'is_active' => true,
        ]);
    }
}

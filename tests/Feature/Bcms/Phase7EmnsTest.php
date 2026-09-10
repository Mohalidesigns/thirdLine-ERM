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
use App\Models\Bcms\AlertTemplate;
use App\Models\Bcms\Contact;
use App\Models\Bcms\NotificationDelivery;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Emns\AlertDispatcher;
use App\Services\Bcms\Emns\AlertService;
use App\Services\Bcms\Emns\EscalationService;
use App\Services\Bcms\Emns\EvidenceExport;
use App\Services\Bcms\Emns\InboundResponseHandler;
use App\Services\Bcms\Emns\RollCallService;
use App\Services\Bcms\Emns\TemplateRenderer;
use App\Services\Bcms\Notification\ChannelRegistry;
use App\Services\Bcms\Notification\Channels\FailoverSmsChannel;
use App\Services\Bcms\Notification\Channels\SmsSegmenter;
use App\Services\Bcms\Notification\ProviderHealth;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * PHASE 7 — the Emergency Mass Notification System.
 *
 * THE CONTROLS ARE TESTED HARDER THAN THE HAPPY PATH, deliberately. An EMNS is
 * a button that puts a sentence on twelve thousand phones at three in the
 * morning; the interesting failures are all in the direction of it doing that
 * when it should not, or of somebody believing it did when it did not.
 *
 * NO TEST HERE ASSERTS ON A REAL GATEWAY. `Http::fake()` stands in for the
 * providers, which is the only honest way to exercise a Nigerian SMS failover
 * from a laptop. What is asserted is the DISPATCHER's behaviour — audience,
 * consent, failover, approval, simulation, evidence — and never a vendor's.
 */
class Phase7EmnsTest extends TestCase
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
    /*  Criterion 1 — reach, acknowledge, escalate, export.
    /* ================================================================== */

    #[Test]
    public function a_life_safety_alert_reaches_its_audience_and_produces_a_per_recipient_record(): void
    {
        $this->contacts(60);

        $alert = $this->alert(AlertSeverity::LifeSafety, ['sms', 'voice', 'email']);
        $service = app(AlertService::class);

        $estimate = $service->estimate($alert);
        $this->assertSame(60, $estimate['recipients']);
        $this->assertTrue($estimate['offline_capable'], 'A life-safety dispatch offers a channel that works with no data.');

        $service->release($this->cleared($alert), $this->operator->id);
        $this->dispatchAll($alert);

        $this->assertSame(60, AlertRecipient::query()->where('alert_id', $alert->getKey())->count());
        $this->assertGreaterThan(60, NotificationDelivery::query()->where('alert_id', $alert->getKey())->count(),
            'Three channels each, so more deliveries than people.');

        // One person, many channels, ONE acknowledgement.
        $rollCall = app(RollCallService::class);
        $first = AlertRecipient::query()->where('alert_id', $alert->getKey())->first();
        $rollCall->record($first, RollCallService::SAFE, null, 'sms');

        $summary = $rollCall->summary($alert->refresh());
        $this->assertSame(1, $summary['safe']);
        $this->assertSame(59, $summary['unaccounted_for']);

        // The evidence pack: one row per recipient per channel, and it names
        // the failures as plainly as the successes.
        $rows = app(EvidenceExport::class)->rows($alert);
        $this->assertGreaterThanOrEqual(60, count($rows));
        $this->assertContains('Provider message id', app(EvidenceExport::class)->columns());
    }

    /* ================================================================== */
    /*  Criterion 2 — 10,000 recipients queued fast, in chunks.
    /* ================================================================== */

    #[Test]
    public function a_large_dispatch_is_queued_in_chunks_rather_than_sent_in_the_request(): void
    {
        Queue::fake();

        $this->contacts(1200);

        $alert = $this->alert(AlertSeverity::Critical, ['sms']);
        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);

        $started = microtime(true);

        AlertRecipient::query()->where('alert_id', $alert->getKey())->select('id')
            ->chunkById(200, function ($chunk) use ($alert): void {
                DispatchAlertChunkJob::dispatch(
                    (int) $alert->getKey(),
                    (int) $alert->organization_id,
                    $chunk->pluck('id')->map(fn ($id) => (int) $id)->all(),
                )->onQueue($alert->severity->queue());
            });

        $elapsed = microtime(true) - $started;

        Queue::assertPushed(DispatchAlertChunkJob::class, 6);
        $this->assertLessThan(
            (float) config('bcms.nfr.dispatch_queue_seconds'),
            $elapsed,
            'Queueing must not be doing the sending.',
        );

        // LIFE SAFETY IS A SEPARATE POOL, NOT A PRIORITY (criterion 12,
        // standing rule 6). A "high priority" flag on a shared queue is still
        // behind whatever the worker is currently doing.
        $this->assertSame('bcms-alerts', $alert->severity->queue());
        $this->assertSame('bcms-lifesafety', AlertSeverity::LifeSafety->queue());
        $this->assertNotSame(AlertSeverity::LifeSafety->queue(), AlertSeverity::Advisory->queue());
    }

    /* ================================================================== */
    /*  Criterion 3 — SMS failover between gateways.
    /* ================================================================== */

    #[Test]
    public function a_failing_primary_gateway_fails_over_and_both_attempts_are_recorded(): void
    {
        Http::fake([
            'primary.test/*' => Http::response(['message' => 'service unavailable'], 503),
            'secondary.test/*' => Http::response(['message_id' => 'sec-1'], 200),
        ]);

        $channel = new FailoverSmsChannel(app(ProviderHealth::class), [
            ['name' => 'primary', 'endpoint' => 'https://primary.test/send', 'api_key' => 'k', 'sender_id' => 'KHB'],
            ['name' => 'secondary', 'endpoint' => 'https://secondary.test/send', 'api_key' => 'k', 'sender_id' => 'KHB'],
        ]);

        $contact = $this->contact('Amina');
        $receipt = $channel->send(Recipient::fromContact($contact), $this->message());

        $this->assertSame(DeliveryStatus::Sent, $receipt->status);
        $this->assertSame('secondary', $receipt->provider, 'The delivery row names the gateway that carried it.');

        // BOTH attempts are on the record. An audit showing only the successful
        // send answers "did it arrive" and not "how close did we come to it not
        // arriving" — and the second question is what gets a gateway replaced.
        $attempts = $receipt->rawResponse['attempts'] ?? [];
        $this->assertCount(2, $attempts);
        $this->assertSame('primary', $attempts[0]['provider']);
        $this->assertSame('failed', $attempts[0]['status']);

        // Health reflects it, and after three failures the gateway is demoted.
        $health = app(ProviderHealth::class);
        $health->recordFailure('primary');
        $health->recordFailure('primary');
        $this->assertTrue($health->isCoolingOff('primary'));
        $this->assertSame(['secondary', 'primary'], $health->order(['primary', 'secondary']));
    }

    #[Test]
    public function every_gateway_refusing_is_one_failure_that_names_them_all(): void
    {
        Http::fake(['*' => Http::response(['message' => 'nope'], 500)]);

        $channel = new FailoverSmsChannel(app(ProviderHealth::class), [
            ['name' => 'primary', 'endpoint' => 'https://a.test/s', 'api_key' => 'k', 'sender_id' => 'KHB'],
            ['name' => 'secondary', 'endpoint' => 'https://b.test/s', 'api_key' => 'k', 'sender_id' => 'KHB'],
        ]);

        $receipt = $channel->send(Recipient::fromContact($this->contact('Musa')), $this->message());

        $this->assertSame(DeliveryStatus::Failed, $receipt->status);
        $this->assertStringContainsString('primary', (string) $receipt->failedReason);
        $this->assertStringContainsString('secondary', (string) $receipt->failedReason);
    }

    #[Test]
    public function an_adapter_never_throws_when_a_gateway_is_unreachable(): void
    {
        // Rule 2 of the frozen interface, and the reason a bad number cannot
        // abort a thousand-recipient dispatch.
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('dns failure'));

        $channel = new FailoverSmsChannel(app(ProviderHealth::class), [
            ['name' => 'primary', 'endpoint' => 'https://a.test/s', 'api_key' => 'k', 'sender_id' => 'KHB'],
        ]);

        $receipt = $channel->send(Recipient::fromContact($this->contact('Chidi')), $this->message());

        $this->assertSame(DeliveryStatus::Failed, $receipt->status);
        $this->assertStringContainsString('unreachable', (string) $receipt->failedReason);
    }

    /* ================================================================== */
    /*  Criterion 4 — dual approval.
    /* ================================================================== */

    #[Test]
    public function an_alert_over_the_threshold_cannot_dispatch_without_a_second_authoriser(): void
    {
        $this->contacts(5);

        $alert = $this->alert(AlertSeverity::Critical, ['sms']);
        $service = app(AlertService::class);

        $this->assertTrue($service->requiresDualApproval($alert), 'Critical trips the severity threshold.');
        $this->assertFalse($service->isDispatchable($alert));

        $this->assertThrows(
            fn () => $service->release($alert, $this->operator->id),
            InvalidArgumentException::class,
        );

        $service->approve($alert, $this->operator);
        $this->assertFalse($service->isDispatchable($alert->refresh()), 'One signature is not two.');

        // THE SECOND AUTHORISER CANNOT BE THE FIRST — the first thing an
        // auditor tests.
        $this->assertThrows(
            fn () => $service->approve($alert->refresh(), $this->operator),
            InvalidArgumentException::class,
        );

        $second = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Deputy MD',
            'email' => 'deputy@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);

        $service->approve($alert->refresh(), $second);
        $this->assertTrue($service->isDispatchable($alert->refresh()));
    }

    #[Test]
    public function a_large_advisory_trips_the_recipient_threshold_and_a_simulation_never_does(): void
    {
        $alert = $this->alert(AlertSeverity::Advisory, ['email']);
        $alert->forceFill(['recipient_count' => 900])->save();

        $service = app(AlertService::class);
        $this->assertTrue($service->requiresDualApproval($alert),
            'Nine hundred people is a blast radius somebody should confirm, whatever the severity.');

        $alert->forceFill(['is_simulation' => true])->save();
        $this->assertFalse($service->requiresDualApproval($alert->refresh()),
            'Requiring a signature to run a drill is how operators learn to route around the control.');
    }

    /* ================================================================== */
    /*  Criterion 5 — simulation.
    /* ================================================================== */

    #[Test]
    public function an_exercise_alert_defaults_to_simulation_and_dispatches_nothing(): void
    {
        $this->contacts(4);

        $occurrence = $this->occurrence();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Evacuate the building',
            'message' => 'Leave by the nearest exit and assemble at the car park.',
            'severity' => AlertSeverity::LifeSafety->value,
            'channels' => ['sms'],
            'occurrence_id' => $occurrence,
            // THERE IS NO "EVERYONE". The G0 grammar deliberately has no such
            // leaf and `audience_rule` is NOT NULL: a rule that resolves to
            // nobody resolves to nobody and never falls back to everybody. The
            // whole-organisation audience is named explicitly, as the root org
            // node with its descendants.
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        $this->assertTrue((bool) $alert->is_simulation, 'Exercise-linked alerts default to simulation.');

        $captured = [];
        $this->captureChannel(ChannelKey::Sms, $captured);

        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);
        $this->dispatchAll($alert);

        $this->assertSame([], $captured, 'A simulation calls no adapter at all.');

        // But it writes the whole record, so the operator sees what a live
        // dispatch would have produced.
        $deliveries = NotificationDelivery::query()->where('alert_id', $alert->getKey())->get();
        $this->assertCount(4, $deliveries);

        foreach ($deliveries as $delivery) {
            $this->assertSame('simulation', $delivery->provider);
            $this->assertTrue($delivery->raw_response['simulated']);
            $this->assertStringStartsWith(
                RenderedMessage::EXERCISE_PREFIX,
                (string) $delivery->raw_response['would_have_sent'],
                'Standing rule 5: the prefix on every channel, always.',
            );
        }
    }

    /* ================================================================== */
    /*  Criterion 6 — roll-call and manager escalation.
    /* ================================================================== */

    #[Test]
    public function unanswered_recipients_escalate_to_their_manager_once_and_as_one_message(): void
    {
        $manager = $this->contact('Line Manager');
        $team = [];

        foreach (['Amina', 'Chidi', 'Musa'] as $name) {
            $team[] = $this->contact($name, managerUserId: $manager->user_id);
        }

        $alert = $this->alert(AlertSeverity::Urgent, ['sms']);
        $alert->forceFill(['ack_window_minutes' => 5])->save();

        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);
        $this->dispatchAll($alert);

        // One of them answers; the other two do not.
        $answered = AlertRecipient::query()->where('alert_id', $alert->getKey())
            ->where('contact_id', $team[0]->getKey())->first();
        app(RollCallService::class)->record($answered, RollCallService::SAFE);

        // Escalation reaches a manager on VOICE first — somebody told four
        // people are missing should have their phone ring, not buzz.
        $captured = [];
        $this->captureChannel(ChannelKey::Voice, $captured);

        $this->travel(10)->minutes();
        $result = app(EscalationService::class)->escalateUnacknowledged($alert->refresh());
        $this->travelBack();

        // ONE message to the manager naming both, not one per missing person: a
        // manager with nine unaccounted-for staff must not get nine texts while
        // trying to find them.
        $toManager = array_values(array_filter(
            $captured,
            fn (array $c) => $c['to']->contactId === $manager->getKey(),
        ));

        $this->assertCount(1, $toManager);
        $this->assertStringContainsString('Chidi', $toManager[0]['message']->body);
        $this->assertStringContainsString('Musa', $toManager[0]['message']->body);
        $this->assertStringNotContainsString('Amina', $toManager[0]['message']->body,
            'The person who answered is not on the missing list.');

        $this->assertSame(1, $result['managers_notified']);

        // Three escalations, not two: the manager is themselves on this alert
        // and did not answer either. They have nobody above them, so they are
        // recorded as escalated-with-nobody-told rather than quietly dropped —
        // the crisis screen has to show that gap.
        $this->assertSame(3, $result['escalated']);
        $this->assertSame(1, $result['no_manager']);

        $summary = app(RollCallService::class)->summary($alert->refresh());
        $this->assertSame(3, $summary['escalated']);
    }

    #[Test]
    public function a_simulation_never_wakes_a_real_manager(): void
    {
        $manager = $this->contact('Line Manager');
        $this->contact('Amina', managerUserId: $manager->user_id);

        $alert = $this->alert(AlertSeverity::Urgent, ['sms']);
        $alert->forceFill(['is_simulation' => true, 'ack_window_minutes' => 1])->save();

        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);

        $captured = [];
        $this->captureChannel(ChannelKey::Voice, $captured);

        $this->travel(5)->minutes();
        app(EscalationService::class)->escalateUnacknowledged($alert->refresh());
        $this->travelBack();

        $this->assertSame([], $captured,
            'A branch manager rung at 3am by a drill is the last drill that bank runs.');

        // The rows still say escalated, so the operator sees what would have
        // happened.
        $this->assertGreaterThan(0, AlertRecipient::query()
            ->where('alert_id', $alert->getKey())->whereNotNull('escalated_at')->count());
    }

    #[Test]
    public function the_roll_call_never_merges_silent_with_unreachable(): void
    {
        $reachable = $this->contact('Amina');
        $unreachable = $this->contact('Ghost');
        $unreachable->update(['mobile_primary' => null, 'mobile_secondary' => null, 'email' => null]);

        $alert = $this->alert(AlertSeverity::LifeSafety, ['sms']);
        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);

        $summary = app(RollCallService::class)->summary($alert->refresh());

        $this->assertSame(1, $summary['unreachable'], 'Never called at all.');
        $this->assertSame(1, $summary['silent'], 'Called and has not answered.');
        $this->assertSame(2, $summary['unaccounted_for']);

        $states = array_column($summary['unaccounted'], 'state');
        $this->assertContains('unreachable', $states);
        $this->assertContains('silent', $states);
    }

    /* ================================================================== */
    /*  Criterion 7 — two-way responses.
    /* ================================================================== */

    #[Test]
    public function replies_arrive_from_sms_whatsapp_and_ussd_and_land_on_the_recipient(): void
    {
        $this->contacts(3);

        $alert = $this->alert(AlertSeverity::LifeSafety, ['sms']);
        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);

        $recipients = AlertRecipient::query()->where('alert_id', $alert->getKey())->get();
        $handler = app(InboundResponseHandler::class);
        $dispatcher = app(AlertDispatcher::class);

        foreach ([['sms', 'SAFE'], ['whatsapp', 'I am safe'], ['ussd', '1']] as $i => [$channel, $body]) {
            $token = $dispatcher->tokenFor($recipients[$i]);

            $this->postJson(route('bcms.alerts.reply'), [
                'from' => '+2348000000'.$i,
                'body' => $body.' '.$token,
                'channel' => $channel,
            ])->assertOk()->assertJson(['matched' => true, 'response' => RollCallService::SAFE]);
        }

        $this->assertSame(3, app(RollCallService::class)->summary($alert->refresh())['safe']);

        // An unmatched reply is a 200 with matched:false — a gateway that gets
        // an error retries, and a retried unmatched reply is a loop that costs
        // money.
        $this->postJson(route('bcms.alerts.reply'), ['body' => 'who is this'])
            ->assertOk()->assertJson(['matched' => false]);
    }

    #[Test]
    public function help_beats_safe_in_an_ambiguous_reply(): void
    {
        $handler = app(InboundResponseHandler::class);

        // A FALSE SAFE STOPS SOMEBODY LOOKING. That asymmetry is why help is
        // matched first and on a substring.
        $this->assertSame(RollCallService::NEEDS_HELP, $handler->interpret('I am safe but Musa needs help'));
        $this->assertSame(RollCallService::SAFE, $handler->interpret('safe'));
        $this->assertSame(RollCallService::SAFE, $handler->interpret('lafiya'));
        $this->assertSame(RollCallService::NOT_ON_SITE, $handler->interpret('not on site today'));

        // "ok" inside "broken" must not mark an injured person safe.
        $this->assertNull($handler->interpret('my leg is broken'), 'Whole words only.');
        $this->assertNull($handler->interpret('call me'), 'Unclear is an acknowledgement, not a status.');
    }

    #[Test]
    public function a_delivery_receipt_never_moves_a_record_backwards(): void
    {
        $this->contacts(1);
        $alert = $this->alert(AlertSeverity::Urgent, ['sms']);
        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);
        $this->dispatchAll($alert);

        $delivery = NotificationDelivery::query()->where('alert_id', $alert->getKey())->first();
        $delivery->forceFill(['provider_message_id' => 'msg-1'])->save();

        $handler = app(InboundResponseHandler::class);

        $handler->handleStatusReceipt('termii', ['message_id' => 'msg-1', 'status' => 'delivered']);
        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status);

        // Gateways deliver webhooks out of order. A late "sent" must not walk
        // the evidence backwards.
        $handler->handleStatusReceipt('termii', ['message_id' => 'msg-1', 'status' => 'sent']);
        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status);

        $handler->handleStatusReceipt('termii', ['message_id' => 'msg-1', 'status' => 'read']);
        $this->assertSame(DeliveryStatus::Read, $delivery->refresh()->status);
    }

    /* ================================================================== */
    /*  Criterion 8 — quiet hours.
    /* ================================================================== */

    #[Test]
    public function life_safety_bypasses_quiet_hours_and_an_advisory_does_not(): void
    {
        app(\App\Services\Bcms\BcmsSettings::class)->update([
            'quiet_hours_start' => '22:00:00',
            'quiet_hours_end' => '06:00:00',
        ]);

        // 02:00 Lagos, squarely inside the window.
        $this->travelTo(now()->setTimezone('Africa/Lagos')->startOfDay()->addHours(2)->utc());

        $service = app(AlertService::class);

        $this->assertTrue($service->isHeldByQuietHours($this->alert(AlertSeverity::Advisory, ['email'])));
        $this->assertFalse($service->isHeldByQuietHours($this->alert(AlertSeverity::LifeSafety, ['sms'])));
        $this->assertFalse($service->isHeldByQuietHours($this->alert(AlertSeverity::Critical, ['sms'])),
            'Critical never waits either.');

        $this->travelBack();
    }

    /* ================================================================== */
    /*  Criterion 9 — the estimate is close to the truth.
    /* ================================================================== */

    #[Test]
    public function the_cost_estimate_is_within_five_per_cent_of_what_is_actually_spent(): void
    {
        config()->set('bcms-gateways.sms', [[
            'name' => 'termii', 'endpoint' => 'https://sms.test/send', 'api_key' => 'k',
            'sender_id' => 'KHB', 'cost_minor' => 350, 'currency' => 'NGN',
        ]]);
        config()->set('bcms.channels.sms', FailoverSmsChannel::class);

        Http::fake(['sms.test/*' => Http::response(['message_id' => 'm-1'], 200)]);

        $this->contacts(40);

        $alert = $this->alert(AlertSeverity::Urgent, ['sms']);
        $estimate = app(AlertService::class)->estimate($alert);

        $this->assertNotNull($estimate['estimated_cost_minor']);

        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);
        $this->dispatchAll($alert);

        $actual = (int) NotificationDelivery::query()
            ->where('alert_id', $alert->getKey())->sum('cost_minor');

        $this->assertGreaterThan(0, $actual);

        $drift = abs($actual - $estimate['estimated_cost_minor']) / max(1, $actual);
        $this->assertLessThanOrEqual(0.05, $drift,
            'Criterion 9: the estimate is built the same way the dispatch is, so it cannot drift.');
    }

    #[Test]
    public function an_unpriced_channel_reports_null_rather_than_zero(): void
    {
        $this->contacts(3);
        $alert = $this->alert(AlertSeverity::Advisory, ['email']);

        $estimate = app(AlertService::class)->estimate($alert);

        // Zero would tell a CFO the dispatch was free.
        $this->assertNull($estimate['estimated_cost_minor']);
        $this->assertGreaterThan(0, $estimate['unpriced_messages']);
    }

    /* ================================================================== */
    /*  Criterion 10 — languages and SMS length.
    /* ================================================================== */

    #[Test]
    public function sms_is_never_truncated_mid_word_and_segments_are_counted_honestly(): void
    {
        $long = str_repeat('Assemble at the main car park immediately. ', 12);

        $cut = SmsSegmenter::truncate($long, 1);
        $this->assertLessThanOrEqual(SmsSegmenter::GSM_SINGLE, SmsSegmenter::units($cut));
        $this->assertStringEndsWith('…', $cut);

        // The cut text is a WHOLE-WORD PREFIX of the original. A word-boundary
        // cut still ends in a letter, so the test is that the next character in
        // the source is a boundary — not that the result looks a certain way.
        $kept = rtrim(mb_substr($cut, 0, -1));
        $this->assertStringStartsWith($kept, $long);
        $this->assertSame(' ', mb_substr($long, mb_strlen($kept), 1), 'Never cut mid-word.');

        // A single curly apostrophe drops the whole message to UCS-2 and 70
        // characters a segment — the pasted-from-Word bill nobody can explain.
        $plain = str_repeat('a', 100);
        $this->assertSame(1, SmsSegmenter::segments($plain));
        $this->assertTrue(SmsSegmenter::isGsm7($plain));

        $smart = str_repeat('a', 100).'’';
        $this->assertFalse(SmsSegmenter::isGsm7($smart));
        $this->assertSame(2, SmsSegmenter::segments($smart));
        $this->assertSame(['’'], SmsSegmenter::nonGsmCharacters($smart));
    }

    #[Test]
    public function a_missing_translation_falls_back_to_english_and_says_that_it_did(): void
    {
        $template = AlertTemplate::query()->create([
            'organization_id' => $this->organization->id,
            'code' => 'EVAC-T', 'name' => 'Evacuate', 'locale' => 'en',
            'body' => 'Leave the building now.', 'severity' => 'life_safety',
            'is_life_safety' => true, 'is_active' => true,
        ]);

        // A Hausa row that has NOT been reviewed. It must never be sent.
        AlertTemplate::query()->create([
            'organization_id' => $this->organization->id,
            'code' => 'EVAC-T', 'name' => 'Evacuate', 'locale' => 'ha',
            'body' => 'DRAFT — not reviewed', 'severity' => 'life_safety',
            'is_life_safety' => true, 'is_active' => false,
        ]);

        $alert = $this->alert(AlertSeverity::LifeSafety, ['sms']);
        $alert->forceFill(['template_id' => $template->getKey()])->save();

        $message = app(TemplateRenderer::class)->render($alert->refresh(), ChannelKey::Sms, 'ha');

        $this->assertSame('Leave the building now.', $message->body);
        $this->assertSame('en', $message->locale);
        $this->assertTrue($message->metadata['locale_fell_back'],
            'A silent fallback would let a bank believe it had five-language cover for years.');

        $coverage = app(TemplateRenderer::class)->coverage();
        $row = collect($coverage['scenarios'])->firstWhere('code', 'EVAC-T');
        $this->assertSame('live', $row['locales']['en']['state']);
        $this->assertSame('awaiting_review', $row['locales']['ha']['state']);
        $this->assertSame('not_authored', $row['locales']['yo']['state']);
    }

    #[Test]
    public function an_unfilled_placeholder_is_removed_rather_than_printed(): void
    {
        $alert = $this->alert(AlertSeverity::Urgent, ['sms']);
        $alert->forceFill(['message' => 'Assemble at {{assembly_point}} now.'])->save();

        $body = app(TemplateRenderer::class)->render($alert->refresh(), ChannelKey::Sms, 'en')->body;

        $this->assertStringNotContainsString('{{', $body);
        $this->assertStringNotContainsString('assembly_point', $body);
    }

    /* ================================================================== */
    /*  Criterion 11 — evidence export.
    /* ================================================================== */

    #[Test]
    public function the_evidence_export_names_the_people_nothing_was_sent_to(): void
    {
        $reachable = $this->contact('Amina');
        $unreachable = $this->contact('Ghost');
        $unreachable->update(['mobile_primary' => null, 'mobile_secondary' => null, 'email' => null]);

        $alert = $this->alert(AlertSeverity::LifeSafety, ['sms']);
        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);
        $this->dispatchAll($alert);

        $rows = app(EvidenceExport::class)->rows($alert->refresh());
        $flat = array_map(fn (array $r) => implode('|', $r), $rows);

        $this->assertTrue(
            collect($flat)->contains(fn (string $r) => str_contains($r, 'Ghost')
                && str_contains($r, 'No usable channel')),
            'A pack that omitted the people nothing was sent to would be true and completely misleading.',
        );
        $this->assertTrue(collect($flat)->contains(fn (string $r) => str_contains($r, 'Amina')));
    }

    /* ================================================================== */
    /*  Criterion 13 — MFA on dispatch.
    /* ================================================================== */

    #[Test]
    public function the_dispatch_route_requires_a_recent_mfa_assertion(): void
    {
        $middleware = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->getName() === 'bcms.alerts.dispatch')
            ?->gatherMiddleware() ?? [];

        $this->assertContains('mfa', $middleware,
            'Criterion 13: live dispatch ships in this phase, so a password alone is not a '
            .'proportionate control over putting a sentence on twelve thousand phones.');

        // And it is NOT on compose or approve — an operator drafting under
        // stress hits the second factor once, at the moment it matters.
        $compose = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->getName() === 'bcms.alerts.store')?->gatherMiddleware() ?? [];
        $this->assertNotContains('mfa', $compose);
    }

    /* ================================================================== */
    /*  Criterion 14 — Phase 5 and 6 run unchanged on the real adapters.
    /* ================================================================== */

    #[Test]
    public function the_registry_falls_back_to_the_mock_when_a_real_adapter_has_no_credentials(): void
    {
        // This is what makes criterion 14 hold in practice. An operator names
        // the real class the day the contract is signed; the sender ID clears
        // weeks later. Without the fallback the naming would break every
        // dispatch in between, so it would be done late and all at once.
        config()->set('bcms.channels.sms', FailoverSmsChannel::class);
        config()->set('bcms-gateways.sms', [['name' => 'termii', 'endpoint' => 'https://x', 'api_key' => null, 'sender_id' => null]]);

        $registry = new ChannelRegistry;

        $this->assertTrue($registry->isMock(ChannelKey::Sms));
        $state = collect($registry->states())->firstWhere('channel', 'sms');
        $this->assertSame('awaiting_credentials', $state['status']);

        config()->set('bcms-gateways.sms', [[
            'name' => 'termii', 'endpoint' => 'https://x', 'api_key' => 'k', 'sender_id' => 'KHB',
        ]]);

        $live = new ChannelRegistry;
        $this->assertFalse($live->isMock(ChannelKey::Sms));
        $this->assertSame('live', collect($live->states())->firstWhere('channel', 'sms')['status']);
    }

    #[Test]
    public function consent_withdrawal_removes_a_channel_and_not_a_person(): void
    {
        $withdrawn = $this->contact('Private');
        $withdrawn->update([
            'consent_status' => 'withdrawn',
            'consent_withdrawn_at' => now(),
        ]);

        // A ROUTINE ALERT excludes their personal phone; they keep their
        // corporate email and stay in the audience and in the headcount.
        $routine = $this->alert(AlertSeverity::Urgent, ['sms', 'email']);
        app(AlertService::class)->release($this->cleared($routine), $this->operator->id);

        $row = AlertRecipient::query()->where('alert_id', $routine->getKey())
            ->where('contact_id', $withdrawn->getKey())->first();

        $this->assertNotNull($row, 'Consent removes channels, never people.');
        $this->assertNotContains('sms', (array) $row->resolved_channels);
        $this->assertContains('email', (array) $row->resolved_channels);

        // A LIFE-SAFETY dispatch reaches them on the phone anyway — the NDPA's
        // vital-interests basis, narrow and recorded.
        $lifeSafety = $this->alert(AlertSeverity::LifeSafety, ['sms']);
        app(AlertService::class)->release($this->cleared($lifeSafety), $this->operator->id);

        $emergency = AlertRecipient::query()->where('alert_id', $lifeSafety->getKey())
            ->where('contact_id', $withdrawn->getKey())->first();

        $this->assertContains('sms', (array) $emergency->resolved_channels);
    }

    #[Test]
    public function an_audience_that_resolves_to_nobody_is_refused(): void
    {
        $alert = $this->alert(AlertSeverity::Urgent, ['sms']);

        $this->assertThrows(
            fn () => app(AlertService::class)->release($alert, $this->operator->id),
            InvalidArgumentException::class,
        );
    }

    /* ================================================================== */
    /*  Helpers
    /* ================================================================== */

    /**
     * Clear the dual-approval gate for the tests that are not about it.
     *
     * Life-safety and critical traffic trips the severity threshold by design,
     * so most of these tests would otherwise be testing the approval control
     * over and over instead of the thing they name.
     */
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

    private function contact(string $name, ?int $managerUserId = null): Contact
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'organization_id' => $this->organization->id,
            'name' => $name,
            'email' => 'p'.$n.'@khb.test',
            'password' => bcrypt('secret'),
            'is_active' => false,
        ]);

        return Contact::query()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
            'source' => ContactSource::Manual->value,
            'full_name' => $name,
            'employee_id' => 'E-'.$n,
            'business_unit_id' => $this->unit->id,
            'manager_user_id' => $managerUserId,
            'email' => 'contact'.$n.'@khb.test',
            'mobile_primary' => '+2348000'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'preferred_language' => 'en',
            'consent_status' => 'granted',
            'verification_status' => 'verified',
            'last_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    /**
     * A real occurrence row, because the simulation default keys off the FK
     * and a null would prove nothing.
     */
    private function occurrence(): int
    {
        $programme = \App\Models\Bcms\ExerciseProgramme::query()->create([
            'organization_id' => $this->organization->id,
            'year' => (int) now()->year,
            'name' => 'Programme',
            'status' => 'draft',
        ]);

        $type = \App\Models\Bcms\ExerciseType::query()->first();

        $definition = \App\Models\Bcms\ExerciseDefinition::query()->create([
            'organization_id' => $this->organization->id,
            'exercise_programme_id' => $programme->getKey(),
            'exercise_type_id' => $type?->getKey(),
            'name' => 'Evacuation drill',
            'frequency_per_year' => 1,
        ]);

        return (int) \App\Models\Bcms\ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => 1,
            'scheduled_date' => now()->addDay()->toDateString(),
            'status' => \App\Enums\Bcms\OccurrenceStatus::Planned->value,
        ])->getKey();
    }

    private function message(): RenderedMessage
    {
        return new RenderedMessage(body: 'Evacuate now.', subject: 'Evacuate');
    }

    /** Run every queued chunk inline. */
    private function dispatchAll(Alert $alert): void
    {
        $ids = AlertRecipient::query()->where('alert_id', $alert->getKey())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach (array_chunk($ids, 200) as $chunk) {
            (new DispatchAlertChunkJob((int) $alert->getKey(), (int) $alert->organization_id, $chunk))
                ->handle(app(AlertDispatcher::class));
        }

        TenantContext::set($this->organization->id);
    }

    /** @param list<array{to: Recipient, message: RenderedMessage}> $captured */
    private function captureChannel(ChannelKey $key, array &$captured): void
    {
        app(ChannelRegistry::class)->swap($key, new class($key, $captured) implements NotificationChannel
        {
            /** @param list<array{to: Recipient, message: RenderedMessage}> $captured */
            public function __construct(private ChannelKey $key, private array &$captured) {}

            public function key(): ChannelKey
            {
                return $this->key;
            }

            public function provider(): string
            {
                return 'capture-'.$this->key->value;
            }

            public function supports(Recipient $to): bool
            {
                return true;
            }

            public function send(Recipient $to, RenderedMessage $message): DeliveryReceipt
            {
                $this->captured[] = ['to' => $to, 'message' => $message];

                return DeliveryReceipt::sent($this->provider(), 'cap-'.count($this->captured));
            }

            public function estimateCostMinor(Recipient $to, RenderedMessage $message): ?int
            {
                return null;
            }
        });
    }
}

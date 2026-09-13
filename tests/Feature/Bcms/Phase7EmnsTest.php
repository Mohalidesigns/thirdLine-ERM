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
use App\Jobs\Bcms\EscalateAlertRecipientsJob;
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

    /**
     * Defect 5 (Gate 1): criterion 5's other half — "a live dispatch from an
     * exercise context requires dual approval" — had no test, and no endpoint
     * could reach it: `store()` never accepted `is_simulation`, so
     * `AlertController::dispatchAlert()`'s `bcms.alert.life_safety` branch was
     * dead code. `AlertService::escalateLive()` and the `alerts.escalate-live`
     * route are what closes that. This tests the service; the permission gate
     * on the route is tested in `Phase7ScreensTest`.
     */
    #[Test]
    public function escalating_an_exercise_to_live_trips_dual_approval_unconditionally(): void
    {
        $this->contacts(2);
        $occurrence = $this->occurrence();

        $alert = $this->alertOn($occurrence, AlertSeverity::Advisory, ['email']);
        $this->assertTrue((bool) $alert->is_simulation);

        // An advisory to two people trips neither the severity nor the
        // headcount threshold — proving the "unconditional" half of the rule,
        // not the ordinary dual-approval path criterion 4 already covers.
        $this->assertFalse(app(AlertService::class)->requiresDualApproval($alert));

        $alert = app(AlertService::class)->escalateLive($alert, $this->operator);

        $this->assertFalse((bool) $alert->is_simulation);
        $this->assertTrue(
            app(AlertService::class)->requiresDualApproval($alert),
            'Standing rule 5: a live dispatch from an exercise context always needs a second pair of '
                .'eyes, whatever the severity or the headcount.',
        );

        // One authoriser is not dual approval.
        $this->assertThrows(
            fn () => app(AlertService::class)->release($alert, $this->operator->id),
            InvalidArgumentException::class,
        );

        $second = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Second Authoriser',
            'email' => 'second-escalate@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);

        app(AlertService::class)->approve($alert, $this->operator);
        app(AlertService::class)->approve($alert->refresh(), $second);

        $this->assertTrue(app(AlertService::class)->isDispatchable($alert->refresh()));

        $captured = [];
        $this->captureChannel(ChannelKey::Email, $captured);

        app(AlertService::class)->release($alert->refresh(), $second->id);
        $this->dispatchAll($alert);

        $this->assertNotEmpty($captured, 'Escalated to live: it must actually reach the adapter.');

        foreach ($captured as $sent) {
            $this->assertStringStartsNotWith(
                RenderedMessage::EXERCISE_PREFIX,
                (string) $sent['message']->body,
                'Once escalated, the message reaching people is the real one, not the exercise one.',
            );
        }
    }

    #[Test]
    public function escalating_live_is_refused_off_an_exercise_context_or_off_an_already_live_alert(): void
    {
        $service = app(AlertService::class);

        // Only an alert linked to an exercise can be escalated.
        $routine = $this->alert(AlertSeverity::Advisory, ['email']);
        $this->assertThrows(
            fn () => $service->escalateLive($routine, $this->operator),
            InvalidArgumentException::class,
        );

        $occurrence = $this->occurrence();
        $exercise = $this->alertOn($occurrence, AlertSeverity::Advisory, ['email']);
        $live = $service->escalateLive($exercise, $this->operator);

        // Escalating an alert that is already live is refused rather than
        // silently repeated.
        $this->assertThrows(
            fn () => $service->escalateLive($live->refresh(), $this->operator),
            InvalidArgumentException::class,
        );
    }

    /**
     * GATE 2 DEFECT 3, PERMANENT REGRESSION TEST. Alice approves the exercise,
     * Bob approves, Carol escalates — both signatures were already on the row
     * at that point, so before this fix the alert was instantly dispatchable
     * the moment it went live, with nobody having signed off on a LIVE
     * dispatch at all.
     *
     * `approve()` on a simulation now throws (see the guard's own
     * commentary), so the two signatures here are forced onto the row with
     * `forceFill` rather than collected through `approve()` — reproducing the
     * exact shape the defect described (signatures present on a
     * still-simulated alert) regardless of which code path could have put
     * them there. What this test certifies is `escalateLive()`'s side of the
     * contract: it must withdraw whatever is on the record, unconditionally,
     * rather than trust that nothing could be there.
     *
     * One-line change that would make this fail: deleting the four
     * `forceFill` nulls in `AlertService::escalateLive()`.
     */
    #[Test]
    public function escalating_a_drill_that_already_carries_approvals_withdraws_them(): void
    {
        $this->contacts(2);
        $occurrence = $this->occurrence();
        $alert = $this->alertOn($occurrence, AlertSeverity::Advisory, ['email']);
        $this->assertTrue((bool) $alert->is_simulation);

        $alice = $this->operator;
        $bob = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Bob',
            'email' => 'bob@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $carol = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Carol',
            'email' => 'carol@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);

        // Alice and Bob's signatures land on the row while it is still a
        // simulation — the state the defect actually found in the wild.
        $alert->forceFill([
            'approved_by' => $alice->id, 'approved_at' => now(),
            'second_approved_by' => $bob->id, 'second_approved_at' => now(),
        ])->save();

        $this->assertTrue(
            app(AlertService::class)->isDispatchable($alert->refresh(), 2),
            'Sanity check: two signatures on a simulation read as dispatchable before Carol escalates — '
                .'this is exactly the state that must not survive the escalation.',
        );

        // Carol escalates.
        $live = app(AlertService::class)->escalateLive($alert->refresh(), $carol);

        $this->assertFalse((bool) $live->is_simulation);
        $this->assertNull($live->approved_by, "Alice's signature must not carry over to a live dispatch.");
        $this->assertNull($live->approved_at);
        $this->assertNull($live->second_approved_by, "Bob's signature must not carry over either.");
        $this->assertNull($live->second_approved_at);
        $this->assertSame('draft', $live->status);

        $this->assertFalse(
            app(AlertService::class)->isDispatchable($live, 2),
            'Two signatures given for a drill must not authorise a live dispatch the instant it goes live.',
        );

        $this->assertThrows(
            fn () => app(AlertService::class)->release($live, $carol->id),
            InvalidArgumentException::class,
        );

        // The withdrawal is on the record, not silently vanished.
        $entry = \App\Models\Bcms\AuditLog::query()
            ->where('auditable_type', Alert::class)
            ->where('auditable_id', $live->getKey())
            ->where('event', 'alert.exercise_escalated_live')
            ->latest('id')->first();
        $this->assertNotNull($entry, 'The escalation must be on the audit record.');
        $this->assertTrue($entry->after['approvals_withdrawn']);
    }

    /**
     * GATE 2 DEFECT 4, PERMANENT REGRESSION TEST. `release()` used to read
     * `recipient_count`, which is written only by the optional, manually
     * triggered `estimate()` button — skip it and the column is `0`, so a
     * huge advisory could dispatch with no second authoriser at all because
     * the threshold check saw zero recipients.
     *
     * One-line change that would make this fail: `release()` reading
     * `$alert->recipient_count` instead of `$contacts->count()` for the
     * dual-approval check.
     */
    #[Test]
    public function a_high_volume_advisory_released_without_ever_calling_estimate_is_refused(): void
    {
        $this->contacts(501);

        $alert = $this->alert(AlertSeverity::Advisory, ['email'])->refresh();
        $this->assertSame(0, $alert->recipient_count, '`estimate()` was never called: the column is still zero.');

        $this->assertThrows(
            fn () => app(AlertService::class)->release($alert, $this->operator->id),
            InvalidArgumentException::class,
        );

        $this->assertSame('draft', $alert->refresh()->status, 'A refused release must not move the alert forward.');
        $this->assertSame(0, AlertRecipient::query()->where('alert_id', $alert->getKey())->count());
    }

    /**
     * GATE 2's SEVENTH DEFECT, PERMANENT REGRESSION TEST — the deadlock
     * introduced fixing defect 3 and fixed again. The old `approve()` guard
     * read `! requiresDualApproval($alert)`, which itself read the unreliable
     * stored `recipient_count` column: a live, below-severity, unestimated
     * 9,000-person advisory read as "does not require approval" to `approve()`
     * (so it refused to record a signature — "there is nothing to approve")
     * while `release()`, which always resolves the real audience, refused to
     * dispatch it for the opposite reason ("approval required"). Neither
     * endpoint could move the alert.
     *
     * `approve()` and `release()` must never disagree about whether the same
     * alert needs a second signature. This is that invariant, exercised at
     * the exact volume and severity that broke it.
     *
     * One-line change that would make this fail: reintroducing
     * `! $this->requiresDualApproval($alert)` (no count argument) as
     * `approve()`'s guard.
     */
    #[Test]
    public function approve_and_release_never_disagree_about_a_high_volume_unestimated_advisory(): void
    {
        $this->contacts(501);

        $alert = $this->alert(AlertSeverity::Advisory, ['email'])->refresh();
        $this->assertSame(0, $alert->recipient_count, '`estimate()` was never called.');

        $service = app(AlertService::class);

        // Reading the stored (zero) count, this alert looks like it needs no
        // approval — the exact reading the old buggy `approve()` guard used.
        $this->assertFalse(
            $service->requiresDualApproval($alert),
            'Sanity check: the STORED count under-reports the real audience, same as the live incident.',
        );

        // Both signatures must be collectable — `approve()` no longer refuses
        // on the stale reading.
        $second = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Second Authoriser',
            'email' => 'second-deadlock@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);

        $service->approve($alert, $this->operator);
        $service->approve($alert->refresh(), $second);

        $this->assertNotNull($alert->refresh()->approved_by);
        $this->assertNotNull($alert->refresh()->second_approved_by);

        // And now release() — which resolves the real, 501-strong audience —
        // must agree the two signatures already collected are sufficient,
        // rather than demand a third that could never be given.
        $captured = [];
        $this->captureChannel(ChannelKey::Email, $captured);

        $contacts = $service->release($alert->refresh(), $second->id);

        $this->assertSame(501, $contacts->count());
        $this->assertSame('dispatching', $alert->refresh()->status);
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

            $this->signedReplyPost([
                'from' => '+2348000000'.$i,
                'body' => $body.' '.$token,
                'channel' => $channel,
            ], 'termii')->assertOk()->assertJson(['matched' => true, 'response' => RollCallService::SAFE]);
        }

        $this->assertSame(3, app(RollCallService::class)->summary($alert->refresh())['safe']);

        // An unmatched reply is a 200 with matched:false — a gateway that gets
        // an error retries, and a retried unmatched reply is a loop that costs
        // money.
        $this->signedReplyPost(['body' => 'who is this'], 'termii')
            ->assertOk()->assertJson(['matched' => false]);
    }

    /* ================================================================== */
    /*  Gate 1 defect — an unauthenticated, cross-tenant safety-status spoof.
    /*
    /*  `reply()` had no signature check at all: a bare public POST with a
    /*  guessed or known phone number could mark ANY tenant's open recipient
    /*  safe, with no token, no credential and no tenant boundary. Fixed by
    /*  making `reply()` fail closed on an unconfigured or absent signature —
    /*  the opposite default to `status()`, and deliberately so: see
    /*  `AlertWebhookController`'s docblock for the asymmetry.
    /* ================================================================== */

    #[Test]
    public function an_unsigned_reply_is_refused_even_with_a_valid_token(): void
    {
        // This is the product's actual state today: no channel is live, so no
        // provider secret is configured for anyone. Before this fix, that
        // state made `reply()` accept ANY caller. It must now refuse.
        $this->contacts(1);
        $alert = $this->alert(AlertSeverity::LifeSafety, ['sms']);
        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);

        $recipient = AlertRecipient::query()->where('alert_id', $alert->getKey())->firstOrFail();
        $token = app(AlertDispatcher::class)->tokenFor($recipient);

        $this->postJson(route('bcms.alerts.reply', ['provider' => 'termii']), [
            'from' => $recipient->contact->mobile_primary,
            'body' => 'SAFE '.$token,
        ])->assertStatus(403);

        $this->assertNull(
            $recipient->refresh()->acknowledged_at,
            'An unsigned callback must never be able to change a roll-call status.'
        );

        // A secret IS now configured for the provider, but the caller still
        // presents no signature header at all. A provider that has a secret
        // and simply forgets to sign one callback is not a reason to accept
        // it — the same rule `status()` already enforces once a secret exists.
        config()->set('bcms-gateways.webhook_secrets.termii', 'a-real-secret');

        $this->postJson(route('bcms.alerts.reply', ['provider' => 'termii']), [
            'from' => $recipient->contact->mobile_primary,
            'body' => 'SAFE '.$token,
        ])->assertStatus(403);

        $this->assertNull($recipient->refresh()->acknowledged_at);
    }

    #[Test]
    public function a_signed_reply_with_a_valid_token_still_works(): void
    {
        $this->contacts(1);
        $alert = $this->alert(AlertSeverity::LifeSafety, ['sms']);
        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);

        $recipient = AlertRecipient::query()->where('alert_id', $alert->getKey())->firstOrFail();
        $token = app(AlertDispatcher::class)->tokenFor($recipient);

        $this->signedReplyPost([
            'from' => $recipient->contact->mobile_primary,
            'body' => 'SAFE '.$token,
        ], 'termii')->assertOk()->assertJson(['matched' => true, 'response' => RollCallService::SAFE]);

        $this->assertNotNull($recipient->refresh()->acknowledged_at);
    }

    #[Test]
    public function the_number_only_fallback_cannot_reach_another_tenants_recipient(): void
    {
        // A second, unrelated tenant with an open recipient that happens to
        // share a mobile number with ours — the exact shape Gate 1 named:
        // "matches purely on the last 9 digits ... across every tenant in the
        // database". `OrganizationScope` cannot help here (it is inert until a
        // reply is matched, same as `CascadeAckController`), so the only thing
        // standing between this and a cross-tenant false SAFE is the existing
        // "exactly one match, or refuse" rule in `recipientForNumber` — this
        // is the first test that ever exercises it with two tenants in play.
        $sharedNumber = '+2347099999999';

        $other = Organization::create([
            'name' => 'Lagos Trust MFB', 'short_name' => 'LTM',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($other->id);

        $otherUnit = BusinessUnit::create([
            'organization_id' => $other->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);
        $otherOperator = User::create([
            'organization_id' => $other->id, 'name' => 'Other Crisis Manager',
            'email' => 'crisis@ltm.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $otherContactUser = User::create([
            'organization_id' => $other->id, 'name' => 'Shared Number Person',
            'email' => 'shared@ltm.test', 'password' => bcrypt('secret'), 'is_active' => false,
        ]);
        Contact::query()->create([
            'organization_id' => $other->id, 'user_id' => $otherContactUser->id,
            'source' => ContactSource::Manual->value, 'full_name' => 'Shared Number Person',
            'employee_id' => 'LTM-1', 'business_unit_id' => $otherUnit->id,
            'email' => 'sharedcontact@ltm.test', 'mobile_primary' => $sharedNumber,
            'preferred_language' => 'en', 'consent_status' => 'granted',
            'verification_status' => 'verified', 'last_verified_at' => now(), 'is_active' => true,
        ]);
        $otherAlert = app(AlertService::class)->compose([
            'organization_id' => $other->id, 'title' => 'Other bank alert', 'message' => 'Please respond.',
            'severity' => AlertSeverity::Advisory->value, 'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $otherUnit->id, 'include_descendants' => true],
        ], $otherOperator->id);
        app(AlertService::class)->release($otherAlert, $otherOperator->id);
        $otherRecipient = AlertRecipient::query()->where('alert_id', $otherAlert->getKey())->firstOrFail();

        TenantContext::set($this->organization->id);

        // Our own tenant's open recipient, deliberately given the SAME number.
        $mine = $this->contact('Colliding Number Person');
        $mine->forceFill(['mobile_primary' => $sharedNumber])->save();
        $alert = $this->alert(AlertSeverity::Advisory, ['sms']);
        app(AlertService::class)->release($alert, $this->operator->id);
        $mineRecipient = AlertRecipient::query()->where('alert_id', $alert->getKey())
            ->whereHas('contact', fn ($q) => $q->where('mobile_primary', $sharedNumber))
            ->firstOrFail();

        // Signed, so it passes the gate that stopped the unsigned case above —
        // this proves the collision is refused by the matching rule itself,
        // not merely by the signature check.
        $this->signedReplyPost(['from' => $sharedNumber, 'body' => 'safe'], 'termii')
            ->assertOk()->assertJson(['matched' => false]);

        $this->assertNull($otherRecipient->refresh()->acknowledged_at, "Another tenant's recipient must not move.");
        $this->assertNull($mineRecipient->refresh()->acknowledged_at, 'Nor may an ambiguous match settle our own.');
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

    /**
     * Gate 1 defect 3 — the regression test for "a digit keyword matched
     * inside the acknowledgement token" was PROBABILISTIC, not deterministic.
     *
     * It used a live `AlertDispatcher::tokenFor()`, which is an HMAC and
     * therefore roughly a coin flip on whether it contains a "3" — the exact
     * digit `not_on_site`'s menu code is. This test pins a 16-hex-character
     * token that DOES contain a "3", by construction, so it fails every time
     * the token-strip in `interpret()` is removed rather than about a third
     * of the time.
     *
     * Verified by temporarily reverting the `preg_replace` strip in
     * `InboundResponseHandler::interpret()`: this test failed on every run
     * (asserting NOT_ON_SITE instead of SAFE), where the old live-token test
     * would have passed roughly two times in three. Restored immediately
     * after confirming the failure.
     */
    #[Test]
    public function a_token_containing_a_three_is_stripped_before_keyword_matching(): void
    {
        $handler = app(InboundResponseHandler::class);

        // Sixteen lowercase hex characters, chosen to contain a "3" — exactly
        // the shape `AlertDispatcher::tokenFor()` produces, and exactly the
        // shape the original bug report used ("SAFE 8a3f…").
        $tokenWithThree = 'a1b2c3d4e5f60718';
        $this->assertStringContainsString('3', $tokenWithThree);

        // FREE-TEXT REPLIES. Verified by direct inspection AND by reverting
        // the strip: a free-text "SAFE <token>" is protected twice over —
        // `MENU_CODES` only ever matches the WHOLE normalised message
        // (`array_key_exists`, not `str_contains`), so a "3" buried inside a
        // 16-character token next to the word "safe" was never going to reach
        // it even with the strip removed. These two assertions hold with or
        // without the strip; they are pinned here as an invariant, not as the
        // regression proof — that is the keypad case below.
        $this->assertSame(RollCallService::SAFE, $handler->interpret('SAFE '.$tokenWithThree));
        $this->assertSame(RollCallService::SAFE, $handler->interpret($tokenWithThree.' I am safe'));

        // THE KEYPAD CASE IS THE ONE THE STRIP ACTUALLY GUARDS, and this is
        // where the previous, probabilistic test's real regression risk sits:
        // a USSD confirmation carries the keypad digit AND the token in one
        // body ("1 a1b2c3d4e5f60718"), and `MENU_CODES` needs the message
        // reduced to the bare digit to match at all. Verified by temporarily
        // removing the `preg_replace` strip in `interpret()`: this assertion
        // failed on every run — `SAFE` became `null` (the reply went
        // unmatched, not misfiled as `NOT_ON_SITE`, because the whole-message
        // exact match simply stopped matching anything) — where the old
        // live-token test would only have caught a break roughly a third of
        // the time, and never this failure mode at all. Restored immediately
        // after confirming the failure.
        $this->assertSame(
            RollCallService::SAFE,
            $handler->interpret('1 '.$tokenWithThree),
            'A USSD SAFE selection ("1") plus a token containing a 3 must still register as SAFE, '
            .'not go unmatched — a dropped acknowledgement is a person who reported in and was not heard.'
        );

        // The digit-3 keypad code itself must still mean NOT ON SITE, alone
        // or paired with a token, and a token containing a 3 must not corrupt
        // that either.
        $this->assertSame(RollCallService::NOT_ON_SITE, $handler->interpret('3'));
        $this->assertSame(RollCallService::NOT_ON_SITE, $handler->interpret('3 '.$tokenWithThree));
    }

    #[Test]
    public function a_delivery_receipt_never_moves_a_record_backwards(): void
    {
        $this->contacts(1);
        $alert = $this->alert(AlertSeverity::Urgent, ['sms']);
        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);
        $this->dispatchAll($alert);

        $delivery = NotificationDelivery::query()->where('alert_id', $alert->getKey())->first();
        // GATE 2 DEFECT 2 fixture: `handleStatusReceipt` now binds its query to
        // `(provider, provider_message_id)`, not the message id alone — see
        // `InboundResponseHandler`. `dispatchAll()` sends through the mock
        // adapter, whose provider is `mock-sms`, not `termii`; without setting
        // `provider` here to match the provider name the test calls
        // `handleStatusReceipt` with, the query would find no row and every
        // assertion below would be observing a delivery that never moved,
        // which would pass for the wrong reason.
        $delivery->forceFill(['provider' => 'termii', 'provider_message_id' => 'msg-1'])->save();

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

    /**
     * GATE 2 DEFECT 2, PERMANENT REGRESSION TEST — a `sim-` id under the wrong
     * provider must never match, and under the right provider must. Before
     * this gate the id alone was the whole query; combined with a global
     * auto-increment simulation id, that meant any provider name reaching the
     * status endpoint with a guessed `sim-N` could move it. Binding the query
     * to `(provider, provider_message_id)` closes that even for a correctly
     * guessed id.
     *
     * One-line change that would make this fail: dropping the
     * `->where('provider', $provider)` clause from `handleStatusReceipt()`.
     */
    #[Test]
    public function a_simulation_delivery_id_only_matches_under_its_own_provider(): void
    {
        $this->contacts(1);

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Simulation id binding test',
            'message' => 'Please respond.',
            'severity' => AlertSeverity::Advisory->value,
            'channels' => ['sms'],
            'is_simulation' => true,
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        app(AlertService::class)->release($alert, $this->operator->id);
        $this->dispatchAll($alert);

        $delivery = NotificationDelivery::query()->where('alert_id', $alert->getKey())->firstOrFail();
        $this->assertSame('simulation', $delivery->provider);
        $this->assertMatchesRegularExpression(
            '/^sim-[0-9a-f]{32}$/',
            $delivery->provider_message_id,
            '128 bits of `random_bytes`, not the auto-increment key — an enumerable id is the whole defect.',
        );

        $handler = app(InboundResponseHandler::class);

        // The exact id, but the wrong provider: no match at all.
        $result = $handler->handleStatusReceipt('termii', [
            'message_id' => $delivery->provider_message_id, 'status' => 'delivered',
        ]);
        $this->assertNull($result, 'The right id under the wrong provider must not resolve to any delivery.');
        $this->assertSame(DeliveryStatus::Sent, $delivery->refresh()->status, 'And must not have moved it.');

        // The same id, the right provider: matches and moves forward.
        $handler->handleStatusReceipt('simulation', [
            'message_id' => $delivery->provider_message_id, 'status' => 'delivered',
        ]);
        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status);
    }

    /**
     * GATE 2 DEFECT 1, PERMANENT REGRESSION TEST (fix 2 of 3) — a query string
     * on a request whose BODY carries a genuinely valid signature must still
     * be refused. `$request->validate()` validates `all()` (body ∪ query),
     * while the signature only ever covered the body — so `?from=<anything>`
     * riding alongside an honestly-signed `{"body":"safe"}` used to reach
     * `handleReply()` with an attacker-chosen `from`.
     *
     * One-line change that would make this fail: deleting the
     * `getQueryString()` check in `AlertWebhookController::signatureOk()`.
     */
    #[Test]
    public function a_query_string_on_a_validly_signed_reply_body_is_refused(): void
    {
        $this->contacts(1);
        $alert = $this->alert(AlertSeverity::LifeSafety, ['sms']);
        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);

        $victim = AlertRecipient::query()->where('alert_id', $alert->getKey())->firstOrFail();

        config()->set('bcms-gateways.webhook_secrets.termii', 'qs-secret');

        $payload = ['body' => 'received, thanks'];
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.json_encode($payload), 'qs-secret');

        // The query string is the attack: an attacker names ANY recipient's
        // number here, riding on a signature that only ever covered the body.
        $uri = route('bcms.alerts.reply', ['provider' => 'termii']).'?from='.urlencode((string) $victim->contact->mobile_primary);

        $this->postJson($uri, $payload, [
            'X-BCMS-Signature' => $signature,
            'X-BCMS-Timestamp' => $timestamp,
        ])->assertStatus(403);

        $this->assertNull($victim->refresh()->acknowledged_at, 'The query string must never reach the handler at all.');
    }

    /**
     * GATE 2 DEFECT 1, PERMANENT REGRESSION TEST (fix 3 of 3) — the replay
     * window. Nothing in the signed material used to expire, so a captured
     * request stayed valid forever; a signature computed honestly over a
     * stale timestamp is now refused.
     *
     * One-line change that would make this fail: widening or deleting
     * `SIGNATURE_WINDOW_SECONDS` in `AlertWebhookController`.
     */
    #[Test]
    public function a_signature_over_an_hour_old_timestamp_is_refused(): void
    {
        $this->contacts(1);
        $alert = $this->alert(AlertSeverity::LifeSafety, ['sms']);
        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);

        $recipient = AlertRecipient::query()->where('alert_id', $alert->getKey())->firstOrFail();
        $token = app(AlertDispatcher::class)->tokenFor($recipient);

        config()->set('bcms-gateways.webhook_secrets.termii', 'replay-secret');

        $payload = ['body' => 'SAFE '.$token];
        $staleTimestamp = (string) (time() - 3600);
        $signature = hash_hmac('sha256', $staleTimestamp.'.'.json_encode($payload), 'replay-secret');

        $this->postJson(route('bcms.alerts.reply', ['provider' => 'termii']), $payload, [
            'X-BCMS-Signature' => $signature,
            'X-BCMS-Timestamp' => $staleTimestamp,
        ])->assertStatus(403);

        $this->assertNull($recipient->refresh()->acknowledged_at, 'A replayed hour-old capture must never settle a roll-call status.');

        // Sanity: the same construction, freshly timestamped, works — proving
        // the previous 403 was the window and nothing else about the request.
        $freshTimestamp = (string) time();
        $freshSignature = hash_hmac('sha256', $freshTimestamp.'.'.json_encode($payload), 'replay-secret');

        $this->postJson(route('bcms.alerts.reply', ['provider' => 'termii']), $payload, [
            'X-BCMS-Signature' => $freshSignature,
            'X-BCMS-Timestamp' => $freshTimestamp,
        ])->assertOk()->assertJson(['matched' => true]);
    }

    /**
     * GATE 2 DEFECT 1, PERMANENT REGRESSION TEST (fix 1 of 3) — `{provider}`
     * must name a real gateway before either trust model is even consulted.
     * An unrecognised segment used to fall through to "no secret configured",
     * which `status()` treats as an unsigned-but-acceptable callback —
     * letting a caller pick any string at all to land on the permissive
     * branch.
     *
     * One-line change that would make this fail: deleting the
     * `array_key_exists($provider, $secrets)` check in `signatureOk()`.
     */
    #[Test]
    public function an_unrecognised_provider_segment_is_refused_on_both_routes(): void
    {
        $this->postJson(route('bcms.alerts.provider-status', ['provider' => 'not-a-real-gateway']), [
            'message_id' => 'whatever', 'status' => 'delivered',
        ])->assertStatus(403);

        $this->postJson(route('bcms.alerts.reply', ['provider' => 'not-a-real-gateway']), [
            'body' => 'safe',
        ])->assertStatus(403);
    }

    /**
     * REWRITTEN, GATE 1, THIS CYCLE. ADR 0016 §4 / phase-7-inbound-token-
     * contract.md §4 AC 10 replaced the single shared
     * `webhook_rate_limit_per_minute` this test used to read (deleted) with
     * two independent keys, because gate 2 found that a SHARED ceiling was
     * itself the defect: the "equal buckets" invariant let the roll-call
     * route (a person's life-safety acknowledgement) inherit whatever number
     * the receipt route needed, and vice versa. The invariant that actually
     * matters, per the ADR, is not "the two numbers are equal" — it is that
     * **the life-safety route's ceiling is never below the receipt route's**,
     * because a 429 on the reply route is a dropped acknowledgement and a 429
     * on the receipt route is, at worst, a delayed evidence write. Asserting
     * numeric equality (the old assertion) would now PASS on the interim
     * values by coincidence (600 == 600) and PASS AGAIN if a future
     * deployment set the reply route's ceiling to 1 and the status route's to
     * 1 — the old test could not tell "safe" from "both wrong the same way".
     *
     * This test asserts three things AC 10 actually requires:
     *   1. Both keys exist, are configured, and are the documented interim
     *      value (600) — not the deleted key.
     *   2. `webhook_rate_limit_per_minute` (deleted) is not read by either
     *      limiter closure — proven by changing IT and observing NEITHER
     *      limit moves, which the old shared-config test could never show
     *      because it read that same deleted key itself.
     *   3. The invariant: alert-reply's ceiling is never lower than
     *      provider-status's, keyed independently per `{provider}+ip` so one
     *      provider's volume cannot exhaust another's bucket.
     */
    #[Test]
    public function the_life_safety_reply_routes_ceiling_is_never_below_the_receipt_routes(): void
    {
        $replyPerMinute = (int) config('bcms-gateways.alert_reply_rate_limit_per_minute');
        $statusPerMinute = (int) config('bcms-gateways.provider_status_rate_limit_per_minute');

        $this->assertSame(600, $replyPerMinute, 'ADR 0016 §3: the documented interim value.');
        $this->assertSame(600, $statusPerMinute, 'ADR 0016 §3: the documented interim value.');

        // The deleted key must be inert: setting it must move neither
        // limiter, proving neither closure still reads it.
        config()->set('bcms-gateways.webhook_rate_limit_per_minute', 1);

        $limiter = app(\Illuminate\Cache\RateLimiter::class);

        $replyRequest = \Illuminate\Http\Request::create('/bcms/alert-reply/termii', 'POST');
        $replyRequest->setRouteResolver(fn () => new class
        {
            public function parameter($name)
            {
                return 'termii';
            }
        });

        $statusRequest = \Illuminate\Http\Request::create('/bcms/provider-status/africastalking', 'POST');
        $statusRequest->setRouteResolver(fn () => new class
        {
            public function parameter($name)
            {
                return 'africastalking';
            }
        });

        $replyLimit = call_user_func($limiter->limiter('bcms-alert-reply'), $replyRequest);
        $statusLimit = call_user_func($limiter->limiter('bcms-provider-status'), $statusRequest);

        $this->assertNotSame(1, $replyLimit->maxAttempts, 'The reply limiter must not have read the deleted shared key.');
        $this->assertNotSame(1, $statusLimit->maxAttempts, 'The status limiter must not have read the deleted shared key.');

        // THE INVARIANT ITSELF: never "equal", always "not below".
        $this->assertGreaterThanOrEqual(
            $statusLimit->maxAttempts,
            $replyLimit->maxAttempts,
            "The life-safety reply route's ceiling must never be below the receipt route's."
        );

        // Keyed per-provider-plus-ip: a different provider gets a different
        // bucket, so one provider's volume cannot exhaust another's.
        $this->assertStringContainsString('termii', $replyLimit->key);
        $this->assertStringContainsString('africastalking', $statusLimit->key);
        $this->assertNotSame($replyLimit->key, $statusLimit->key);
    }

    /**
     * AC 10's other half: nothing in the codebase still READS the deleted
     * key as config — as opposed to naming it in a comment, which every file
     * that made this change legitimately does, to explain what replaced it.
     * A grep-based guard rather than a config assertion, because the
     * closures are registered once at boot and closing over a value read at
     * boot time would make a config-only check blind to a stray read
     * anywhere else in the module. Matched narrowly on the two shapes an
     * actual read or a live definition would take
     * (`config('bcms-gateways.webhook_rate_limit_per_minute')` or an array
     * key `'webhook_rate_limit_per_minute' =>`), not on the bare string,
     * which the docblocks above are entitled to contain.
     */
    #[Test]
    public function nothing_reads_the_deleted_shared_rate_limit_key(): void
    {
        $hits = [];
        $pattern = '/(config\(\s*[\'"]bcms-gateways\.webhook_rate_limit_per_minute[\'"]|'
            .'[\'"]webhook_rate_limit_per_minute[\'"]\s*=>)/';

        foreach (['app', 'routes', 'config'] as $dir) {
            $path = base_path($dir);
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                if ($contents !== false && preg_match($pattern, $contents) === 1) {
                    $hits[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $hits, 'The deleted config key must not be read or defined anywhere: '.implode(', ', $hits));
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

    /**
     * GATE 2 DEFECT 7, PERMANENT REGRESSION TEST — the export used to sit
     * behind `bcms.report.export` alone and hand out `Address` and
     * `Response text` unconditionally. `Response text` predictably collects
     * health data and named third parties, because the inbound parser's own
     * help vocabulary is `injured`, `hurt`, `trapped`. This is the mechanics
     * `EvidenceExport` itself owns; the permission split at the route is
     * `Phase7ScreensTest`.
     *
     * One-line change that would make this fail: `filterRow()` returning
     * `$row` unchanged regardless of `$includeContactData`.
     */
    #[Test]
    public function the_redacted_pack_omits_address_and_response_text_and_says_so_on_its_face(): void
    {
        $this->contacts(1);
        $alert = $this->alert(AlertSeverity::Urgent, ['sms']);
        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);
        $this->dispatchAll($alert);

        $recipient = AlertRecipient::query()->where('alert_id', $alert->getKey())->firstOrFail();
        app(RollCallService::class)->record(
            $recipient, RollCallService::NEEDS_HELP,
            'Musa is trapped on the third floor, send help', 'sms',
        );

        $export = app(EvidenceExport::class);

        $fullColumns = $export->columns(true);
        $redactedColumns = $export->columns(false);
        $this->assertContains('Address', $fullColumns);
        $this->assertContains('Response text', $fullColumns);
        $this->assertNotContains('Address', $redactedColumns, 'Column and value must be dropped together.');
        $this->assertNotContains('Response text', $redactedColumns);

        $fullRows = implode('|', array_map(fn (array $r) => implode('|', $r), $export->rows($alert->refresh(), true)));
        $redactedRows = implode('|', array_map(fn (array $r) => implode('|', $r), $export->rows($alert->refresh(), false)));

        $this->assertStringContainsString((string) $recipient->contact->mobile_primary, $fullRows);
        $this->assertStringContainsString('trapped on the third floor', $fullRows);
        $this->assertStringNotContainsString((string) $recipient->contact->mobile_primary, $redactedRows);
        $this->assertStringNotContainsString('trapped on the third floor', $redactedRows);

        // The withholding is declared on the pack's own face, not silent.
        $fullPreamble = $export->preamble($alert, true);
        $redactedPreamble = $export->preamble($alert, false);
        $this->assertFalse(
            collect($fullPreamble)->contains(fn (array $line) => ($line[0] ?? null) === 'REDACTED'),
        );
        $this->assertTrue(
            collect($redactedPreamble)->contains(fn (array $line) => ($line[0] ?? null) === 'REDACTED'),
        );

        // THE NO-ARGUMENT DEFAULT IS THE FULL SHAPE. The permission gate is the
        // controller's job (`Phase7ScreensTest`); a defensive narrow default
        // here would just be a second, undocumented place the same decision is
        // made, and the two could drift.
        $this->assertSame($fullColumns, $export->columns());
        $this->assertSame($export->rows($alert->refresh(), true), $export->rows($alert->refresh()));
        $this->assertSame($fullPreamble, $export->preamble($alert));

        // Distinct filenames: an examiner holding both must be able to tell
        // them apart without opening either.
        $this->assertNotSame($export->filename($alert, true), $export->filename($alert, false));
        $this->assertStringContainsString('redacted', $export->filename($alert, false));
        $this->assertStringNotContainsString('redacted', $export->filename($alert, true));
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
    /*  Regression — tenancy on inline job invocation.
    /*
    /*  `DispatchAlertChunkJob` and `EscalateAlertRecipientsJob` both used to
    /*  clear `TenantContext` in a `finally`, which is correct for a real
    /*  queue worker (it always starts untenanted) but silently untenants
    /*  everything after the job when a seeder or a test calls `->handle()`
    /*  directly — the family the Phase 4 ICS feed and the Phase 6 cascade
    /*  acknowledgement route already belong to: `OrganizationScope` is
    /*  INERT with no tenant resolved (see
    /*  `TenancyIsolationTest::the_scope_is_inert_when_no_tenant_is_resolved`,
    /*  which proves it returns EVERY organisation's rows unfiltered, not
    /*  nothing). Both jobs now use `TenantContext::actingAs()` to restore the
    /*  caller's tenant instead of clearing it, matching
    /*  `App\Services\Tprm\Reporting\ScheduledReportDispatcher`.
    /* ================================================================== */

    #[Test]
    public function the_dispatch_chunk_job_leaves_no_tenant_resolved_when_none_was_active_before_it_ran(): void
    {
        $this->contacts(3);
        $alert = $this->alert(AlertSeverity::Urgent, ['sms']);
        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);
        $ids = AlertRecipient::query()->where('alert_id', $alert->getKey())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        // The true queue-worker starting state: nothing resolved. This is the
        // scenario `TenantContext`'s own docblock names as legitimate for
        // "console and system contexts".
        TenantContext::clear();

        (new DispatchAlertChunkJob((int) $alert->getKey(), (int) $this->organization->id, $ids))
            ->handle(app(AlertDispatcher::class));

        $this->assertNull(
            TenantContext::organizationIdOrNull(),
            'actingAs(previous: null) restores to null, same as the old clear() — a worker\'s baseline.',
        );

        // The property that matters is never "another tenant's rows". A bare
        // `AlertRecipient::query()->get()` would not prove that here —
        // OrganizationScope is INERT with nothing resolved, so it adds no
        // filter at all and would show every organisation's recipients
        // unfiltered if more than one existed. What is asserted instead is
        // the realistic downstream shape: code (reports, presenters, a raw
        // query) that explicitly asks for "the current tenant's rows"
        // without re-establishing one first. `where('organization_id', null)`
        // becomes `whereNull`, which matches nothing — every recipient row is
        // stamped with an organization_id on creation — so this fails closed
        // rather than silently answering with whichever tenant last ran.
        $rows = AlertRecipient::query()
            ->where('organization_id', TenantContext::organizationIdOrNull())
            ->get();

        $this->assertCount(0, $rows, 'Fails closed: nothing, not another tenant\'s rows.');
    }

    #[Test]
    public function the_dispatch_chunk_job_restores_the_callers_tenant_rather_than_leaking_another_ones_rows(): void
    {
        // A second, genuinely different, tenant with its own alert and
        // recipient — the data a `clear()`-then-inert-scope defect would be
        // able to leak into, and the data `actingAs()` must never show.
        $other = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($other->id);
        $otherUnit = BusinessUnit::create([
            'organization_id' => $other->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);
        $otherOperator = User::create([
            'organization_id' => $other->id, 'name' => 'Other Ops',
            'email' => 'ops@ob.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        Contact::query()->create([
            'organization_id' => $other->id, 'source' => ContactSource::Manual->value,
            'full_name' => 'Their Officer', 'employee_id' => 'OB-1', 'business_unit_id' => $otherUnit->id,
            'email' => 'officer@ob.test', 'mobile_primary' => '+2348009990000',
            'preferred_language' => 'en', 'consent_status' => 'granted', 'verification_status' => 'verified',
            'last_verified_at' => now(), 'is_active' => true,
        ]);
        $otherAlert = app(AlertService::class)->compose([
            'organization_id' => $other->id, 'title' => 'Theirs', 'message' => 'x',
            'severity' => AlertSeverity::Urgent->value, 'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $otherUnit->id, 'include_descendants' => true],
        ], $otherOperator->id);
        app(AlertService::class)->release($otherAlert, $otherOperator->id);

        // Back to my own tenant, with my own alert to dispatch.
        TenantContext::set($this->organization->id);
        $this->contacts(2);
        $alert = $this->alert(AlertSeverity::Urgent, ['sms']);
        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);
        $ids = AlertRecipient::query()->where('alert_id', $alert->getKey())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Run the job the way a seeder or a `sync`-driver caller would:
        // directly, with a tenant already active — and, the point of this
        // test, WITHOUT re-establishing it afterward, unlike the old
        // `dispatchAll()` helper's masking re-set.
        (new DispatchAlertChunkJob((int) $alert->getKey(), (int) $this->organization->id, $ids))
            ->handle(app(AlertDispatcher::class));

        $this->assertSame(
            $this->organization->id,
            TenantContext::organizationIdOrNull(),
            'actingAs() restores the caller\'s own tenant; it must not still be the job\'s.',
        );

        // Never another tenant's rows, whatever the design: a plain read
        // right after the job, with nothing re-established, shows only mine.
        $visible = AlertRecipient::query()->pluck('organization_id')->unique()->values()->all();
        $this->assertSame([$this->organization->id], $visible);
    }

    #[Test]
    public function the_escalation_job_leaves_no_tenant_resolved_when_none_was_active_before_it_ran(): void
    {
        $manager = $this->contact('Line Manager');
        $this->contact('Amina', managerUserId: $manager->user_id);

        $alert = $this->alert(AlertSeverity::Urgent, ['sms']);
        $alert->forceFill(['ack_window_minutes' => 5])->save();
        app(AlertService::class)->release($this->cleared($alert), $this->operator->id);
        $this->dispatchAll($alert);

        $this->travel(10)->minutes();
        TenantContext::clear();

        (new EscalateAlertRecipientsJob((int) $alert->getKey(), (int) $this->organization->id))
            ->handle(app(EscalationService::class));

        $this->travelBack();

        $this->assertNull(TenantContext::organizationIdOrNull());

        $rows = AlertRecipient::query()
            ->where('organization_id', TenantContext::organizationIdOrNull())
            ->get();

        $this->assertCount(0, $rows, 'Fails closed: nothing, not another tenant\'s rows.');
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

    /** An exercise-linked alert — defaults to simulation, per criterion 5. */
    private function alertOn(int $occurrenceId, AlertSeverity $severity, array $channels): Alert
    {
        return app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Test exercise alert',
            'message' => 'Please respond.',
            'severity' => $severity->value,
            'channels' => $channels,
            'occurrence_id' => $occurrenceId,
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

    /**
     * Post to `reply()` the way a genuinely signed provider callback would —
     * configuring a secret for `$provider` and presenting the same HMAC the
     * controller computes, over the same JSON body `postJson` sends. Every
     * test that expects `reply()` to succeed must go through this; only the
     * tests for defect 1 itself post unsigned.
     *
     * @param  array<string, mixed>  $payload
     */
    private function signedReplyPost(array $payload, string $provider, string $secret = 'test-provider-secret')
    {
        config()->set('bcms-gateways.webhook_secrets.'.$provider, $secret);

        // The signed material is `timestamp.body`, not the body alone —
        // `AlertWebhookController::signatureOk()` binds a timestamp into the
        // HMAC and rejects a signature computed without one. `json_encode`
        // here must match byte-for-byte what `postJson` sends as the request
        // body, since the controller signs `$request->getContent()` verbatim.
        $timestamp = (string) time();
        $body = json_encode($payload);
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return $this->postJson(
            route('bcms.alerts.reply', ['provider' => $provider]),
            $payload,
            [
                'X-BCMS-Signature' => $signature,
                'X-BCMS-Timestamp' => $timestamp,
            ],
        );
    }

    private function message(): RenderedMessage
    {
        return new RenderedMessage(body: 'Evacuate now.', subject: 'Evacuate');
    }

    /**
     * Run every queued chunk inline.
     *
     * No trailing `TenantContext::set()` here any more. That re-set used to
     * mask the job's `finally`-clear — see
     * `running_the_dispatch_chunk_job_inline_restores_the_callers_tenant()`
     * below for why it is gone: the job now restores the tenant that was
     * active before it ran (`TenantContext::actingAs()`), which is this
     * test's own organisation throughout, so nothing needs repairing after
     * the loop.
     */
    private function dispatchAll(Alert $alert): void
    {
        $ids = AlertRecipient::query()->where('alert_id', $alert->getKey())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach (array_chunk($ids, 200) as $chunk) {
            (new DispatchAlertChunkJob((int) $alert->getKey(), (int) $alert->organization_id, $chunk))
                ->handle(app(AlertDispatcher::class));
        }
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

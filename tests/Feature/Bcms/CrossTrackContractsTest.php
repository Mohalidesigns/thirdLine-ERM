<?php

namespace Tests\Feature\Bcms;

use App\Contracts\Bcms\DeliveryReceipt;
use App\Contracts\Bcms\NotificationChannel;
use App\Contracts\Bcms\Recipient;
use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\DeliveryStatus;
use App\Models\Bcms\AuditLog;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\CallTreeNode;
use App\Models\Bcms\Contact;
use App\Models\Bcms\SavedGroup;
use App\Models\Bcms\Site;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\AudienceResolver;
use App\Services\Bcms\ContactResolver;
use App\Services\Bcms\Notification\ChannelRegistry;
use App\Support\Bcms\AudienceRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The cross-track contracts of Orchestration §5, frozen at G0.
 *
 * These are the only surfaces Tracks B, C, D and E share, and they are frozen
 * BEFORE those tracks start because a contract that moves in week six moves
 * under four branches at once. Each assertion below is a property some other
 * track is entitled to rely on for the next fourteen weeks; changing one is an
 * architect ADR and a broadcast, not a commit.
 */
class CrossTrackContractsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  NotificationChannel — the interface and its Phase 0 mocks */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_channel_has_a_registered_adapter(): void
    {
        $registry = app(ChannelRegistry::class);

        foreach (ChannelKey::cases() as $channel) {
            $adapter = $registry->for($channel);

            $this->assertInstanceOf(NotificationChannel::class, $adapter);
            $this->assertSame($channel, $adapter->key());
        }

        // Eight, including USSD, which is a Phase 12 capability. It is in the
        // frozen set because adding a channel later would be a structural
        // change to `channel_set` JSON already written into customers'
        // reminder schedules.
        $this->assertCount(8, $registry->all());
    }

    #[Test]
    public function every_phase_zero_adapter_reports_itself_as_a_mock(): void
    {
        $registry = app(ChannelRegistry::class);

        foreach (ChannelKey::cases() as $channel) {
            $this->assertTrue(
                $registry->isMock($channel),
                "{$channel->value} claims to be a real adapter in Phase 0."
            );
        }
    }

    #[Test]
    public function a_channel_returns_a_failed_receipt_rather_than_throwing(): void
    {
        // Rule 2 of ADR 0004. An exception means the adapter is broken;
        // a provider failure is a receipt. Failover is the caller's decision,
        // made by reading receipts.
        $channel = app(ChannelRegistry::class)->for(ChannelKey::Sms);

        $recipient = new Recipient(contactId: 1, name: 'Amina Bello', email: 'a@khb.test');
        $receipt = $channel->send($recipient, new RenderedMessage(body: 'Test'));

        $this->assertSame(DeliveryStatus::Failed, $receipt->status);
        $this->assertNotNull($receipt->failedReason);
    }

    #[Test]
    public function a_channel_prices_a_send_as_null_rather_than_zero_when_it_cannot(): void
    {
        // Zero is a claim that a send was free. A cost report that sums nulls
        // as zero understates a crisis dispatch by however many providers did
        // not report.
        $channel = app(ChannelRegistry::class)->for(ChannelKey::Sms);
        $recipient = new Recipient(contactId: 1, name: 'Amina Bello', mobilePrimary: '+2348000000001');

        $this->assertNull($channel->estimateCostMinor($recipient, new RenderedMessage(body: 'Test')));
    }

    #[Test]
    public function a_simulation_message_carries_the_exercise_prefix_and_never_stacks_it(): void
    {
        // Standing rule 5. The prefix is applied by the message, not by whoever
        // composes one: a rule every caller has to remember is a rule that will
        // be forgotten once, at the worst possible moment.
        $message = new RenderedMessage(body: 'Evacuate the building.', subject: 'Evacuate', isSimulation: true);

        $this->assertStringStartsWith(RenderedMessage::EXERCISE_PREFIX, $message->wireBody());
        $this->assertStringStartsWith(RenderedMessage::EXERCISE_PREFIX, $message->wireSubject());

        $already = new RenderedMessage(
            body: RenderedMessage::EXERCISE_PREFIX.'Evacuate the building.',
            isSimulation: true,
        );

        $this->assertSame(1, substr_count($already->wireBody(), RenderedMessage::EXERCISE_PREFIX));

        $live = new RenderedMessage(body: 'Evacuate the building.', isSimulation: false);
        $this->assertSame('Evacuate the building.', $live->wireBody());
    }

    #[Test]
    public function a_delivery_receipt_maps_straight_onto_the_delivery_columns(): void
    {
        // The field set IS the column set, so persisting a receipt is a copy
        // and not a translation. A translation layer is where a provider's
        // failure reason quietly stops being recorded.
        $receipt = DeliveryReceipt::sent('termii', 'msg-123', 450, 'NGN', ['raw' => true]);

        $attributes = $receipt->toDeliveryAttributes();

        foreach (['status', 'provider', 'provider_message_id', 'failed_reason', 'cost_minor', 'currency', 'raw_response', 'sent_at', 'delivered_at'] as $column) {
            $this->assertArrayHasKey($column, $attributes);
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('bcms_notification_deliveries', $column),
                "DeliveryReceipt produces '{$column}', which is not a column on bcms_notification_deliveries."
            );
        }
    }

    #[Test]
    public function life_safety_traffic_is_routed_to_its_own_queue(): void
    {
        // Standing rule 6. Not a priority level on a shared pool — a separate
        // queue, because a "high priority" job on a shared queue is still
        // behind whatever the worker is doing right now.
        $this->assertSame('bcms-lifesafety', AlertSeverity::LifeSafety->queue());

        foreach ([AlertSeverity::Informational, AlertSeverity::Advisory, AlertSeverity::Urgent, AlertSeverity::Critical] as $severity) {
            $this->assertSame('bcms-alerts', $severity->queue());
        }
    }

    /* ------------------------------------------------------------------ */
    /*  AudienceRule — the grammar */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_invalid_rule_is_refused_at_construction(): void
    {
        // The grammar is stored as JSON in customer data, on every reminder
        // schedule and every alert. An unvalidated rule is a row that resolves
        // to nobody in six months and nobody can say why.
        $this->expectException(InvalidArgumentException::class);

        AudienceRule::fromArray(['type' => 'not_a_real_type']);
    }

    #[Test]
    public function a_leaf_missing_its_required_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AudienceRule::fromArray(['type' => 'org_node']);
    }

    #[Test]
    public function the_fingerprint_is_stable_under_key_order(): void
    {
        // The reminder idempotency key is built from this hash (ADR 0005). If
        // key order changed it, regenerating an identical ladder would produce
        // different keys and send everything twice — exactly what Gate G1
        // tests for.
        $a = AudienceRule::fromArray(['type' => 'org_node', 'id' => 4, 'include_descendants' => true]);
        $b = AudienceRule::fromArray(['include_descendants' => true, 'id' => 4, 'type' => 'org_node']);

        $this->assertSame($a->fingerprint(), $b->fingerprint());

        $c = AudienceRule::fromArray(['type' => 'org_node', 'id' => 5, 'include_descendants' => true]);
        $this->assertNotSame($a->fingerprint(), $c->fingerprint());
    }

    #[Test]
    public function a_null_rule_and_an_empty_rule_are_different_things(): void
    {
        // Null means nobody chose an audience, which is a draft. An empty
        // `any_of` means somebody chose an audience matching nobody, which is a
        // mistake worth showing them. Both resolve to nobody and only one
        // should be dispatchable.
        $this->assertNull(AudienceRule::fromJson(null));
        $this->assertNotNull(AudienceRule::anyOf([]));
    }

    /* ------------------------------------------------------------------ */
    /*  AudienceResolver */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_rule_resolves_to_contacts_and_never_to_users(): void
    {
        $unit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);

        $reachable = Contact::query()->create([
            'full_name' => 'Amina Bello', 'employee_id' => 'KHB-1',
            'business_unit_id' => $unit->id, 'mobile_primary' => '+2348000000001', 'is_active' => true,
        ]);

        $elsewhere = Contact::query()->create([
            'full_name' => 'Chidi Okonkwo', 'employee_id' => 'KHB-2', 'is_active' => true,
        ]);

        $resolved = app(AudienceResolver::class)->resolve(
            AudienceRule::make('org_node', ['id' => $unit->id, 'include_descendants' => true])
        );

        $this->assertTrue($resolved->has($reachable->id));
        $this->assertFalse($resolved->has($elsewhere->id));
        $this->assertInstanceOf(Contact::class, $resolved->first());
    }

    #[Test]
    public function none_of_removes_and_never_adds(): void
    {
        $unit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);

        $site = Site::factory()->create();

        $both = Contact::query()->create([
            'full_name' => 'Both', 'employee_id' => 'KHB-1',
            'business_unit_id' => $unit->id, 'site_id' => $site->id, 'is_active' => true,
        ]);
        $unitOnly = Contact::query()->create([
            'full_name' => 'Unit only', 'employee_id' => 'KHB-2',
            'business_unit_id' => $unit->id, 'is_active' => true,
        ]);

        $rule = AudienceRule::anyOf([
            AudienceRule::make('org_node', ['id' => $unit->id]),
            AudienceRule::noneOf([AudienceRule::make('site', ['ids' => [$site->id]])]),
        ]);

        $resolved = app(AudienceResolver::class)->resolve($rule);

        $this->assertTrue($resolved->has($unitOnly->id));
        $this->assertFalse($resolved->has($both->id), 'A none_of branch did not exclude.');
    }

    #[Test]
    public function an_all_of_containing_only_exclusions_resolves_to_nobody(): void
    {
        $site = Site::factory()->create();

        Contact::query()->create([
            'full_name' => 'Someone', 'employee_id' => 'KHB-1', 'site_id' => $site->id, 'is_active' => true,
        ]);

        // Fail closed. The alternative reading — "everybody except" — is how a
        // targeting mistake becomes a message to the whole bank.
        $rule = AudienceRule::allOf([
            AudienceRule::noneOf([AudienceRule::make('site', ['ids' => [$site->id]])]),
        ]);

        $this->assertSame(0, app(AudienceResolver::class)->count($rule));
    }

    #[Test]
    public function a_call_tree_rule_resolves_through_its_nodes(): void
    {
        $contact = Contact::query()->create([
            'full_name' => 'Tier one', 'employee_id' => 'KHB-1', 'is_active' => true,
        ]);

        $tree = CallTree::factory()->create();

        CallTreeNode::query()->create([
            'call_tree_id' => $tree->id, 'tier' => 1, 'contact_id' => $contact->id,
        ]);

        $resolved = app(AudienceResolver::class)->resolve(
            AudienceRule::make('call_tree', ['id' => $tree->id, 'tiers' => [1]])
        );

        $this->assertTrue($resolved->has($contact->id));

        // A tier nobody is on resolves to nobody, not to the whole tree.
        $this->assertSame(0, app(AudienceResolver::class)->count(
            AudienceRule::make('call_tree', ['id' => $tree->id, 'tiers' => [4]])
        ));
    }

    #[Test]
    public function a_saved_group_resolves_from_its_members_or_from_its_rule(): void
    {
        $member = Contact::query()->create([
            'full_name' => 'Crisis team member', 'employee_id' => 'KHB-1', 'is_active' => true,
        ]);

        $static = SavedGroup::factory()->create(['is_dynamic' => false]);
        $static->members()->attach($member->id, ['organization_id' => $this->organization->id]);

        $this->assertTrue(
            app(AudienceResolver::class)->resolve(AudienceRule::make('saved_group', ['id' => $static->id]))->has($member->id)
        );
    }

    /* ------------------------------------------------------------------ */
    /*  ContactResolver — consent and channel resolution */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function withdrawn_consent_removes_personal_channels_and_leaves_corporate_ones(): void
    {
        $contact = Contact::query()->create([
            'full_name' => 'Amina Bello', 'employee_id' => 'KHB-1',
            'email' => 'amina@khb.test', 'mobile_primary' => '+2348000000001',
            'consent_status' => 'withdrawn', 'is_active' => true,
        ]);

        $resolver = app(ContactResolver::class);

        // A corporate email is a work channel for a work purpose and needs no
        // consent. The withdrawal bites on the personal mobile and only there.
        $this->assertTrue($resolver->canReach($contact, ChannelKey::Email));
        $this->assertFalse($resolver->canReach($contact, ChannelKey::Sms));
    }

    #[Test]
    public function life_safety_traffic_reaches_a_withdrawn_contact_on_a_personal_channel(): void
    {
        $contact = Contact::query()->create([
            'full_name' => 'Amina Bello', 'employee_id' => 'KHB-1',
            'mobile_primary' => '+2348000000001', 'consent_status' => 'withdrawn', 'is_active' => true,
        ]);

        // NDPA vital interests. The exception is narrow, deliberate, recorded
        // on the delivery row, and written down in the NDPA register rather
        // than left as an engineer's judgement.
        $this->assertTrue(app(ContactResolver::class)->canReach($contact, ChannelKey::Sms, isLifeSafety: true));
    }

    #[Test]
    public function a_contact_with_no_address_for_a_channel_is_skipped_rather_than_failed(): void
    {
        $contact = Contact::query()->create([
            'full_name' => 'Email only', 'employee_id' => 'KHB-1',
            'email' => 'e@khb.test', 'is_active' => true,
        ]);

        // Recording a failure against a channel that was never attempted makes
        // the delivery audit a lie about what happened.
        $channels = app(ContactResolver::class)->channelsFor($contact, [ChannelKey::Sms, ChannelKey::Email]);

        $this->assertSame([ChannelKey::Email], $channels);
    }

    #[Test]
    public function a_contacts_own_channel_preference_orders_the_attempts(): void
    {
        $contact = Contact::query()->create([
            'full_name' => 'Prefers WhatsApp', 'employee_id' => 'KHB-1',
            'email' => 'e@khb.test', 'mobile_primary' => '+2348000000001', 'whatsapp' => '+2348000000001',
            'channel_preferences' => ['whatsapp', 'sms'], 'is_active' => true,
        ]);

        $channels = app(ContactResolver::class)->channelsFor(
            $contact,
            [ChannelKey::Email, ChannelKey::Sms, ChannelKey::WhatsApp]
        );

        $this->assertSame(ChannelKey::WhatsApp, $channels[0]);
        $this->assertContains(ChannelKey::Email, $channels);
    }

    /* ------------------------------------------------------------------ */
    /*  The audit trail */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_state_change_writes_a_before_and_after_row(): void
    {
        $site = Site::factory()->create(['name' => 'Kano Branch']);

        $this->assertDatabaseHas('bcms_audit_logs', [
            'auditable_type' => Site::class,
            'auditable_id' => $site->id,
            'event' => 'created',
        ]);

        $site->update(['name' => 'Kano Main Branch']);

        $update = AuditLog::query()
            ->where('auditable_id', $site->id)
            ->where('event', 'updated')
            ->firstOrFail();

        // Only what CHANGED, so a screen that re-saves forty untouched fields
        // writes one meaningful row rather than forty columns of noise.
        $this->assertSame('Kano Branch', $update->before['name']);
        $this->assertSame('Kano Main Branch', $update->after['name']);
        $this->assertArrayNotHasKey('updated_at', $update->after);
    }

    #[Test]
    public function a_secret_is_never_written_to_the_audit_log(): void
    {
        $contact = Contact::query()->create([
            'full_name' => 'Amina Bello', 'employee_id' => 'KHB-1',
            'push_token' => 'a-real-looking-device-token', 'is_active' => true,
        ]);

        $created = AuditLog::query()
            ->where('auditable_type', Contact::class)
            ->where('auditable_id', $contact->id)
            ->firstOrFail();

        // Recording a before/after of a credential puts it in the audit table
        // in plain text, which is worse than not auditing the change.
        $this->assertArrayNotHasKey('push_token', $created->after ?? []);
    }

    #[Test]
    public function a_user_has_no_channel_and_the_resolver_says_so(): void
    {
        // The contract in one assertion: BCMS never queries `users` for a
        // channel, and a user with no contact record is unreachable — which is
        // a data hygiene finding, not a silent omission.
        $user = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->assertNull(app(ContactResolver::class)->forUser($user));
    }
}

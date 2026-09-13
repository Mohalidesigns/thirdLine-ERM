<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\CascadeMode;
use App\Enums\Bcms\CascadeOutcome;
use App\Enums\Bcms\ContactSource;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\CallTreeNode;
use App\Models\Bcms\CallTreeTestNode;
use App\Models\Bcms\Contact;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\CallTrees\CallTreeService;
use App\Services\Bcms\CallTrees\CascadeEngine;
use App\Services\Bcms\Emns\AlertDispatcher;
use App\Services\Bcms\Emns\AlertService;
use App\Services\Bcms\Emns\InboundResponseHandler;
use App\Services\Bcms\Emns\RollCallService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * docs/bcms/phase-7-inbound-token-contract.md §4 — the ten acceptance
 * criteria for the re-gate, exercised directly against
 * `InboundResponseHandler::recipientForToken()` (the `r-…` namespace). The
 * `c-…` / `CascadeEngine::nodeForToken()` side shares the identical shape
 * (§2's table); AC 2 and AC 5 are mirrored below against that side (see
 * `a_valid_cascade_token_resolves_with_one_query_against_call_tree_test_nodes`
 * and `an_already_answered_node_inside_an_open_cascade_still_resolves`), on
 * top of the basic happy-path coverage already in `Phase6CallTreeTest`.
 */
class Phase7TokenContractAcceptanceTest extends TestCase
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
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
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

    /**
     * AC 1. One indexed fetch to `bcms_alert_recipients`, regardless of how
     * many other open recipients exist — never a scan proportional to them.
     */
    #[Test]
    public function a_valid_token_resolves_with_one_query_against_alert_recipients(): void
    {
        $this->seedRecipients(5);
        $alert = $this->alert();
        app(AlertService::class)->release($alert, $this->operator->id);

        $recipient = AlertRecipient::query()->where('alert_id', $alert->getKey())->firstOrFail();
        $token = app(AlertDispatcher::class)->tokenFor($recipient);

        // Isolated to the RESOLUTION step itself, via the private method
        // directly — `handleReply()` goes on to call `RollCallService::
        // record()`, which legitimately issues its own update and a
        // `refresh()` afterwards. AC 1 is about how the row is FOUND, not
        // about the write that follows finding it.
        $method = new ReflectionMethod(InboundResponseHandler::class, 'recipientForToken');
        $method->setAccessible(true);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $resolved = $method->invoke(app(InboundResponseHandler::class), $token);

        $hits = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'bcms_alert_recipients') && str_contains(strtolower($q['query']), 'select'))
            ->count();

        DB::disableQueryLog();

        $this->assertNotNull($resolved);
        $this->assertSame($recipient->getKey(), $resolved->getKey());
        $this->assertSame(1, $hits, 'Exactly one SELECT against bcms_alert_recipients, not a scan.');
    }

    /**
     * AC 3. A valid id with the WRONG tag resolves to null — the same
     * response an unknown id gets (§4 point 3's "same status, same body").
     */
    #[Test]
    public function a_wrong_tag_on_a_real_id_resolves_to_null_same_as_an_unknown_id(): void
    {
        $alert = $this->alert();
        app(AlertService::class)->release($alert, $this->operator->id);
        $recipient = AlertRecipient::query()->where('alert_id', $alert->getKey())->firstOrFail();

        $wrongTagToken = 'r-'.$recipient->getKey().'-0000000000000000';
        $unknownIdToken = 'r-999999-0000000000000000';

        $handler = app(InboundResponseHandler::class);

        $this->assertNull($handler->handleReply('SAFE '.$wrongTagToken));
        $this->assertNull($handler->handleReply('SAFE '.$unknownIdToken));
        $this->assertNull($recipient->refresh()->acknowledged_at);
    }

    /**
     * AC 4. A token for a recipient OUTSIDE the open window (its alert
     * dispatched more than two days ago) resolves to null, indistinguishable
     * from an unknown id.
     */
    #[Test]
    public function a_token_outside_the_open_window_resolves_to_null(): void
    {
        $alert = $this->alert();
        app(AlertService::class)->release($alert, $this->operator->id);
        $recipient = AlertRecipient::query()->where('alert_id', $alert->getKey())->firstOrFail();
        $token = app(AlertDispatcher::class)->tokenFor($recipient);

        $alert->forceFill(['dispatched_at' => now()->subDays(3)])->save();

        $resolved = app(InboundResponseHandler::class)->handleReply('SAFE '.$token);

        $this->assertNull($resolved);
        $this->assertNull($recipient->refresh()->acknowledged_at);
    }

    /**
     * AC 2. The `c-…` mirror of AC 1: one indexed fetch to
     * `bcms_call_tree_test_nodes`, regardless of how many other nodes the
     * open cascade carries.
     */
    #[Test]
    public function a_valid_cascade_token_resolves_with_one_query_against_call_tree_test_nodes(): void
    {
        $tree = $this->smallApprovedCallTree(nodes: 5);
        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->operator->id);
        $engine->initiate($test, $this->operator->id);

        $row = CallTreeTestNode::query()->where('test_id', $test->getKey())->firstOrFail();
        $token = $engine->tokenFor($row);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $resolved = $engine->nodeForToken($token);

        $hits = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'bcms_call_tree_test_nodes') && str_contains(strtolower($q['query']), 'select'))
            ->count();

        DB::disableQueryLog();

        $this->assertNotNull($resolved);
        $this->assertSame($row->getKey(), $resolved->getKey());
        $this->assertSame(1, $hits, 'Exactly one SELECT against bcms_call_tree_test_nodes, not a scan.');
    }

    /**
     * AC 5. A node that has already answered inside a still-open cascade
     * keeps resolving — a second tap on the link must report the answer,
     * not a dead link (§2's eligibility table, "unchanged" row).
     */
    #[Test]
    public function an_already_answered_node_inside_an_open_cascade_still_resolves(): void
    {
        $tree = $this->smallApprovedCallTree(nodes: 3);
        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->operator->id);
        $engine->initiate($test, $this->operator->id);

        $row = CallTreeTestNode::query()->where('test_id', $test->getKey())->firstOrFail();
        $token = $engine->tokenFor($row);

        $engine->acknowledge($row, via: CascadeEngine::CHANNEL_IN_APP);
        $this->assertSame(CascadeOutcome::Reached, $row->refresh()->outcome, 'Sanity: the node has already answered.');
        $this->assertNull($test->refresh()->completed_at, 'Sanity: the cascade is still open.');

        $resolved = $engine->nodeForToken($token);

        $this->assertNotNull($resolved, 'An already-answered node inside an open cascade must still resolve.');
        $this->assertSame($row->getKey(), $resolved->getKey());
    }

    /**
     * AC 6. A `c-…` cascade token posted to the REPLY endpoint's parser is
     * rejected by shape — no token at all, not a failed lookup.
     */
    #[Test]
    public function a_cascade_token_posted_as_a_reply_is_rejected_by_shape_not_by_lookup(): void
    {
        $handler = app(InboundResponseHandler::class);

        $this->assertNull($handler->extractToken('OK c-4821-9f2c1ab77d0e4b31'));

        // And the complement, on the cascade side: an `r-…` recipient token
        // is not a shape `CascadeEngine::nodeForToken()` recognises either.
        $this->assertNull(app(CascadeEngine::class)->nodeForToken('r-4821-9f2c1ab77d0e4b31'));
    }

    /**
     * AC 7. Cross-tenant probe. A token minted for THIS tenant's recipient
     * resolves correctly when posted with NO tenant resolved — the shape a
     * webhook actually arrives in — and nothing about another tenant's data
     * is touched, because the row id is the only thing that selects.
     */
    #[Test]
    public function a_token_resolves_correctly_with_no_tenant_resolved(): void
    {
        $alert = $this->alert();
        app(AlertService::class)->release($alert, $this->operator->id);
        $recipient = AlertRecipient::query()->where('alert_id', $alert->getKey())->firstOrFail();
        $token = app(AlertDispatcher::class)->tokenFor($recipient);

        TenantContext::clear();

        $resolved = app(InboundResponseHandler::class)->handleReply('SAFE '.$token);

        $this->assertNotNull($resolved, 'OrganizationScope must not stand between a valid token and its row.');
        $this->assertSame($recipient->getKey(), $resolved->getKey());
    }

    /**
     * AC 8. No early return between the fetch and the tag comparison — a
     * static/reflective check, since a timing assertion on CI is a flake and
     * not a guard (the ADR's own words). Reads the compiled method body
     * between its opening brace and the `hash_equals` call and asserts no
     * `return` statement appears in between: every path, found row or not,
     * must reach the comparison before anything can return.
     */
    #[Test]
    public function recipient_for_token_has_no_early_return_before_the_hash_compare(): void
    {
        $method = new ReflectionMethod(InboundResponseHandler::class, 'recipientForToken');
        $body = $this->methodBody($method);

        $fetchPos = strpos($body, '->first()');
        $comparePos = strpos($body, 'hash_equals(');

        $this->assertNotFalse($fetchPos, 'Could not locate the fetch in recipientForToken() — has it been restructured?');
        $this->assertNotFalse($comparePos, 'Could not locate the hash_equals compare in recipientForToken().');

        $between = substr($body, $fetchPos, $comparePos - $fetchPos);

        $this->assertDoesNotMatchRegularExpression(
            '/\breturn\b/',
            $between,
            'A `return` between the fetch and the hash_equals compare is the timing oracle ADR 0016 §2 forbids: '.$between
        );
    }

    private function seedRecipients(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->contact('Filler '.$i);
        }
    }

    private function contact(string $name): Contact
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'organization_id' => $this->organization->id, 'name' => $name,
            'email' => 'filler'.$n.'@khb.test', 'password' => bcrypt('secret'), 'is_active' => false,
        ]);

        return Contact::query()->create([
            'organization_id' => $this->organization->id, 'user_id' => $user->id,
            'source' => ContactSource::Manual->value, 'full_name' => $name, 'employee_id' => 'F-'.$n,
            'business_unit_id' => $this->unit->id, 'email' => 'fillercontact'.$n.'@khb.test',
            'mobile_primary' => '+234701'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            'preferred_language' => 'en', 'consent_status' => 'granted', 'verification_status' => 'verified',
            'last_verified_at' => now(), 'is_active' => true,
        ]);
    }

    private function alert(): Alert
    {
        $this->contact('Primary Recipient');

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Test alert', 'message' => 'Please respond.',
            'severity' => AlertSeverity::LifeSafety->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        return $this->cleared($alert);
    }

    /** Approves a second time if the alert's recipient count demands it. */
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

    /** A flat one-tier call tree of `nodes` people, approved and ready to schedule. */
    private function smallApprovedCallTree(int $nodes): CallTree
    {
        $tree = app(CallTreeService::class)->create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id,
            'name' => 'Operations call tree',
            'tree_type' => \App\Enums\Bcms\CallTreeType::Department->value,
        ], $this->operator->id);

        for ($i = 0; $i < $nodes; $i++) {
            CallTreeNode::query()->create([
                'organization_id' => $this->organization->id,
                'call_tree_id' => $tree->getKey(),
                'parent_node_id' => null,
                'tier' => 0,
                'contact_id' => $this->contact('Node '.$i)->getKey(),
                'role_label' => 'Tier 0',
                'is_must_reach' => true,
                'expected_response_minutes' => 10,
                'sequence' => $i,
            ]);
        }

        app(CallTreeService::class)->approve($tree, $this->operator->id);

        return $tree->refresh();
    }

    private function methodBody(ReflectionMethod $method): string
    {
        $filename = (string) $method->getFileName();
        $start = $method->getStartLine() - 1;
        $length = $method->getEndLine() - $start;

        $lines = array_slice(file($filename), $start, $length);

        return implode('', $lines);
    }
}

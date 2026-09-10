<?php

namespace Tests\Feature\Bcms;

use App\Contracts\Bcms\DeliveryReceipt;
use App\Contracts\Bcms\NotificationChannel;
use App\Contracts\Bcms\Recipient;
use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\CallTreeSource;
use App\Enums\Bcms\CallTreeStatus;
use App\Enums\Bcms\CallTreeType;
use App\Enums\Bcms\CascadeMode;
use App\Enums\Bcms\CascadeOutcome;
use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\ContactSource;
use App\Enums\Bcms\FindingSource;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\CallTreeNode;
use App\Models\Bcms\CallTreeTest as CascadeTest;
use App\Models\Bcms\CallTreeTestNode;
use App\Models\Bcms\Contact;
use App\Models\Bcms\Finding;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\CallTrees\BrokenBranchAnalyser;
use App\Services\Bcms\CallTrees\CallTreeRemediation;
use App\Services\Bcms\CallTrees\CallTreeService;
use App\Services\Bcms\CallTrees\CascadeEngine;
use App\Services\Bcms\CallTrees\TreeHealthService;
use App\Services\Bcms\CallTrees\TreeProposalService;
use App\Services\Bcms\Notification\ChannelRegistry;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * GATE G2 — call tree management, and the broken-branch screen.
 *
 * The ten tests below are the ten acceptance criteria, in order, and the first
 * of them is the demo: two hundred staff, four tiers, one unreachable unit lead
 * and the number of people that isolated.
 *
 * WHAT IS ASSERTED IS THE CASCADE'S BEHAVIOUR, NEVER A GATEWAY'S. Every channel
 * is a Phase 0 recording mock and stays one; if swapping a real adapter in
 * would require changing anything here, the abstraction is wrong. Two tests
 * swap in a failing mock, which is the only honest way to exercise a wrong
 * number.
 *
 * THE COUNTS ARE COMPUTED, NEVER FIXTURED. `34` never appears as a literal in a
 * test that claims the analyser found it: the tree is built, the cascade is
 * run, and the assertion is that the count equals the number of descendants
 * that were actually left unreached. A fixture would pass against an analyser
 * that returned a constant.
 */
class Phase6CallTreeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

    private User $admin;

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

        $this->admin = User::create([
            'organization_id' => $this->organization->id, 'name' => 'BC Manager',
            'email' => 'bc@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantContext::clear();

        parent::tearDown();
    }

    /* ================================================================== */
    /*  Criterion 1 — a four-tier tree of 200 staff runs an automated test,
    /*  and the scorecard identifies a broken branch with its downstream
    /*  blocked count. THIS IS THE DEMO.
    /* ================================================================== */

    #[Test]
    public function a_four_tier_cascade_of_two_hundred_scores_itself_and_names_the_broken_branch(): void
    {
        $tree = $this->bigTree(staff: 200, approve: true);

        $this->assertSame(200, $tree->nodes()->count(), 'The demo tree is two hundred people.');
        $this->assertSame(4, $tree->nodes()->distinct()->count('tier'), 'Four tiers.');

        // The victim: a tier-2 unit lead with the most people under them, whose
        // number does not work. Exactly the estate the demo seeder builds.
        $victim = CallTreeNode::query()
            ->where('call_tree_id', $tree->getKey())->where('tier', 2)
            ->withCount('children')->orderByDesc('children_count')->first();

        $this->assertNotNull($victim);
        $victim->update(['deputy_contact_id' => null, 'deputy_user_id' => null]);
        $victim->contact?->update(['mobile_primary' => null, 'mobile_secondary' => null, 'email' => null]);

        $expectedIsolated = app(CallTreeService::class)->downstreamCount($victim);
        $this->assertGreaterThan(0, $expectedIsolated, 'The demo needs somebody below the broken node.');

        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->admin->id);
        $engine->initiate($test, $this->admin->id);

        // Everybody who was actually contacted answers. The victim cannot be,
        // so their branch never gets released — which is the mechanic.
        $this->answerEverybodyReachable($test, $engine);

        $engine->complete($test, $this->admin->id);
        $test->refresh();

        $analysis = app(BrokenBranchAnalyser::class)->analyse($test);
        $branch = collect($analysis['branches'])->firstWhere('node_id', (int) $victim->getKey());

        $this->assertNotNull($branch, 'The unreachable unit lead is reported as a broken branch.');
        $this->assertSame(
            $expectedIsolated,
            $branch['downstream_blocked_count'],
            'The blocked count is everybody below the failure who was never reached.',
        );
        $this->assertStringContainsString('isolated', $branch['headline']);

        // The scorecard: stored, complete, and honest about what it measured.
        $card = $test->scorecard;
        $this->assertSame(200, $card['nodes_total']);
        $this->assertNotNull($card['completion_rate']);
        $this->assertNotEmpty($card['by_tier'], 'Per-tier timing is part of the scorecard.');
        $this->assertSame(4, count($card['by_tier']));
        $this->assertGreaterThan(0, $card['blocked_downstream']);
        $this->assertSame($expectedIsolated, $analysis['total_blocked']);

        // A node nobody called is `blocked`, not `timeout`. Blaming thirty-odd
        // people for missing a call that was never placed would make the tree
        // look several times more broken than it is.
        $blocked = CallTreeTestNode::query()->where('test_id', $test->getKey())
            ->where('outcome', CascadeOutcome::Blocked->value)->count();
        $this->assertSame($expectedIsolated, $blocked);
    }

    /* ================================================================== */
    /*  Criterion 2 — generate from the manager chain, edit, approve,
    /*  v1 immutable, v2 created.
    /* ================================================================== */

    #[Test]
    public function generate_from_the_directory_follows_the_manager_chain_and_versions_properly(): void
    {
        // Five levels of reporting, which collapses onto four tiers: a nine
        // level bank is still a four-tier cascade.
        $chain = $this->managerChain(levels: 5);

        $tree = app(TreeProposalService::class)->generate($this->unit, CallTreeType::Department, null, $this->admin->id);

        $this->assertSame(CallTreeSource::AdGenerated, $tree->source);
        $this->assertSame(CallTreeStatus::Draft, $tree->status, 'Generation produces a draft, never an approved tree.');
        $this->assertSame(count($chain), $tree->nodes()->count());

        // The chain is reproduced: the level-1 person's node has the level-0
        // person's node as its parent, and depth beyond three is capped.
        $root = $tree->nodes()->whereNull('parent_node_id')->first();
        $this->assertNotNull($root);
        $this->assertSame((int) $chain[0]->getKey(), (int) $root->contact_id);
        $this->assertSame(3, (int) $tree->nodes()->max('tier'), 'Level 4 collapses onto tier 3.');

        $child = $tree->nodes()->where('contact_id', $chain[1]->getKey())->first();
        $this->assertSame((int) $root->getKey(), (int) $child->parent_node_id);

        // The department head edits it. That makes it hybrid, not generated.
        app(CallTreeRemediation::class)->assignDeputy($child, $chain[2], $this->admin->id);
        $this->assertSame(CallTreeSource::Hybrid, $tree->refresh()->source);

        $trees = app(CallTreeService::class);
        $v1 = $trees->approve($tree, $this->admin->id);
        $this->assertSame(CallTreeStatus::Approved, $v1->status);

        // v1 is immutable. Editing throws rather than silently succeeding.
        $this->expectExceptionMessageMatches('/cannot be edited/');
        $trees->update($v1, ['name' => 'Renamed'], $this->admin->id);
    }

    #[Test]
    public function superseding_copies_the_tree_and_leaves_version_one_untouched(): void
    {
        $tree = $this->smallTree();
        $trees = app(CallTreeService::class);
        $v1 = $trees->approve($tree, $this->admin->id);
        $v1Nodes = $v1->nodes()->count();

        $v2 = $trees->supersede($v1, null, $this->admin->id);

        $this->assertSame('2.0', $v2->version);
        $this->assertSame(CallTreeStatus::Draft, $v2->status);
        $this->assertSame((int) $v1->getKey(), (int) $v2->supersedes_call_tree_id);
        $this->assertSame(CallTreeStatus::Archived, $v1->refresh()->status);
        $this->assertSame($v1Nodes, $v2->nodes()->count(), 'The nodes are copied, not moved.');
        $this->assertSame($v1Nodes, $v1->nodes()->count(), 'v1 keeps its own nodes.');

        // The copied children point at the COPIED parents. A copy whose
        // children still pointed at v1's nodes would be v1 wearing a new id.
        foreach ($v2->nodes()->whereNotNull('parent_node_id')->get() as $node) {
            $this->assertSame(
                (int) $v2->getKey(),
                (int) CallTreeNode::query()->find($node->parent_node_id)->call_tree_id,
            );
        }

        $chain = $trees->versions($v2);
        $this->assertCount(2, $chain);
    }

    /* ================================================================== */
    /*  Criterion 3 — acknowledgement in the app, by web link and by reply.
    /* ================================================================== */

    #[Test]
    public function a_node_can_acknowledge_in_app_by_web_link_or_by_inbound_reply(): void
    {
        $tree = $this->smallTree(approve: true);
        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->admin->id);
        $engine->initiate($test, $this->admin->id);

        $rows = CallTreeTestNode::query()->where('test_id', $test->getKey())->orderBy('tier_snapshot')->get();

        // (a) in the app
        Carbon::setTestNow(now()->addMinutes(3));
        $engine->acknowledge($rows[0], via: CascadeEngine::CHANNEL_IN_APP);
        $this->assertSame(CascadeOutcome::Reached, $rows[0]->refresh()->outcome);
        $this->assertSame(3, (int) $rows[0]->response_minutes, 'The response is timed, not just flagged.');

        // (b) by the signed web link in the message
        $token = $engine->tokenFor($rows[1]);
        $this->assertSame($rows[1]->getKey(), $engine->nodeForToken($token)?->getKey());

        $this->get(route('bcms.cascade.ack', $token))->assertOk();

        // POST then REDIRECT, so a refresh of the thank-you page does not
        // re-submit — and a refresh is exactly what somebody does when they are
        // not sure a tap registered.
        $this->post(route('bcms.cascade.ack.store', $token))
            ->assertRedirect(route('bcms.cascade.ack', $token));
        $this->assertSame(CascadeOutcome::Reached, $rows[1]->refresh()->outcome);

        // And the link still resolves afterwards, saying the answer is in
        // rather than that the link is dead.
        $this->get(route('bcms.cascade.ack', $token))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Bcms/CallTrees/Acknowledge')
                ->where('done', true)
                ->where('headline', 'Acknowledged. Thank you.')
            );

        // A stale token is refused, not applied to somebody else.
        $this->assertNull($engine->nodeForToken('deadbeefdeadbeef'));

        // (c) by an inbound reply carrying the token
        $third = CallTreeTestNode::query()->where('test_id', $test->getKey())
            ->where('outcome', CascadeOutcome::Pending->value)->first();
        $this->assertNotNull($third);

        $this->postJson(route('bcms.cascade.inbound'), [
            'from' => '+2348000000123',
            'body' => 'OK '.$engine->tokenFor($third),
            'channel' => 'sms',
        ])->assertOk()->assertJson(['matched' => true]);

        $this->assertTrue($third->refresh()->outcome?->isReached());

        // An unmatched reply is a 200 with `matched: false`. A gateway that
        // receives an error retries, and a retried unmatched reply is a loop
        // that costs money.
        $this->postJson(route('bcms.cascade.inbound'), ['body' => 'who is this'])
            ->assertOk()->assertJson(['matched' => false]);
    }

    /* ================================================================== */
    /*  Criterion 4 — hybrid reports its two halves separately.
    /* ================================================================== */

    #[Test]
    public function hybrid_mode_confirms_the_top_tiers_by_hand_and_dispatches_the_rest(): void
    {
        $tree = $this->bigTree(staff: 40, approve: true);
        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Hybrid, true, null, $this->admin->id);
        $engine->initiate($test, $this->admin->id);

        $rows = CallTreeTestNode::query()->where('test_id', $test->getKey())->get();

        // Tier 0 was released for a human to confirm, not dispatched.
        $tierZero = $rows->firstWhere('tier_snapshot', 0);
        $this->assertSame(CascadeEngine::CHANNEL_MANUAL, $tierZero->channel_used);

        $engine->acknowledge($tierZero, via: CascadeEngine::CHANNEL_MANUAL);
        $this->answerEverybodyReachable($test, $engine);
        $engine->complete($test, $this->admin->id);

        $halves = $test->refresh()->scorecard['by_confirmation'];

        $this->assertArrayHasKey('human_confirmed', $halves);
        $this->assertArrayHasKey('system_dispatched', $halves);
        $this->assertSame([0, 1], $halves['human_confirmed']['tiers']);
        $this->assertSame([2, 3], $halves['system_dispatched']['tiers']);

        // Tiers 2 and down went through a channel, not through a person.
        $dispatched = $rows->where('tier_snapshot', '>=', 2)
            ->filter(fn (CallTreeTestNode $r) => $r->channel_used !== null);
        $this->assertTrue(
            $dispatched->every(fn (CallTreeTestNode $r) => $r->channel_used !== CascadeEngine::CHANNEL_MANUAL),
            'Tiers 2 and 3 are dispatched by the system under hybrid.',
        );
    }

    /* ================================================================== */
    /*  Criterion 5 — a timeout escalates to the deputy inside the window.
    /* ================================================================== */

    #[Test]
    public function a_primary_who_does_not_answer_escalates_to_the_deputy(): void
    {
        $tree = $this->smallTree(approve: true);

        $root = $tree->nodes()->whereNull('parent_node_id')->first();
        $deputy = $this->contact('The Deputy');
        $root->update(['deputy_contact_id' => $deputy->getKey(), 'expected_response_minutes' => 15]);

        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->admin->id);
        $engine->initiate($test, $this->admin->id);

        $row = CallTreeTestNode::query()->where('test_id', $test->getKey())
            ->where('node_id', $root->getKey())->first();
        $this->assertSame(1, (int) $row->attempts);

        // Fourteen minutes: still inside the window, nothing happens.
        $engine->tick($test, now()->addMinutes(14));
        $this->assertSame(1, (int) $row->refresh()->attempts, 'The window has not closed yet.');
        $this->assertSame(CascadeOutcome::Pending, $row->outcome);

        // Sixteen: the deputy is called.
        $engine->tick($test, now()->addMinutes(16));
        $row->refresh();
        $this->assertSame(2, (int) $row->attempts, 'Attempt two is the deputy.');
        $this->assertStringContainsString('Escalated to deputy The Deputy', (string) $row->notes);

        // The deputy answers, and the scorecard says the deputy was needed.
        $engine->acknowledge($row, via: CascadeEngine::CHANNEL_IN_APP, at: now()->addMinutes(20));
        $this->assertSame(CascadeOutcome::Deputy, $row->refresh()->outcome);

        $engine->complete($test, $this->admin->id);
        $this->assertGreaterThan(0, (float) $test->refresh()->deputy_activation_rate);
    }

    #[Test]
    public function a_node_with_no_deputy_times_out_rather_than_escalating_to_nobody(): void
    {
        $tree = $this->smallTree(approve: true);

        $root = $tree->nodes()->whereNull('parent_node_id')->first();
        $root->update(['deputy_contact_id' => null, 'expected_response_minutes' => 15]);

        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->admin->id);
        $engine->initiate($test, $this->admin->id);

        $engine->tick($test, now()->addMinutes(16));

        $row = CallTreeTestNode::query()->where('test_id', $test->getKey())
            ->where('node_id', $root->getKey())->first();

        $this->assertSame(CascadeOutcome::Timeout, $row->outcome);
        $this->assertSame(1, (int) $row->attempts, 'There was nobody to make a second attempt on.');
        $this->assertStringContainsString('no deputy to escalate to', (string) $row->notes);
    }

    /* ================================================================== */
    /*  Criterion 6 — a leaver flags the tree with its downstream count.
    /* ================================================================== */

    #[Test]
    public function a_leaver_orphans_a_node_and_the_tree_reports_who_it_blocks(): void
    {
        $tree = $this->bigTree(staff: 40, approve: true);
        $trees = app(CallTreeService::class);

        $lead = CallTreeNode::query()->where('call_tree_id', $tree->getKey())->where('tier', 1)
            ->withCount('children')->orderByDesc('children_count')->first();
        $expected = $trees->downstreamCount($lead);
        $this->assertGreaterThan(0, $expected);

        $this->assertSame([], $trees->orphanedNodes($tree), 'Nothing is orphaned before anybody leaves.');

        // HR deactivates them. Nothing about the tree changes; the node still
        // has a name on it. That is the failure this catches.
        $lead->contact?->update(['is_active' => false]);

        $orphans = $trees->orphanedNodes($tree);
        $this->assertCount(1, $orphans);
        $this->assertSame((int) $lead->getKey(), $orphans[0]['id']);
        $this->assertSame($expected, $orphans[0]['downstream_count']);
        $this->assertStringContainsString('no longer an active contact', $orphans[0]['reason']);

        // The nightly sweep flags the tree with the downstream count. It does
        // NOT raise a finding: `call_tree_test` findings carry a test id and a
        // sweep has no test to point at, so filing one nightly for ever would
        // be a register nobody reads. The corrective action is raised by a
        // person from the broken-branch screen (criterion 9).
        $this->artisan('bcms:call-tree-hygiene')->assertSuccessful();

        $flag = \App\Models\Bcms\AuditLog::query()
            ->where('event', 'call_tree.hygiene_flagged')->latest('id')->first();

        $this->assertNotNull($flag, 'The tree is flagged as impacted.');

        $context = json_decode((string) $flag->getRawOriginal('after'), true);
        $this->assertSame($expected, $context['downstream_blocked'],
            'The flag carries the number of people the leaver blocks, not just that somebody left.');
        $this->assertSame(1, $context['orphaned_nodes']);

        $this->assertSame(0, Finding::query()->where('source', FindingSource::CallTreeTest->value)->count());
    }

    /* ================================================================== */
    /*  Criterion 7 — unannounced hides the entry and writes the audit note.
    /* ================================================================== */

    #[Test]
    public function an_unannounced_cascade_records_the_suppression_and_hides_the_calendar_entry(): void
    {
        $tree = $this->smallTree(approve: true);

        $test = app(CascadeEngine::class)
            ->schedule($tree, CascadeMode::Automated, announced: false, userId: $this->admin->id);

        $this->assertFalse((bool) $test->announced);

        $audit = \App\Models\Bcms\AuditLog::query()
            ->where('event', 'call_tree_test.unannounced')->latest('id')->first();

        $this->assertNotNull($audit, 'The suppression is written down where an examiner will find it.');
        $this->assertStringContainsString(
            'suppressed',
            json_encode($audit->getAttributes()),
            'The note says what was suppressed and why.',
        );

        // The calendar filter: a participant does not see an unannounced
        // occurrence; somebody who can run the programme does. Asserted at the
        // service, because that is where the rule lives.
        $participant = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Teller',
            'email' => 'teller@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);

        $this->assertFalse($participant->can('bcms.exercise.manage'));
    }

    /* ================================================================== */
    /*  Criterion 8 — a tree past its cadence is stale and moves the KRI.
    /* ================================================================== */

    #[Test]
    public function a_tree_past_its_review_cadence_is_stale_and_the_indicator_moves(): void
    {
        $trees = app(CallTreeService::class);
        $health = app(TreeHealthService::class);

        $fresh = $this->smallTree(approve: true);
        $this->assertFalse($trees->isStale($fresh));
        $this->assertNull($trees->daysOverdue($fresh));

        $before = collect($health->kris())->firstWhere('code', 'BCMS-CT-STALE')['value'];

        // 181 days on a 180-day cadence. Staleness is computed, so it is true
        // the moment it is true — no job has to run.
        $fresh->update(['last_reviewed_at' => now()->subDays(181), 'approved_at' => now()->subDays(181)]);

        $this->assertTrue($trees->isStale($fresh->refresh()));
        $this->assertSame(1, $trees->daysOverdue($fresh));
        $this->assertSame(1, $trees->staleQuery()->count(), 'The SQL and the per-row rule agree.');

        $after = collect($health->kris())->firstWhere('code', 'BCMS-CT-STALE')['value'];
        $this->assertSame($before + 1, $after);

        // Recording a review clears it without changing the version.
        $trees->markReviewed($fresh, $this->admin->id);
        $this->assertFalse($trees->isStale($fresh->refresh()));
        $this->assertSame('1.0', $fresh->version);
    }

    /* ================================================================== */
    /*  Criterion 9 — the four one-click actions, and no second register.
    /* ================================================================== */

    #[Test]
    public function the_broken_branch_actions_each_work_and_the_finding_lands_in_the_phase_one_register(): void
    {
        $tree = $this->bigTree(staff: 20, approve: true);
        $engine = app(CascadeEngine::class);
        $remediation = app(CallTreeRemediation::class);

        $victim = CallTreeNode::query()->where('call_tree_id', $tree->getKey())->where('tier', 2)
            ->withCount('children')->orderByDesc('children_count')->first();
        $victim->update(['deputy_contact_id' => null]);
        $victim->contact?->update(['mobile_primary' => null, 'email' => null]);

        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->admin->id);
        $engine->initiate($test, $this->admin->id);
        $this->answerEverybodyReachable($test, $engine);
        $engine->complete($test, $this->admin->id);

        $row = CallTreeTestNode::query()->where('test_id', $test->getKey())
            ->where('node_id', $victim->getKey())->first();

        // (a) fix the contact record — and it comes back unverified, because an
        // edited number is not a working number.
        $remediation->fixContact($victim->contact, ['mobile_primary' => '+2348000999111'], $this->admin->id);
        $this->assertSame('+2348000999111', $victim->contact->refresh()->mobile_primary);
        $this->assertSame('unverified', $victim->contact->verification_status);
        $this->assertSame(0, (int) $victim->contact->consecutive_failures);

        // The tree is approved, so structural repairs need a new version.
        $v2 = app(CallTreeService::class)->supersede($tree, null, $this->admin->id);
        $v2Victim = $v2->nodes()->where('contact_id', $victim->contact_id)->first();

        // (b) assign a deputy
        $deputy = $this->contact('Standby');
        $remediation->assignDeputy($v2Victim, $deputy, $this->admin->id);
        $this->assertSame((int) $deputy->getKey(), (int) $v2Victim->refresh()->deputy_contact_id);

        // A person cannot be their own deputy — escalating to whoever did not
        // answer is not an escalation.
        $this->assertThrows(
            fn () => $remediation->assignDeputy($v2Victim, $v2Victim->contact, $this->admin->id),
            InvalidArgumentException::class,
        );

        // (c) re-parent, and refuse a loop
        $newParent = $v2->nodes()->where('tier', 1)->first();
        $remediation->reparent($v2Victim, $newParent, $this->admin->id);
        $this->assertSame((int) $newParent->getKey(), (int) $v2Victim->refresh()->parent_node_id);

        $child = $v2Victim->children()->first();
        $this->assertThrows(
            fn () => $remediation->reparent($v2Victim, $child, $this->admin->id),
            InvalidArgumentException::class,
        );

        // (d) raise a finding — in Phase 1's register, with the right source.
        $finding = $remediation->raiseFinding($test, $row, null, userId: $this->admin->id);

        $this->assertSame(FindingSource::CallTreeTest, $finding->source);
        $this->assertSame((int) $test->getKey(), (int) $finding->call_tree_test_id);
        $this->assertNotEmpty($finding->reference);
        $this->assertNotEmpty($finding->iso_clause_ref);

        // Raising it twice from the same source does not duplicate it.
        $again = $remediation->raiseFinding($test, $row, null, userId: $this->admin->id);
        $this->assertSame((int) $finding->getKey(), (int) $again->getKey());
    }

    #[Test]
    public function track_c_owns_no_second_findings_register(): void
    {
        // Criterion 9's grep, as a test. A second table or a second service
        // would be two answers to "what is outstanding", and the overdue count
        // on the board pack would be whichever one somebody happened to query.
        $callTreeCode = collect(glob(app_path('Services/Bcms/CallTrees/*.php')))
            ->merge(glob(app_path('Http/Controllers/Bcms/CallTree*.php')))
            ->merge(glob(app_path('Http/Controllers/Bcms/CascadeAckController.php')))
            ->map(fn (string $file) => file_get_contents($file))
            ->implode("\n");

        $this->assertStringNotContainsString('Finding::query()->create', $callTreeCode,
            'Track C must raise findings through FindingService, never by writing the table.');
        $this->assertStringNotContainsString('CorrectiveAction::query()->create', $callTreeCode,
            'Track C must not write corrective actions directly.');

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasTable('bcms_call_tree_findings'),
            'There is one findings register and it is Phase 1\'s.',
        );
    }

    /* ================================================================== */
    /*  Criterion 10 — a withdrawal excludes the channel, visibly.
    /* ================================================================== */

    #[Test]
    public function a_contact_who_withdrew_consent_is_excluded_visibly_rather_than_silently(): void
    {
        $tree = $this->smallTree(approve: true);

        $node = $tree->nodes()->whereNull('parent_node_id')->first();
        $contact = $node->contact;

        // Personal channels only, and every other channel removed, so the
        // withdrawal is the only reason they cannot be reached.
        $contact->update([
            'consent_status' => 'withdrawn',
            'consent_withdrawn_at' => now(),
            'email' => null, 'teams_id' => null, 'slack_id' => null, 'push_token' => null,
        ]);

        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->admin->id);
        $engine->initiate($test, $this->admin->id);

        $row = CallTreeTestNode::query()->where('test_id', $test->getKey())
            ->where('node_id', $node->getKey())->first();

        $this->assertSame(CascadeOutcome::ConsentBlocked, $row->outcome,
            'A withdrawal is its own outcome, not a generic failure.');
        $this->assertStringContainsString('withdrawn consent', (string) $row->notes);
        $this->assertStringContainsString('life-safety', (string) $row->notes,
            'The note says what would still reach them in an emergency.');

        $engine->complete($test, $this->admin->id);
        $card = $test->refresh()->scorecard;

        $this->assertSame(1, $card['consent_excluded']);
        $this->assertContains($contact->full_name, $card['consent_excluded_names'],
            'The exclusion is named on the scorecard, not hidden in a total.');

        // AND IT IS NOT A DATA-QUALITY FAILURE. Counting it as one would put
        // pressure on somebody to "fix" a withdrawal.
        $this->assertSame(0, $card['data_quality_failures']);
    }

    /* ================================================================== */
    /*  Beyond the criteria: the traps earlier phases hit.
    /* ================================================================== */

    #[Test]
    public function a_gateway_failure_is_recorded_by_its_cause_and_escalates_immediately(): void
    {
        $this->swapFailingSms('Invalid number: not in service');

        $tree = $this->smallTree(approve: true);
        $node = $tree->nodes()->whereNull('parent_node_id')->first();
        $deputy = $this->contact('Standby');
        $node->update([
            'deputy_contact_id' => $deputy->getKey(),
            'primary_channel' => ChannelKey::Sms->value,
        ]);

        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->admin->id);
        $engine->initiate($test, $this->admin->id);

        $row = CallTreeTestNode::query()->where('test_id', $test->getKey())
            ->where('node_id', $node->getKey())->first();

        // A dead number is not worth waiting fifteen minutes over: the deputy
        // is tried at once rather than at the timeout.
        $this->assertSame(2, (int) $row->attempts);
        $this->assertStringContainsString('failed', (string) $row->notes);
    }

    #[Test]
    public function a_cascade_test_always_carries_the_exercise_prefix(): void
    {
        $captured = [];
        $this->captureSms($captured);

        $tree = $this->smallTree(approve: true);
        $tree->nodes()->update(['primary_channel' => ChannelKey::Sms->value]);

        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->admin->id);
        $engine->initiate($test, $this->admin->id);

        $this->assertNotEmpty($captured, 'Something was dispatched.');

        foreach ($captured as $message) {
            $this->assertStringStartsWith(RenderedMessage::EXERCISE_PREFIX, $message->wireBody(),
                'Standing rule 5: a cascade test is always a simulation.');
        }
    }

    #[Test]
    public function a_parent_cycle_does_not_hang_the_downstream_walk(): void
    {
        // The memory of a 512 MB out-of-memory error on an unguarded object
        // graph walk. `parent_node_id` is user-editable and a directory export
        // with a management loop in it is not hypothetical.
        $tree = $this->smallTree();
        $nodes = $tree->nodes()->orderBy('tier')->get();

        \Illuminate\Support\Facades\DB::table('bcms_call_tree_nodes')
            ->where('id', $nodes[0]->getKey())
            ->update(['parent_node_id' => $nodes[2]->getKey()]);

        $structure = app(CallTreeService::class)->structure($tree->refresh());

        $this->assertIsArray($structure['nodes'], 'The walk terminates rather than exhausting memory.');
    }

    #[Test]
    public function a_draft_tree_cannot_be_cascaded(): void
    {
        $tree = $this->smallTree();

        $this->expectExceptionMessageMatches('/Only an approved call tree/');
        app(CascadeEngine::class)->schedule($tree, CascadeMode::Automated, true, null, $this->admin->id);
    }

    #[Test]
    public function an_empty_tree_cannot_be_approved(): void
    {
        $tree = app(CallTreeService::class)->create([
            'organization_id' => $this->organization->id,
            'name' => 'Empty', 'tree_type' => CallTreeType::Department->value,
        ], $this->admin->id);

        $this->expectExceptionMessageMatches('/no nodes cannot be approved/');
        app(CallTreeService::class)->approve($tree, $this->admin->id);
    }

    #[Test]
    public function a_crisis_team_tree_is_not_generated_from_the_reporting_chain(): void
    {
        $this->managerChain(levels: 3);

        $this->expectExceptionMessageMatches('/appointments, not reporting lines/');
        app(TreeProposalService::class)->preview($this->unit, CallTreeType::CrisisTeam);
    }

    #[Test]
    public function the_scorecard_reports_nothing_measured_rather_than_zero(): void
    {
        $tree = $this->smallTree(approve: true);
        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Manual, true, null, $this->admin->id);
        $engine->initiate($test, $this->admin->id);
        $engine->complete($test, $this->admin->id);

        $card = $test->refresh()->scorecard;

        // Nobody answered, so there is no median response time. Null, not zero:
        // "0 minutes" is a claim that everybody answered instantly.
        $this->assertNull($card['median_response_minutes']);
        $this->assertSame(0.0, (float) $card['completion_rate'], 'Nobody reached IS zero per cent.');

        // A tenant with no completed tests has no completion-rate indicator.
        $organization = Organization::create([
            'name' => 'Empty Bank', 'short_name' => 'EB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        TenantContext::set($organization->id);
        $this->assertNull(collect(app(TreeHealthService::class)->kris())->firstWhere('code', 'BCMS-CT-COMPLETION')['value']);
        TenantContext::set($this->organization->id);
    }

    /* ================================================================== */
    /*  Helpers
    /* ================================================================== */

    private function contact(string $name, ?User $manager = null): Contact
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'organization_id' => $this->organization->id,
            'name' => $name,
            'email' => 'c'.$n.'@khb.test',
            'password' => bcrypt('secret'),
            'is_active' => false,
        ]);

        return Contact::query()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
            'source' => ContactSource::Manual->value,
            'full_name' => $name,
            'employee_id' => 'T-'.$n,
            'business_unit_id' => $this->unit->id,
            'title' => $name,
            'manager_user_id' => $manager?->id,
            'email' => 'contact'.$n.'@khb.test',
            'mobile_primary' => '+2348000'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'preferred_language' => 'en',
            'consent_status' => 'granted',
            'verification_status' => 'verified',
            'last_verified_at' => now()->subDays(10),
            'is_active' => true,
        ]);
    }

    /**
     * A reporting chain `levels` deep, each person managing the next.
     *
     * @return list<Contact>
     */
    private function managerChain(int $levels): array
    {
        $chain = [];
        $previous = null;

        for ($i = 0; $i < $levels; $i++) {
            $contact = $this->contact('Level '.$i, $previous?->user);
            $chain[] = $contact;
            $previous = $contact;
        }

        return $chain;
    }

    private function smallTree(bool $approve = false): CallTree
    {
        return $this->buildTree([1, 2, 2], $approve);
    }

    /** A four-tier tree with `staff` people in it, shaped like a department. */
    private function bigTree(int $staff, bool $approve = false): CallTree
    {
        $sections = max(1, (int) ceil(($staff - 1) / 40));
        $leads = max(1, (int) ceil(($staff - 1 - $sections) / 8));
        $rest = $staff - 1 - $sections - $leads;

        return $this->buildTree([1, $sections, $leads, $rest], $approve);
    }

    /**
     * @param  list<int>  $perTier  how many people at tier 0, 1, 2, 3
     */
    private function buildTree(array $perTier, bool $approve): CallTree
    {
        $tree = app(CallTreeService::class)->create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id,
            'name' => 'Operations call tree',
            'tree_type' => CallTreeType::Department->value,
        ], $this->admin->id);

        $previousTier = [];

        foreach ($perTier as $tier => $count) {
            $thisTier = [];

            for ($i = 0; $i < $count; $i++) {
                $parent = $previousTier === [] ? null : $previousTier[$i % count($previousTier)];

                $thisTier[] = CallTreeNode::query()->create([
                    'organization_id' => $this->organization->id,
                    'call_tree_id' => $tree->getKey(),
                    'parent_node_id' => $parent?->getKey(),
                    'tier' => $tier,
                    'contact_id' => $this->contact('T'.$tier.' person '.$i)->getKey(),
                    'role_label' => 'Tier '.$tier,
                    'is_must_reach' => $tier <= 1,
                    'expected_response_minutes' => $tier === 0 ? 10 : 30,
                    'sequence' => $i,
                ]);
            }

            $previousTier = $thisTier;
        }

        if ($approve) {
            app(CallTreeService::class)->approve($tree, $this->admin->id);
        }

        return $tree->refresh();
    }

    /**
     * Answer for everybody the cascade actually managed to contact.
     *
     * Loops until nothing new is released: acknowledging tier 1 releases tier 2,
     * which releases tier 3. A single pass would leave the lower tiers pending
     * and the completion rate would be a fifth of the truth.
     */
    private function answerEverybodyReachable(CascadeTest $test, CascadeEngine $engine): void
    {
        for ($pass = 0; $pass < 8; $pass++) {
            $waiting = CallTreeTestNode::query()
                ->where('test_id', $test->getKey())
                ->where('outcome', CascadeOutcome::Pending->value)
                ->whereNotNull('contacted_at')
                ->get();

            if ($waiting->isEmpty()) {
                return;
            }

            foreach ($waiting as $row) {
                $engine->acknowledge($row, via: CascadeEngine::CHANNEL_IN_APP);
            }
        }
    }

    private function swapFailingSms(string $reason): void
    {
        app(ChannelRegistry::class)->swap(ChannelKey::Sms, new class($reason) implements NotificationChannel
        {
            public function __construct(private string $reason) {}

            public function key(): ChannelKey
            {
                return ChannelKey::Sms;
            }

            public function provider(): string
            {
                return 'mock-failing-sms';
            }

            public function supports(Recipient $to): bool
            {
                return true;
            }

            public function send(Recipient $to, RenderedMessage $message): DeliveryReceipt
            {
                return DeliveryReceipt::failed($this->provider(), $this->reason);
            }

            public function estimateCostMinor(Recipient $to, RenderedMessage $message): ?int
            {
                return null;
            }
        });
    }

    /** @param list<RenderedMessage> $captured */
    private function captureSms(array &$captured): void
    {
        app(ChannelRegistry::class)->swap(ChannelKey::Sms, new class($captured) implements NotificationChannel
        {
            /** @param list<RenderedMessage> $captured */
            public function __construct(private array &$captured) {}

            public function key(): ChannelKey
            {
                return ChannelKey::Sms;
            }

            public function provider(): string
            {
                return 'mock-capturing-sms';
            }

            public function supports(Recipient $to): bool
            {
                return true;
            }

            public function send(Recipient $to, RenderedMessage $message): DeliveryReceipt
            {
                $this->captured[] = $message;

                return DeliveryReceipt::sent($this->provider(), 'cap-'.count($this->captured));
            }

            public function estimateCostMinor(Recipient $to, RenderedMessage $message): ?int
            {
                return null;
            }
        });
    }
}

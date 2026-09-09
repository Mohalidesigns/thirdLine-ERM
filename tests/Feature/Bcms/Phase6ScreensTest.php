<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\CallTreeType;
use App\Enums\Bcms\CascadeMode;
use App\Enums\Bcms\ContactSource;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\CallTreeNode;
use App\Models\Bcms\Contact;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\CallTrees\CallTreeService;
use App\Services\Bcms\CallTrees\CascadeEngine;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Phase 6 screens.
 *
 * Development standard §10: these stop at the props boundary. What is asserted
 * is that the server shipped the shape the screen draws, not that React drew
 * it — the tree is recursive and every count on it is computed in PHP, so the
 * props ARE the feature.
 *
 * THE THREE PERMISSIONS ARE TESTED SEPARATELY because they are genuinely
 * different authorities. Somebody who may draw a tree must not be able to fire
 * it at two hundred people, and a test that used one admin for everything would
 * never notice if the middleware said otherwise.
 */
class Phase6ScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

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
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function the_dashboard_leads_with_what_would_fail(): void
    {
        $tree = $this->tree(approve: true);
        $tree->update(['last_reviewed_at' => now()->subDays(400), 'approved_at' => now()->subDays(400)]);

        $this->actingAs($this->userWith(['bcms.calltree.view']))
            ->get(route('bcms.call-trees.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/CallTrees/Index')
                ->where('dashboard.summary.stale', 1)
                ->has('dashboard.summary.orphaned_nodes')
                ->where('dashboard.summary.never_tested', 1)
                // Four indicators, each with an owner who can move it.
                ->has('dashboard.kris', 4)
                ->has('dashboard.data_confidence.confidence')
                ->has('dashboard.trees.0.days_overdue')
                ->where('can.manage', false)
                ->where('can.test', false)
            );
    }

    #[Test]
    public function the_designer_carries_the_tree_its_problems_and_its_versions(): void
    {
        $tree = $this->tree();

        // One node whose person has left, so the problems panel has something
        // real to show rather than an empty state.
        $node = $tree->nodes()->where('tier', 1)->first();
        $node->contact?->update(['is_active' => false]);

        $this->actingAs($this->userWith(['bcms.calltree.view', 'bcms.calltree.manage']))
            ->get(route('bcms.call-trees.show', $tree))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/CallTrees/Designer')
                ->where('tree.editable', true)
                ->where('tree.testable', false)
                ->has('nodes.0.children')
                ->has('nodes.0.downstream_count')
                ->has('orphaned.0.downstream_count')
                ->has('versions', 1)
                ->has('tier_labels')
                ->has('modes', 3)
                ->where('can.manage', true)
            );
    }

    #[Test]
    public function the_versions_endpoint_lists_the_whole_supersession_chain(): void
    {
        $tree = $this->tree(approve: true);

        $next = app(CallTreeService::class)->supersede($tree, '2.0', $this->admin()->id);

        $response = $this->actingAs($this->userWith(['bcms.calltree.view']))
            ->getJson(route('bcms.call-trees.versions', $next));

        $response->assertOk();

        $versions = collect($response->json('versions'));

        $this->assertCount(2, $versions);
        $this->assertSame(
            ['1.0', '2.0'],
            $versions->pluck('version')->sort()->values()->all(),
        );
        $old = $versions->firstWhere('version', '1.0');
        $new = $versions->firstWhere('version', '2.0');

        $this->assertSame('archived', $old['status']);
        $this->assertFalse($old['is_current']);
        $this->assertSame('draft', $new['status']);
        $this->assertTrue($new['is_current']);
    }

    #[Test]
    public function the_versions_endpoint_needs_the_calltree_view_permission(): void
    {
        $tree = $this->tree();

        $this->actingAs($this->userWith([], 'nobody-versions@khb.test'))
            ->getJson(route('bcms.call-trees.versions', $tree))
            ->assertForbidden();
    }

    #[Test]
    public function the_versions_endpoint_does_not_reach_another_tenants_tree(): void
    {
        $other = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);
        $foreign = CallTree::query()->create([
            'organization_id' => $other->id, 'name' => 'Theirs',
            'tree_type' => CallTreeType::Department->value, 'version' => '1.0', 'status' => 'draft',
            'review_frequency_days' => 180, 'source' => 'manual',
        ]);
        TenantContext::set($this->organization->id);

        $this->actingAs($this->userWith(['bcms.calltree.view']))
            ->getJson(route('bcms.call-trees.versions', $foreign))
            ->assertNotFound();
    }

    #[Test]
    public function the_candidates_endpoint_offers_people_in_the_trees_own_unit(): void
    {
        $tree = $this->tree();

        // Somebody in the tree's own department who is not on the tree yet.
        $available = $this->contact('Available Officer');

        // Somebody in a different department entirely.
        $otherUnit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-FIN',
            'name' => 'Finance', 'is_active' => true,
        ]);
        $outOfScope = Contact::query()->create([
            'organization_id' => $this->organization->id,
            'source' => ContactSource::Manual->value,
            'full_name' => 'Finance Officer',
            'employee_id' => 'S-FIN-1',
            'business_unit_id' => $otherUnit->id,
            'title' => 'Finance Officer',
            'email' => 'finance-officer@khb.test',
            'mobile_primary' => '+2348001112223',
            'preferred_language' => 'en',
            'consent_status' => 'granted',
            'verification_status' => 'verified',
            'last_verified_at' => now()->subDays(5),
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->userWith(['bcms.calltree.view']))
            ->getJson(route('bcms.call-trees.candidates', $tree).'?q=Officer');

        $response->assertOk();

        $names = collect($response->json('contacts'))->pluck('name');

        $this->assertTrue($names->contains('Available Officer'));
        $this->assertFalse($names->contains('Finance Officer'));
        $this->assertTrue($response->json('contacts.0')['has_mobile']);
    }

    #[Test]
    public function the_candidates_endpoint_needs_the_calltree_view_permission(): void
    {
        $tree = $this->tree();

        $this->actingAs($this->userWith([], 'nobody-candidates@khb.test'))
            ->getJson(route('bcms.call-trees.candidates', $tree))
            ->assertForbidden();
    }

    #[Test]
    public function the_candidates_endpoint_does_not_reach_another_tenants_tree(): void
    {
        $other = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);
        $foreign = CallTree::query()->create([
            'organization_id' => $other->id, 'name' => 'Theirs',
            'tree_type' => CallTreeType::Department->value, 'version' => '1.0', 'status' => 'draft',
            'review_frequency_days' => 180, 'source' => 'manual',
        ]);
        TenantContext::set($this->organization->id);

        $this->actingAs($this->userWith(['bcms.calltree.view']))
            ->getJson(route('bcms.call-trees.candidates', $foreign))
            ->assertNotFound();
    }

    #[Test]
    public function the_live_map_and_its_polled_payload_agree(): void
    {
        $tree = $this->tree(approve: true);
        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->admin()->id);
        $engine->initiate($test, $this->admin()->id);

        $viewer = $this->userWith(['bcms.calltree.view']);

        $this->actingAs($viewer)
            ->get(route('bcms.call-tree-tests.live', $test))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/CallTrees/Live')
                ->where('running', true)
                ->has('tree.0.state')
                ->has('tiers.0.percent')
                // A green tick from a recording mock is the most dangerous
                // thing this module can show, so the screen says so.
                ->where('channels_are_mocked', true)
            );

        $this->actingAs($viewer)
            ->getJson(route('bcms.call-tree-tests.live.json', $test))
            ->assertOk()
            ->assertJsonStructure(['test', 'tree', 'counts', 'tiers', 'total_blocked', 'running']);
    }

    #[Test]
    public function the_results_screen_ships_the_broken_branch_with_its_count_and_its_fixes(): void
    {
        $tree = $this->tree(approve: true, staff: 12);

        $victim = CallTreeNode::query()->where('call_tree_id', $tree->getKey())->where('tier', 1)
            ->withCount('children')->orderByDesc('children_count')->first();
        $victim->update(['deputy_contact_id' => null]);
        $victim->contact?->update(['mobile_primary' => null, 'email' => null]);

        $expected = app(CallTreeService::class)->downstreamCount($victim);

        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->admin()->id);
        $engine->initiate($test, $this->admin()->id);

        // Everybody the cascade could actually contact answers, so the tier-1
        // failure is the TOP failure. Without this the root times out too and
        // the analyser correctly reports one broken branch at tier 0 with the
        // whole tree below it — which is right, and is not this test's subject.
        for ($pass = 0; $pass < 6; $pass++) {
            $waiting = \App\Models\Bcms\CallTreeTestNode::query()
                ->where('test_id', $test->getKey())
                ->where('outcome', \App\Enums\Bcms\CascadeOutcome::Pending->value)
                ->whereNotNull('contacted_at')->get();

            if ($waiting->isEmpty()) {
                break;
            }

            foreach ($waiting as $row) {
                $engine->acknowledge($row);
            }
        }

        $engine->complete($test, $this->admin()->id);

        $this->actingAs($this->userWith(['bcms.calltree.view', 'bcms.finding.manage']))
            ->get(route('bcms.call-tree-tests.show', $test))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/CallTrees/Results')
                ->where('scorecard_is_stored', true)
                ->where('branches.0.downstream_blocked_count', $expected)
                ->has('branches.0.headline')
                ->has('branches.0.remedies')
                ->has('scorecard.by_tier')
                ->has('scorecard.must_reach_missed')
                ->where('can.findings', true)
                ->where('can.manage', false)
            );
    }

    #[Test]
    public function the_scorecard_endpoint_stores_its_result_once_the_cascade_completes(): void
    {
        [$test] = $this->brokenCascade();

        $response = $this->actingAs($this->userWith(['bcms.calltree.view']))
            ->getJson(route('bcms.call-tree-tests.scorecard', $test));

        $response->assertOk();
        $this->assertTrue($response->json('stored'));
        $this->assertSame($test->refresh()->scorecard, $response->json('scorecard'));
        $this->assertArrayHasKey('by_tier', $response->json('scorecard'));
        $this->assertArrayHasKey('must_reach_missed', $response->json('scorecard'));
    }

    #[Test]
    public function the_scorecard_endpoint_computes_live_while_the_cascade_is_still_running(): void
    {
        $tree = $this->tree(approve: true);
        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->admin()->id);
        $engine->initiate($test, $this->admin()->id);

        $response = $this->actingAs($this->userWith(['bcms.calltree.view']))
            ->getJson(route('bcms.call-tree-tests.scorecard', $test));

        $response->assertOk();
        $this->assertFalse($response->json('stored'));
        // Nothing is completed yet, so nothing is stored on the record — the
        // response has to have been computed on the fly.
        $this->assertEmpty($test->refresh()->scorecard);
        $this->assertArrayHasKey('nodes_total', $response->json('scorecard'));
    }

    #[Test]
    public function the_scorecard_endpoint_needs_the_calltree_view_permission(): void
    {
        [$test] = $this->brokenCascade();

        $this->actingAs($this->userWith([], 'nobody-scorecard@khb.test'))
            ->getJson(route('bcms.call-tree-tests.scorecard', $test))
            ->assertForbidden();
    }

    #[Test]
    public function the_scorecard_endpoint_does_not_reach_another_tenants_test(): void
    {
        $foreign = $this->foreignCallTreeTest();

        $this->actingAs($this->userWith(['bcms.calltree.view']))
            ->getJson(route('bcms.call-tree-tests.scorecard', $foreign))
            ->assertNotFound();
    }

    #[Test]
    public function the_broken_branches_endpoint_reports_the_same_downstream_count_as_the_results_screen(): void
    {
        [$test, $expected] = $this->brokenCascade();

        $response = $this->actingAs($this->userWith(['bcms.calltree.view']))
            ->getJson(route('bcms.call-tree-tests.broken-branches', $test));

        $response->assertOk();
        $this->assertSame($expected, $response->json('branches.0.downstream_blocked_count'));
        $this->assertArrayHasKey('headline', $response->json('branches.0'));
        $this->assertArrayHasKey('remedies', $response->json('branches.0'));
    }

    #[Test]
    public function the_broken_branches_endpoint_needs_the_calltree_view_permission(): void
    {
        [$test] = $this->brokenCascade();

        $this->actingAs($this->userWith([], 'nobody-branches@khb.test'))
            ->getJson(route('bcms.call-tree-tests.broken-branches', $test))
            ->assertForbidden();
    }

    #[Test]
    public function the_broken_branches_endpoint_does_not_reach_another_tenants_test(): void
    {
        $foreign = $this->foreignCallTreeTest();

        $this->actingAs($this->userWith(['bcms.calltree.view']))
            ->getJson(route('bcms.call-tree-tests.broken-branches', $foreign))
            ->assertNotFound();
    }

    #[Test]
    public function the_acknowledgement_page_needs_no_session_and_leads_with_the_exercise_prefix(): void
    {
        $tree = $this->tree(approve: true);
        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->admin()->id);
        $engine->initiate($test, $this->admin()->id);

        $node = \App\Models\Bcms\CallTreeTestNode::query()->where('test_id', $test->getKey())->first();
        $token = $engine->tokenFor($node);

        // No `actingAs`. A branch teller with a feature phone at 03:00 does not
        // log into a GRC platform to say "received".
        $this->get(route('bcms.cascade.ack', $token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/CallTrees/Acknowledge')
                ->where('ok', true)
                ->where('headline', fn (string $h) => str_starts_with($h, 'THIS IS AN EXERCISE'))
            );
    }

    #[Test]
    public function drawing_a_tree_and_firing_one_are_different_authorities(): void
    {
        $tree = $this->tree(approve: true);
        $designer = $this->userWith(['bcms.calltree.view', 'bcms.calltree.manage'], 'designer@khb.test');

        // A designer may not dispatch to two hundred people at three in the
        // morning. `bcms.calltree.test` is a third permission on purpose.
        $this->actingAs($designer)
            ->post(route('bcms.call-trees.tests.store', $tree), ['mode' => 'automated'])
            ->assertForbidden();

        $this->actingAs($this->userWith(['bcms.calltree.view'], 'reader@khb.test'))
            ->post(route('bcms.call-trees.store'), ['name' => 'X', 'tree_type' => 'department'])
            ->assertForbidden();
    }

    #[Test]
    public function an_approved_tree_refuses_an_edit_with_an_explanation(): void
    {
        $tree = $this->tree(approve: true);

        $this->actingAs($this->userWith(['bcms.calltree.view', 'bcms.calltree.manage']))
            ->patch(route('bcms.call-trees.update', $tree), ['name' => 'Renamed'])
            ->assertSessionHasErrors('name');

        $this->assertSame('Operations call tree', $tree->refresh()->name);
    }

    #[Test]
    public function another_tenants_call_tree_is_not_reachable(): void
    {
        $other = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);
        $foreign = CallTree::query()->create([
            'organization_id' => $other->id, 'name' => 'Theirs',
            'tree_type' => CallTreeType::Department->value, 'version' => '1.0', 'status' => 'draft',
            'review_frequency_days' => 180, 'source' => 'manual',
        ]);
        TenantContext::set($this->organization->id);

        $this->actingAs($this->userWith(['bcms.calltree.view']))
            ->get(route('bcms.call-trees.show', $foreign))
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ */

    /**
     * A completed cascade with exactly one broken branch, and the downstream
     * count that branch is expected to carry — the same fixture
     * `the_results_screen_ships_the_broken_branch_with_its_count_and_its_fixes`
     * builds, factored out so the scorecard and broken-branches JSON endpoints
     * can be asserted against the identical numbers that screen shows.
     *
     * @return array{0: \App\Models\Bcms\CallTreeTest, 1: int}
     */
    private function brokenCascade(): array
    {
        $tree = $this->tree(approve: true, staff: 12);

        $victim = CallTreeNode::query()->where('call_tree_id', $tree->getKey())->where('tier', 1)
            ->withCount('children')->orderByDesc('children_count')->first();
        $victim->update(['deputy_contact_id' => null]);
        $victim->contact?->update(['mobile_primary' => null, 'email' => null]);

        $expected = app(CallTreeService::class)->downstreamCount($victim);

        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->admin()->id);
        $engine->initiate($test, $this->admin()->id);

        for ($pass = 0; $pass < 6; $pass++) {
            $waiting = \App\Models\Bcms\CallTreeTestNode::query()
                ->where('test_id', $test->getKey())
                ->where('outcome', \App\Enums\Bcms\CascadeOutcome::Pending->value)
                ->whereNotNull('contacted_at')->get();

            if ($waiting->isEmpty()) {
                break;
            }

            foreach ($waiting as $row) {
                $engine->acknowledge($row);
            }
        }

        $engine->complete($test, $this->admin()->id);

        return [$test->refresh(), $expected];
    }

    /** A call tree test belonging to a different tenant entirely. */
    private function foreignCallTreeTest(): \App\Models\Bcms\CallTreeTest
    {
        $other = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);

        $tree = CallTree::query()->create([
            'organization_id' => $other->id, 'name' => 'Theirs',
            'tree_type' => CallTreeType::Department->value, 'version' => '1.0', 'status' => 'approved',
            'review_frequency_days' => 180, 'source' => 'manual', 'approved_at' => now(),
        ]);

        $test = \App\Models\Bcms\CallTreeTest::query()->create([
            'organization_id' => $other->id,
            'call_tree_id' => $tree->getKey(),
            'mode' => CascadeMode::Automated->value,
            'announced' => true,
        ]);

        TenantContext::set($this->organization->id);

        return $test;
    }

    private function tree(bool $approve = false, int $staff = 5): CallTree
    {
        $tree = app(CallTreeService::class)->create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id,
            'name' => 'Operations call tree',
            'tree_type' => CallTreeType::Department->value,
        ], $this->admin()->id);

        $root = CallTreeNode::query()->create([
            'organization_id' => $this->organization->id,
            'call_tree_id' => $tree->getKey(),
            'tier' => 0,
            'contact_id' => $this->contact('Head')->getKey(),
            'role_label' => 'Head of department',
            'is_must_reach' => true,
            'expected_response_minutes' => 10,
        ]);

        $leads = [];
        foreach (range(1, 2) as $i) {
            $leads[] = CallTreeNode::query()->create([
                'organization_id' => $this->organization->id,
                'call_tree_id' => $tree->getKey(),
                'parent_node_id' => $root->getKey(),
                'tier' => 1,
                'contact_id' => $this->contact('Lead '.$i)->getKey(),
                'role_label' => 'Unit lead',
                'is_must_reach' => true,
                'expected_response_minutes' => 15,
                'sequence' => $i,
            ]);
        }

        foreach (range(1, max(0, $staff - 3)) as $i) {
            CallTreeNode::query()->create([
                'organization_id' => $this->organization->id,
                'call_tree_id' => $tree->getKey(),
                'parent_node_id' => $leads[$i % 2]->getKey(),
                'tier' => 2,
                'contact_id' => $this->contact('Officer '.$i)->getKey(),
                'role_label' => 'Officer',
                'expected_response_minutes' => 30,
                'sequence' => $i,
            ]);
        }

        if ($approve) {
            app(CallTreeService::class)->approve($tree, $this->admin()->id);
        }

        return $tree->refresh();
    }

    private function contact(string $name): Contact
    {
        static $n = 0;
        $n++;

        return Contact::query()->create([
            'organization_id' => $this->organization->id,
            'source' => ContactSource::Manual->value,
            'full_name' => $name,
            'employee_id' => 'S-'.$n,
            'business_unit_id' => $this->unit->id,
            'title' => $name,
            'email' => 'screen'.$n.'@khb.test',
            'mobile_primary' => '+2348000'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'preferred_language' => 'en',
            'consent_status' => 'granted',
            'verification_status' => 'verified',
            'last_verified_at' => now()->subDays(5),
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return $this->userWith([
            'bcms.calltree.view', 'bcms.calltree.manage', 'bcms.calltree.test',
        ], 'admin@khb.test');
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions, string $email = 'bc@khb.test'): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], [
            'name' => Str::title(Str::before($email, '@')),
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id, 'is_active' => true,
        ]);

        if ($permissions !== []) {
            $role = Role::findOrCreate('bcms-'.md5($email), 'web');

            foreach ($permissions as $name) {
                $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
            }

            $user->assignRole($role);
        }

        DB::table('business_unit_user')->updateOrInsert(
            ['user_id' => $user->id, 'business_unit_id' => $this->unit->id],
            ['organization_id' => $this->organization->id, 'includes_descendants' => true,
                'created_at' => now(), 'updated_at' => now()],
        );

        return $user->refresh();
    }
}

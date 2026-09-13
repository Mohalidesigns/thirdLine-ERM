<?php

namespace Tests\Feature\Console;

use App\Enums\Bcms\CallTreeType;
use App\Enums\Bcms\CascadeMode;
use App\Enums\Bcms\CascadeOutcome;
use App\Enums\Bcms\ContactSource;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\CallTreeNode;
use App\Models\Bcms\CallTreeTest as CascadeTest;
use App\Models\Bcms\CallTreeTestNode;
use App\Models\Bcms\Contact;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\CallTrees\CallTreeService;
use App\Services\Bcms\CallTrees\CascadeEngine;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * `bcms:cascade-tick` — the every-minute wrapper around `CascadeEngine::tick()`.
 *
 * `CascadeEngine::tick()` itself is covered directly by Phase6CallTreeTest;
 * this file covers what only the wrapper does: finding every running cascade
 * (`initiated_at` set, `completed_at` null) across the tenant loop, and
 * leaving a finished-in-fact cascade untouched — `TickBcmsCascades`'s own
 * docblock is explicit that "a cascade is never auto-completed".
 */
class TickBcmsCascadesCommandTest extends TestCase
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

    #[Test]
    public function it_escalates_a_primary_who_has_not_answered_within_the_window(): void
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
        $this->assertSame(1, (int) $row->attempts, 'Fixture did not reach the state the wrapper is meant to advance.');

        Carbon::setTestNow(now()->addMinutes(16));

        $this->artisan('bcms:cascade-tick')->assertSuccessful();

        $this->assertSame(2, (int) $row->refresh()->attempts, 'The tick wrapper did not escalate to the deputy.');
        $this->assertStringContainsString('Escalated to deputy', (string) $row->notes);
    }

    #[Test]
    public function it_times_out_a_node_with_no_deputy_rather_than_escalating_to_nobody(): void
    {
        $tree = $this->smallTree(approve: true);
        $root = $tree->nodes()->whereNull('parent_node_id')->first();
        $root->update(['deputy_contact_id' => null, 'expected_response_minutes' => 15]);

        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->admin->id);
        $engine->initiate($test, $this->admin->id);

        Carbon::setTestNow(now()->addMinutes(16));

        $this->artisan('bcms:cascade-tick')->assertSuccessful();

        $row = CallTreeTestNode::query()->where('test_id', $test->getKey())
            ->where('node_id', $root->getKey())->first();

        $this->assertSame(CascadeOutcome::Timeout, $row->outcome);
    }

    #[Test]
    public function it_no_ops_quietly_when_no_cascade_is_running(): void
    {
        $this->smallTree(approve: true);

        $this->artisan('bcms:cascade-tick')
            ->expectsOutputToContain('0 running cascades')
            ->assertSuccessful();
    }

    #[Test]
    public function it_leaves_a_finished_in_fact_cascade_uncompleted(): void
    {
        // Every node answered means the cascade is finished in fact, but the
        // command's own docblock says a cascade is never auto-completed: a
        // human still has to close it.
        $tree = $this->smallTree(approve: true);

        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->admin->id);
        $engine->initiate($test, $this->admin->id);

        $this->answerEverybodyReachable($test, $engine);

        $this->artisan('bcms:cascade-tick')->assertSuccessful();

        $this->assertNull($test->refresh()->completed_at, 'The tick wrapper completed a cascade nobody closed.');
    }

    #[Test]
    public function running_the_tick_twice_in_the_same_minute_is_idempotent(): void
    {
        $tree = $this->smallTree(approve: true);
        $root = $tree->nodes()->whereNull('parent_node_id')->first();
        $root->update(['deputy_contact_id' => null, 'expected_response_minutes' => 15]);

        $engine = app(CascadeEngine::class);
        $test = $engine->schedule($tree, CascadeMode::Automated, true, null, $this->admin->id);
        $engine->initiate($test, $this->admin->id);

        Carbon::setTestNow(now()->addMinutes(16));

        $this->artisan('bcms:cascade-tick')->assertSuccessful();

        $row = CallTreeTestNode::query()->where('test_id', $test->getKey())
            ->where('node_id', $root->getKey())->first();
        $this->assertSame(CascadeOutcome::Timeout, $row->outcome);
        $attemptsAfterFirst = (int) $row->attempts;

        $this->artisan('bcms:cascade-tick')->assertSuccessful();

        $this->assertSame($attemptsAfterFirst, (int) $row->refresh()->attempts, 'A second tick re-settled an already-settled node.');
        $this->assertSame(CascadeOutcome::Timeout, $row->outcome);
    }

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

    private function smallTree(bool $approve = false): CallTree
    {
        return $this->buildTree([1, 2, 2], $approve);
    }

    /** @param list<int> $perTier how many people at tier 0, 1, 2, 3 */
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
     * Loops until nothing new is released: acknowledging tier 1 releases tier
     * 2, which releases tier 3.
     */
    private function answerEverybodyReachable(CascadeTest $test, CascadeEngine $engine): void
    {
        do {
            $pending = CallTreeTestNode::query()
                ->where('test_id', $test->getKey())
                ->where('outcome', CascadeOutcome::Pending->value)
                ->whereNotNull('contacted_at')
                ->get();

            foreach ($pending as $row) {
                $engine->acknowledge($row, via: CascadeEngine::CHANNEL_IN_APP);
            }
        } while ($pending->isNotEmpty());
    }
}

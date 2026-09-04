<?php

namespace Tests\Feature\Characterisation;

use App\Models\ControlTest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * CHARACTERISATION — migration Phase 3.4.
 *
 * Pins the six figures and two lists the control testing dashboard shows,
 * as ControlTestController@dashboard computed them before the port:
 *
 *   total / scheduled / in progress / completed   plain status counts
 *   overdue                                       scheduled AND scheduled_date < now
 *   pass rate                                     completed-and-effective / completed × 100,
 *                                                 rounded to one decimal; 0 when nothing
 *                                                 is completed (not null, not "n/a")
 *   recent                                        ten most recently UPDATED tests, any status
 *   upcoming                                      ten scheduled tests by scheduled_date ascending,
 *                                                 overdue ones included (they are still "scheduled")
 *
 * Written against the Blade view data first and re-pointed at the Inertia
 * props in the same commit, so the numbers were pinned before they moved.
 */
class ControlTestingDashboardTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        Permission::findOrCreate('control_test.view');
        $this->actor->givePermissionTo('control_test.view');
        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    private function makeTest(array $attributes = []): ControlTest
    {
        static $n = 0;
        $n++;

        return ControlTest::create(array_merge([
            'organization_id' => $this->organization->id,
            'control_id' => $this->makeControl()->id,
            'test_code' => sprintf('CT-CHAR-%04d', $n),
            'title' => "Test {$n}",
            'test_type' => 'operating_effectiveness',
            'tester_id' => $this->actor->id,
            'scheduled_date' => now()->addDays($n)->toDateString(),
            'status' => 'scheduled',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function figures(): array
    {
        return $this->dashboardProps();
    }

    #[Test]
    public function the_status_counts_are_plain_counts_per_status(): void
    {
        $this->makeTest(['status' => 'scheduled']);
        $this->makeTest(['status' => 'scheduled']);
        $this->makeTest(['status' => 'in_progress']);
        $this->makeTest(['status' => 'completed', 'result' => 'effective']);
        $this->makeTest(['status' => 'pending_review', 'result' => 'effective']);
        $this->makeTest(['status' => 'rejected', 'result' => 'ineffective']);

        $figures = $this->figures();

        $this->assertSame(6, $figures['totalTests']);
        $this->assertSame(2, $figures['scheduledTests']);
        $this->assertSame(1, $figures['inProgress']);
        $this->assertSame(1, $figures['completedTests']);
    }

    #[Test]
    public function overdue_means_scheduled_with_a_date_in_the_past(): void
    {
        $this->makeTest(['status' => 'scheduled', 'scheduled_date' => now()->subDays(3)->toDateString()]);
        $this->makeTest(['status' => 'scheduled', 'scheduled_date' => now()->addDays(3)->toDateString()]);
        // Past date but no longer scheduled: not overdue.
        $this->makeTest(['status' => 'in_progress', 'scheduled_date' => now()->subDays(30)->toDateString()]);
        $this->makeTest(['status' => 'completed', 'result' => 'effective', 'scheduled_date' => now()->subDays(30)->toDateString()]);

        $this->assertSame(1, $this->figures()['overdueTests']);
    }

    #[Test]
    public function the_pass_rate_is_effective_over_completed_to_one_decimal(): void
    {
        $this->makeTest(['status' => 'completed', 'result' => 'effective']);
        $this->makeTest(['status' => 'completed', 'result' => 'effective']);
        $this->makeTest(['status' => 'completed', 'result' => 'partially_effective']);
        // Effective but not completed: neither numerator nor denominator.
        $this->makeTest(['status' => 'pending_review', 'result' => 'effective']);

        $this->assertSame(66.7, $this->figures()['passRate']);
    }

    #[Test]
    public function the_pass_rate_is_zero_not_null_when_nothing_is_completed(): void
    {
        $this->makeTest(['status' => 'scheduled']);

        $this->assertSame(0, $this->figures()['passRate']);
    }

    #[Test]
    public function the_lists_are_capped_at_ten_and_ordered_as_before(): void
    {
        $tests = [];
        foreach (range(1, 12) as $i) {
            $tests[$i] = $this->makeTest([
                'status' => 'scheduled',
                'scheduled_date' => now()->addDays(20 - $i)->toDateString(),
            ]);
        }
        // An overdue scheduled test still counts as upcoming, and sorts first.
        $overdue = $this->makeTest(['status' => 'scheduled', 'scheduled_date' => now()->subDay()->toDateString()]);
        // Not scheduled: never upcoming, but it IS the most recently touched.
        $touched = $this->makeTest(['status' => 'completed', 'result' => 'effective']);
        ControlTest::whereKey($touched->id)->update(['updated_at' => now()->addMinute()]);

        $figures = $this->figures();

        $upcoming = $figures['upcomingIds'];
        $this->assertCount(10, $upcoming);
        $this->assertSame($overdue->id, $upcoming[0]);
        $this->assertSame($tests[12]->id, $upcoming[1], 'earliest scheduled_date after the overdue one');
        $this->assertNotContains($touched->id, $upcoming);

        $recent = $figures['recentIds'];
        $this->assertCount(10, $recent);
        $this->assertSame($touched->id, $recent[0], 'most recently updated first, whatever its status');
    }

    #[Test]
    public function another_tenants_tests_do_not_count(): void
    {
        $this->makeTest(['status' => 'completed', 'result' => 'effective']);

        $other = \App\Models\Organization::create([
            'name' => 'Other Bank PLC', 'short_name' => 'OTHB', 'institution_type' => 'commercial_bank',
            'sector' => 'banking', 'is_active' => true,
        ]);
        ControlTest::withoutGlobalScopes()->insert([
            'organization_id' => $other->id,
            'control_id' => $this->makeControl()->id,
            'test_code' => 'CT-FOREIGN',
            'title' => 'Theirs',
            'test_type' => 'operating_effectiveness',
            'tester_id' => $this->actor->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'completed',
            'result' => 'ineffective',
            'created_by' => $this->actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $figures = $this->figures();

        $this->assertSame(1, $figures['totalTests']);
        // (float) because the figure now crosses JSON: PHP's json_encode drops
        // the zero fraction, so round(100.0, 1) arrives as int 100 while
        // round(66.7, 1) arrives as float 66.7. The NUMBER is unchanged, which
        // is what this test is about; its PHP type on the far side of the wire
        // is not something the dashboard promises.
        $this->assertSame(100.0, (float) $figures['passRate']);
    }

    /* ------------------------------------------------------------------ */

    /**
     * The dashboard's figures, keyed as the controller names them.
     *
     * @return array<string, mixed>
     */
    private function dashboardProps(): array
    {
        // Migration Phase 3.4 put this screen on Inertia
        // (ControlTests/Dashboard), so the figures are read from the page's
        // props rather than from view data. The expected numbers above did not
        // change with it.
        $props = $this->get(route('risk.control-tests.dashboard'))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->component('ControlTests/Dashboard'))
            ->inertiaProps();

        return [
            'totalTests' => $props['totalTests'],
            'scheduledTests' => $props['scheduledTests'],
            'inProgress' => $props['inProgress'],
            'completedTests' => $props['completedTests'],
            'overdueTests' => $props['overdueTests'],
            'passRate' => $props['passRate'],
            'recentIds' => collect($props['recentTests'])->pluck('id')->all(),
            'upcomingIds' => collect($props['upcomingTests'])->pluck('id')->all(),
        ];
    }
}

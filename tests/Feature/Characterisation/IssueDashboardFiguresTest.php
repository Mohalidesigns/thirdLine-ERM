<?php

namespace Tests\Feature\Characterisation;

use App\Models\BusinessUnit;
use App\Models\Issue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The issues dashboard and ageing report (migration Phase 4.4).
 *
 * NEITHER SCREEN HAD EVER BEEN TESTED. The dashboard's `avgDaysToClose` and the
 * closure screen's `avgClosureTime` both used `DATEDIFF()`, which is MySQL-only,
 * so both threw on the SQLite the suite runs on. That is the fourth instance of
 * this pattern in the migration (3.8 and 4.1 used `FIELD()`, 4.3 used
 * `MONTH()`); it is computed in PHP now, which is what makes this file possible.
 *
 * Every other figure was read off IssueController::dashboard()'s compact() keys
 * and is unchanged, EXCEPT the ageing bands — see the note on the band test.
 */
class IssueDashboardFiguresTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $unit;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('issue.view');
        $this->actor->givePermissionTo('issue.view');

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);

        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $attributes */
    private function issue(array $attributes = []): Issue
    {
        $n = ++$this->sequence;

        $issue = Issue::create(array_merge([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id,
            'issue_reference' => sprintf('ISS-%04d', $n),
            'title' => "Issue {$n}",
            'description' => "Fixture issue {$n}",
            'issue_source' => 'internal_audit',
            'issue_category' => 'Control Weakness',
            'issue_status' => 'OPEN',
            'priority' => 'medium',
            'created_by' => $this->actor->id,
        ], $attributes));

        // created_at is what the ageing bands read, and Eloquent stamps it.
        if (isset($attributes['created_at'])) {
            $issue->forceFill(['created_at' => $attributes['created_at']])->saveQuietly();
        }

        return $issue->fresh();
    }

    private function seedIssues(): void
    {
        $this->issue(['issue_status' => 'OPEN', 'priority' => 'critical']);
        $this->issue(['issue_status' => 'IN_PROGRESS', 'priority' => 'high']);
        $this->issue(['issue_status' => 'OVERDUE', 'priority' => 'high', 'remediation_due_date' => now()->subDays(5)]);
        $this->issue(['issue_status' => 'PENDING_CLOSURE', 'priority' => 'low']);
        $this->issue(['issue_status' => 'CANCELLED', 'priority' => 'critical']);
        $this->issue([
            'issue_status' => 'CLOSED',
            'priority' => 'medium',
            'created_at' => now()->subDays(20),
            'closed_at' => now()->subDays(10),
        ]);
        $this->issue(['issue_source' => 'cbn_examination', 'issue_status' => 'OPEN', 'priority' => 'critical']);
    }

    #[Test]
    public function the_status_counts(): void
    {
        $this->seedIssues();

        $this->get(route('risk.issues.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Issues/Dashboard')
                ->where('stats.total', 7)
                ->where('stats.open', 2)
                ->where('stats.inProgress', 1)
                ->where('stats.overdue', 1)
                ->where('stats.pendingClosure', 1)
                ->where('stats.closed', 1)
                // open + in progress, which is what the tile says
                ->where('stats.openIssues', 3));
    }

    /**
     * The priority counts are of OPEN issues: the cancelled critical one does
     * not count, and neither does the closed medium.
     */
    #[Test]
    public function the_priority_counts_exclude_settled_issues(): void
    {
        $this->seedIssues();

        $this->get(route('risk.issues.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.critical', 2)   // not the CANCELLED one
                ->where('stats.high', 2)
                ->where('stats.medium', 0)     // the only medium is CLOSED
                ->where('stats.low', 1));
    }

    /** A CBN finding that is still open is outstanding regulatory work. */
    #[Test]
    public function cbn_findings_counts_open_examination_issues(): void
    {
        $this->seedIssues();

        $this->get(route('risk.issues.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats.cbnFindings', 1));
    }

    /**
     * Twenty days raised to closed. This is the figure `DATEDIFF()` made
     * untestable.
     */
    #[Test]
    public function average_days_to_close(): void
    {
        $this->seedIssues();

        $this->get(route('risk.issues.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats.avgDaysToClose', 10));
    }

    #[Test]
    public function average_days_to_close_is_zero_with_nothing_closed(): void
    {
        $this->issue(['issue_status' => 'OPEN']);

        $this->get(route('risk.issues.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats.avgDaysToClose', 0));
    }

    /**
     * THE BANDS ARE HALF-OPEN NOW, and this is a deliberate correction rather
     * than a carried figure. The Blade version used `>= now()-30` for the first
     * band and `whereBetween(now()-60, now()-30)` for the second, and
     * whereBetween is inclusive at both ends — so an issue created exactly 30
     * days ago was counted TWICE and the buckets could sum to more than the
     * population. Each issue falls in exactly one band here, which is what the
     * chart claims.
     */
    #[Test]
    public function the_ageing_bands_do_not_overlap(): void
    {
        $this->issue(['created_at' => now()->subDays(5)]);
        $this->issue(['created_at' => now()->subDays(30)]);   // the boundary
        $this->issue(['created_at' => now()->subDays(45)]);
        $this->issue(['created_at' => now()->subDays(75)]);
        $this->issue(['created_at' => now()->subDays(200)]);
        $this->issue(['created_at' => now()->subDays(100), 'issue_status' => 'CLOSED']);

        $props = $this->get(route('risk.issues.dashboard'))->assertOk()->viewData('page')['props'];

        $bands = collect($props['ageing'])->pluck('value', 'label');

        $this->assertSame(2, $bands['0-30 days']);   // 5 days and the 30-day one
        $this->assertSame(1, $bands['31-60 days']);
        $this->assertSame(1, $bands['61-90 days']);
        $this->assertSame(1, $bands['90+ days']);    // the CLOSED one is excluded

        $this->assertSame(5, array_sum($bands->all()), 'every open issue lands in exactly one band');
    }

    #[Test]
    public function an_empty_organisation_reports_zeros_not_nulls(): void
    {
        $this->get(route('risk.issues.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total', 0)
                ->where('stats.avgDaysToClose', 0)
                ->has('ageing', 4)
                ->has('overdueIssues', 0));
    }
}

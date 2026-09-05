<?php

namespace Tests\Feature\Characterisation;

use App\Models\RegulatoryCircular;
use App\Models\RegulatoryDeadline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The regulatory compliance dashboard's figures (migration Phase 5.3).
 *
 * Written against the running Blade screen before `RegulatoryDashboardService`
 * existed. This screen is read as a statement of how the institution stands
 * with its regulators, so the figures on it have to mean what they say.
 *
 * ONE FIGURE IS DELIBERATELY CHANGED. `complianceRate` was
 * `$total > 0 ? round($compliant / $total * 100, 1) : 0`, and the tile printed
 * it as "Compliance Rate · 0%" in green. An institution that has recorded no
 * circulars at all therefore read **0% compliance** — a specific claim of
 * total non-compliance made from no data whatsoever, and exactly the defect
 * WP-08 removed from CAR ("0% CAR is a specific, catastrophic claim") and 5.2
 * removed from the ICAAP capital add-on. A rate over an empty register is
 * undefined, not zero.
 */
class RegulatoryDashboardTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('regulatory.view');
        $this->actor->givePermissionTo('regulatory.view');
    }

    /** THE CHANGED FIGURE. No circulars is not 0% compliant. */
    #[Test]
    public function an_empty_register_has_no_compliance_rate(): void
    {
        $figures = $this->dashboard();

        $this->assertSame(0, $figures['totalCirculars']);
        $this->assertNull($figures['complianceRate'], 'A rate over an empty register is undefined, not zero.');
    }

    #[Test]
    public function the_compliance_rate_is_compliant_over_total(): void
    {
        $this->circular(['compliance_status' => 'compliant']);
        $this->circular(['compliance_status' => 'compliant']);
        $this->circular(['compliance_status' => 'partially_compliant']);
        $this->circular(['compliance_status' => 'not_assessed']);

        $figures = $this->dashboard();

        $this->assertSame(4, $figures['totalCirculars']);
        $this->assertEqualsWithDelta(50.0, $figures['complianceRate'], 0.001, 'Two of four.');
        $this->assertSame(2, $figures['pendingCompliance'], 'Partially compliant and not assessed.');
    }

    /**
     * Overdue counts a deadline whose date has passed and which is neither
     * submitted nor marked not applicable.
     */
    #[Test]
    public function overdue_excludes_submitted_and_not_applicable_deadlines(): void
    {
        $this->deadline(['deadline_date' => now()->subWeek(), 'status' => 'upcoming']);
        $this->deadline(['deadline_date' => now()->subWeek(), 'status' => 'submitted']);
        $this->deadline(['deadline_date' => now()->subWeek(), 'status' => 'not_applicable']);
        $this->deadline(['deadline_date' => now()->addWeek(), 'status' => 'upcoming']);

        $this->assertSame(1, $this->dashboard()['overdueCount']);
    }

    /** Upcoming lists the future, unsubmitted deadlines, soonest first. */
    #[Test]
    public function upcoming_deadlines_are_future_unsubmitted_and_ordered(): void
    {
        $this->deadline(['deadline_date' => now()->addMonth(), 'title' => 'Later']);
        $this->deadline(['deadline_date' => now()->addWeek(), 'title' => 'Sooner']);
        $this->deadline(['deadline_date' => now()->addDay(), 'title' => 'Submitted already', 'status' => 'submitted']);
        $this->deadline(['deadline_date' => now()->subDay(), 'title' => 'Past']);

        $titles = collect($this->dashboard()['upcomingDeadlines'])->pluck('title')->all();

        $this->assertSame(['Sooner', 'Later'], $titles);
    }

    /** Per-regulator totals, counted from this organisation's own register. */
    #[Test]
    public function regulator_stats_count_compliance_per_regulator(): void
    {
        $this->circular(['regulator' => 'CBN', 'compliance_status' => 'compliant']);
        $this->circular(['regulator' => 'CBN', 'compliance_status' => 'non_compliant']);
        $this->circular(['regulator' => 'NDIC', 'compliance_status' => 'compliant']);

        $stats = collect($this->dashboard()['regulatorStats'])->keyBy('regulator');

        $this->assertSame(2, (int) $stats['CBN']['total']);
        $this->assertSame(1, (int) $stats['CBN']['compliant_count']);
        $this->assertSame(1, (int) $stats['NDIC']['total']);
        $this->assertSame(1, (int) $stats['NDIC']['compliant_count']);
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        return $this->actingAs($this->actor)
            ->get(route('risk.regulatory.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Regulatory/Dashboard'))
            ->inertiaProps();
    }

    private function circular(array $attributes = []): RegulatoryCircular
    {
        $n = RegulatoryCircular::count() + 1;

        return RegulatoryCircular::create(array_merge([
            'organization_id' => $this->organization->id,
            'regulator' => 'CBN',
            'circular_ref' => sprintf('CIR-%04d', $n),
            'title' => "Circular {$n}",
            'date_issued' => now()->subMonth(),
            'impact_level' => 'medium',
            'compliance_status' => 'not_assessed',
        ], $attributes));
    }

    private function deadline(array $attributes = []): RegulatoryDeadline
    {
        $n = RegulatoryDeadline::count() + 1;

        return RegulatoryDeadline::create(array_merge([
            'organization_id' => $this->organization->id,
            'regulator' => 'CBN',
            'report_type' => 'ORMS',
            'title' => "Deadline {$n}",
            'deadline_date' => now()->addWeek(),
            'frequency' => 'monthly',
            'status' => 'upcoming',
        ], $attributes));
    }
}

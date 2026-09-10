<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaActionPlan;
use App\Models\Rcsa\RcsaAssessment;
use App\Services\Rcsa\RcsaAssessmentService;
use App\Services\Rcsa\RcsaCycleService;
use App\Services\Rcsa\RcsaDashboardService;
use PHPUnit\Framework\Attributes\Test;

/**
 * §10.3 — the v1 reporting surface.
 *
 * The figures are what a Board pack quotes, so each test asserts an ARITHMETIC
 * relationship rather than "the page rendered": the heat map's cells sum to its
 * total, the above-appetite split adds up to the register, the movement counts
 * add up to the lines compared. A dashboard whose numbers merely appear is one
 * nobody can check.
 */
class DashboardTest extends CycleTestCase
{
    private RcsaAssessment $assessment;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (range(1, 4) as $i) {
            $this->publishedRisk(['risk_no' => "RETAIL-R{$i}"]);
        }

        $cycle = $this->makeCycle();
        app(RcsaCycleService::class)->open($cycle, $this->actor);

        $this->assessment = RcsaAssessment::query()->where('business_unit_id', $this->retail->id)->sole();

        $service = app(RcsaAssessmentService::class);
        $lines = $this->assessment->lines()->get();

        // Three above appetite (5 × 5 Not Achieved → 18.75, VERY HIGH), one
        // within (1 × 1 Fully Achieved → 0.00, VERY LOW).
        foreach ($lines->take(3) as $line) {
            $service->apply($line, ['inherent_likelihood' => 5, 'inherent_impact' => 5, 'control_effectiveness' => 'Not Achieved'], $this->actor);
        }

        $service->apply($lines->last(), ['inherent_likelihood' => 1, 'inherent_impact' => 1, 'control_effectiveness' => 'Fully Achieved'], $this->actor);
    }

    private function cycleId(): int
    {
        return (int) $this->assessment->cycle_id;
    }

    #[Test]
    public function the_headline_counts_what_is_in_the_cycle(): void
    {
        $headline = app(RcsaDashboardService::class)->headline($this->cycleId());

        $this->assertSame(4, $headline['risks']);
        $this->assertSame(4, $headline['assessed']);
        $this->assertSame(3, $headline['above_appetite']);
        $this->assertSame(1, $headline['units']);
        $this->assertSame(1, $headline['units_complete']);
    }

    /**
     * The grid is always 25 cells whether or not they are occupied — a heat map
     * with holes reads as missing data rather than as an empty cell.
     */
    #[Test]
    public function the_heat_map_is_a_full_grid_whose_cells_sum_to_its_total(): void
    {
        $map = app(RcsaDashboardService::class)->heatMap($this->cycleId(), 'inherent');

        $this->assertCount(25, $map['cells']);
        $this->assertSame(4, $map['total']);
        $this->assertSame(4, array_sum(array_column($map['cells'], 'count')));

        // Three risks at 5 × 5, one at 1 × 1.
        $corner = collect($map['cells'])->firstWhere(fn ($c) => $c['likelihood'] === 5 && $c['impact'] === 5);
        $this->assertSame(3, $corner['count']);

        $opposite = collect($map['cells'])->firstWhere(fn ($c) => $c['likelihood'] === 1 && $c['impact'] === 1);
        $this->assertSame(1, $opposite['count']);

        // Every empty cell still carries the band it WOULD be, so the grid is
        // coloured end to end.
        $this->assertNotNull(collect($map['cells'])->firstWhere('count', 0)['level']);
    }

    /**
     * The residual basis has no likelihood/impact pair of its own, so the cell
     * is derived. What matters is that it still adds up.
     */
    #[Test]
    public function the_residual_heat_map_moves_risks_without_losing_any(): void
    {
        $service = app(RcsaDashboardService::class);

        $inherent = $service->heatMap($this->cycleId(), 'inherent');
        $residual = $service->heatMap($this->cycleId(), 'residual');

        $this->assertSame($inherent['total'], $residual['total']);
        $this->assertSame(4, array_sum(array_column($residual['cells'], 'count')));

        // The three Not Achieved risks land at 18.75 rather than 25, so they
        // are no longer in the top-right corner.
        $corner = collect($residual['cells'])->firstWhere(fn ($c) => $c['likelihood'] === 5 && $c['impact'] === 5);
        $this->assertSame(0, $corner['count']);
    }

    #[Test]
    public function the_drill_through_returns_the_risks_behind_a_cell(): void
    {
        $lines = app(RcsaDashboardService::class)->drillThrough($this->cycleId(), 'inherent', 5, 5);

        $this->assertCount(3, $lines);
        $this->assertSame($this->assessment->id, $lines[0]['assessment_id']);
        $this->assertNotNull($lines[0]['risk_no']);

        // A cell nobody is in returns nothing rather than everything.
        $this->assertSame([], app(RcsaDashboardService::class)->drillThrough($this->cycleId(), 'inherent', 3, 3));
    }

    #[Test]
    public function above_appetite_by_unit_adds_up_to_the_register(): void
    {
        $rows = app(RcsaDashboardService::class)->aboveAppetiteByUnit($this->cycleId());

        $this->assertCount(1, $rows);
        $this->assertSame($this->retail->name, $rows[0]['label']);
        $this->assertSame(3, $rows[0]['count']);
        $this->assertSame(4, $rows[0]['total']);
    }

    /**
     * In the methodology's own order, best to worst — not by size, so the shape
     * means the same thing every quarter.
     */
    #[Test]
    public function control_effectiveness_keeps_the_methodologys_order(): void
    {
        $rows = app(RcsaDashboardService::class)->controlEffectiveness($this->cycleId());

        $this->assertSame('Fully Achieved', $rows[0]['label']);
        $this->assertSame(1, $rows[0]['count']);
        $this->assertSame('Not Achieved', $rows[count($rows) - 1]['label']);
        $this->assertSame(3, $rows[count($rows) - 1]['count']);

        // A rating nobody used is still shown, at zero — a bar that vanishes is
        // a distribution that cannot be compared with last quarter's.
        $this->assertSame(4, count($rows));
        $this->assertSame(4, array_sum(array_column($rows, 'count')));
    }

    #[Test]
    public function the_completion_tracker_reads_the_stored_percentage_and_the_due_date(): void
    {
        $rows = app(RcsaDashboardService::class)->completionTracker($this->cycleId());

        $this->assertCount(1, $rows);
        $this->assertSame(100, $rows[0]['completion_pct']);
        $this->assertSame(4, $rows[0]['lines_count']);
        $this->assertIsInt($rows[0]['days_to_due']);
    }

    /**
     * The register outlives the cycle, so this panel is not cycle-scoped.
     */
    #[Test]
    public function the_action_plan_panel_buckets_the_open_ones_by_how_late_they_are(): void
    {
        $line = $this->assessment->lines()->first();

        foreach ([-100, -45, -10, 30] as $offset) {
            $line->actionPlans()->create([
                'organization_id' => $this->organization->id,
                'control_to_implement' => 'Something that will be done about it.',
                'owner_id' => $this->actor->id,
                'target_date' => now()->addDays($offset)->toDateString(),
                'status' => RcsaActionPlan::OPEN,
            ]);
        }

        $panel = app(RcsaDashboardService::class)->actionPlans();

        $this->assertSame(4, $panel['total']);
        $this->assertSame(3, $panel['overdue']);

        $ageing = collect($panel['ageing'])->pluck('count', 'label');

        $this->assertSame(1, $ageing['Not yet due']);
        $this->assertSame(1, $ageing['1–30 days late']);
        $this->assertSame(1, $ageing['31–60 days late']);
        $this->assertSame(1, $ageing['Over 90 days late']);
        $this->assertSame(4, $ageing->sum());
    }

    /**
     * A risk with no prior line is NEW, not dropped — a quarter that added
     * forty risks and moved none is a real finding.
     */
    #[Test]
    public function movement_counts_every_line_including_the_new_ones(): void
    {
        $movement = app(RcsaDashboardService::class)->movement($this->cycleId());

        $this->assertSame(4, $movement['improved'] + $movement['worsened'] + $movement['unchanged'] + $movement['new']);
        $this->assertSame(4, $movement['new']);
        $this->assertNull($movement['prior']);
    }

    #[Test]
    public function the_dashboard_renders_and_defaults_to_the_open_cycle(): void
    {
        $this->actingAs($this->actor)
            ->get(route('rcsa.dashboard.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('RcsaDashboard/Index')
                ->where('cycle.id', $this->cycleId())
                ->where('basis', 'inherent')
                ->has('heatMap.cells', 25)
                ->has('topRisks', 4)
                ->where('headline.above_appetite', 3));
    }

    #[Test]
    public function the_dashboard_is_gated_and_flagged(): void
    {
        $stranger = $this->userWith([]);

        $this->actingAs($stranger)->get(route('rcsa.dashboard.index'))->assertForbidden();

        config()->set('features.rcsa_v2', false);
        $this->actingAs($this->actor)->get(route('rcsa.dashboard.index'))->assertNotFound();
    }
}

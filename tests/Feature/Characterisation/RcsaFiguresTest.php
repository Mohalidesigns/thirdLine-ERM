<?php

namespace Tests\Feature\Characterisation;

use App\Models\BusinessUnit;
use App\Models\Control;
use App\Models\Risk;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * CHARACTERISATION — the figures the RCSA screens compute, pinned before
 * Phase 3 moved them out of RcsaController into App\Services\Rcsa.
 *
 * Dashboard: the five assessment-status counts and the completion rate, the
 * per-unit progress with its 80/50 status thresholds, the rating and
 * control-effectiveness distributions, and the top-risk ordering.
 * Matrix: the effectiveness bucket per cell and the coverage percentage.
 * Controls: the KPI counts by effectiveness rating.
 *
 * The worksheet's own arithmetic (residual L×I, the rating band) is pinned
 * by the Rcsa feature tests and is not repeated here.
 *
 * The readers at the bottom are the only part that knows WHERE a figure comes
 * from. They read Blade view data before the port and Inertia props after it;
 * every assertion above is untouched by the move, which is the point of
 * keeping them in one place.
 */
class RcsaFiguresTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $unitA;

    private BusinessUnit $unitB;

    private BusinessUnit $unitC;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('rcsa.view');
        $this->actor->givePermissionTo('rcsa.view');

        $this->unitA = $this->unit('BU-A', 'Alpha');
        $this->unitB = $this->unit('BU-B', 'Bravo');
        $this->unitC = $this->unit('BU-C', 'Charlie');

        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    private function unit(string $code, string $name): BusinessUnit
    {
        return BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function seedDashboard(): void
    {
        // Unit A — three active risks: two assessed inside 12 months, one 14 months ago.
        $this->makeRisk(['risk_code' => 'RK-A1', 'title' => 'A1', 'business_unit_id' => $this->unitA->id, 'last_assessment_date' => now()->subMonths(2), 'residual_rating' => 'High', 'residual_score' => 12]);
        $this->makeRisk(['risk_code' => 'RK-A2', 'title' => 'A2', 'business_unit_id' => $this->unitA->id, 'last_assessment_date' => now()->subMonths(14), 'residual_rating' => 'Medium', 'residual_score' => 6]);
        $this->makeRisk(['risk_code' => 'RK-A6', 'title' => 'A6', 'business_unit_id' => $this->unitA->id, 'last_assessment_date' => now()->subMonth(), 'residual_rating' => 'Low', 'residual_score' => 2]);
        // A closed risk in unit A counts nowhere.
        $this->makeRisk(['risk_code' => 'RK-A5', 'title' => 'A5', 'business_unit_id' => $this->unitA->id, 'status' => 'closed', 'residual_rating' => 'Critical', 'residual_score' => 25]);

        // Unit B — never assessed, and assessed 20 months ago (past the 18-month "in progress" window).
        $this->makeRisk(['risk_code' => 'RK-B3', 'title' => 'B3', 'business_unit_id' => $this->unitB->id, 'last_assessment_date' => null, 'residual_rating' => 'Critical', 'residual_score' => 20]);
        $this->makeRisk(['risk_code' => 'RK-B4', 'title' => 'B4', 'business_unit_id' => $this->unitB->id, 'last_assessment_date' => now()->subMonths(20), 'residual_rating' => 'Low', 'residual_score' => 3]);

        // Unit C — one risk, assessed.
        $this->makeRisk(['risk_code' => 'RK-C7', 'title' => 'C7', 'business_unit_id' => $this->unitC->id, 'last_assessment_date' => now()->subMonths(3), 'residual_rating' => 'Medium', 'residual_score' => 8]);

        foreach (['effective', 'partially_effective', 'ineffective', 'not_tested', null] as $rating) {
            $this->makeControl(['effectiveness_rating' => $rating]);
        }
    }

    #[Test]
    public function the_dashboard_status_counts_and_completion_rate(): void
    {
        $this->seedDashboard();

        $kpis = $this->dashboardKpis();

        $this->assertSame(6, $kpis['total']);
        $this->assertSame(3, $kpis['completed']);      // assessed within 12 months
        $this->assertSame(1, $kpis['in_progress']);    // assessed 12–18 months ago
        $this->assertSame(1, $kpis['not_started']);    // never assessed
        $this->assertSame(3, $kpis['overdue']);        // never, or more than 12 months ago
        $this->assertSame(50, $kpis['completion_rate']); // round(3 / 6 × 100)
    }

    #[Test]
    public function the_dashboard_unit_progress_and_status_thresholds(): void
    {
        $this->seedDashboard();

        $units = $this->dashboardUnits();

        // Ordered by total risks descending.
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], array_column($units, 'name'));

        $this->assertSame([3, 2, 67, 1, 'In Progress'], $this->unitTuple($units[0]));  // 2/3 → 67 → ≥50
        $this->assertSame([2, 0, 0, 1, 'Behind'], $this->unitTuple($units[1]));        // 0/2 → <50
        $this->assertSame([1, 1, 100, 0, 'Completed'], $this->unitTuple($units[2]));   // 1/1 → ≥80
    }

    #[Test]
    public function the_dashboard_distributions(): void
    {
        $this->seedDashboard();

        $this->assertSame(
            ['labels' => ['Critical', 'High', 'Medium', 'Low'], 'values' => [1, 1, 2, 2]],
            $this->dashboardRiskDistribution()
        );

        $this->assertSame(
            ['labels' => ['Effective', 'Partially Effective', 'Ineffective', 'Not Tested'], 'values' => [1, 1, 1, 1]],
            $this->dashboardControlEffectiveness()
        );
    }

    #[Test]
    public function the_dashboard_top_risks_are_ordered_by_rating_then_score(): void
    {
        $this->seedDashboard();

        $this->assertSame(['B3', 'A1', 'C7', 'A2', 'B4', 'A6'], $this->dashboardTopRiskTitles());
    }

    #[Test]
    public function the_matrix_buckets_each_cell_and_computes_coverage(): void
    {
        $riskA = $this->makeRisk(['risk_code' => 'RK-MA', 'title' => 'Matrix A']);
        $riskB = $this->makeRisk(['risk_code' => 'RK-MB', 'title' => 'Matrix B']);

        $cW = $this->makeControl(['control_code' => 'CTL-W', 'effectiveness_rating' => null]);
        $cX = $this->makeControl(['control_code' => 'CTL-X', 'effectiveness_rating' => 'effective']);
        $cY = $this->makeControl(['control_code' => 'CTL-Y', 'effectiveness_rating' => 'partially_effective']);
        $cZ = $this->makeControl(['control_code' => 'CTL-Z', 'effectiveness_rating' => 'ineffective']);

        $this->attachControl($riskA, $cX);
        $this->attachControl($riskA, $cY);
        $this->attachControl($riskA, $cW);
        $this->attachControl($riskB, $cZ);

        $matrix = $this->matrix();

        $this->assertSame(['CTL-W', 'CTL-X', 'CTL-Y', 'CTL-Z'], $matrix['controls']);
        $this->assertSame(['RK-MA', 'RK-MB'], array_keys($matrix['risks']));

        $this->assertSame(['na', 'effective', 'partially', 'na'], $matrix['risks']['RK-MA']['cells']);
        $this->assertSame(75, $matrix['risks']['RK-MA']['coverage']); // 3 of 4 controls

        $this->assertSame(['na', 'na', 'na', 'ineffective'], $matrix['risks']['RK-MB']['cells']);
        $this->assertSame(25, $matrix['risks']['RK-MB']['coverage']);
    }

    #[Test]
    public function the_controls_screen_counts_by_effectiveness(): void
    {
        foreach (['effective', 'partially_effective', 'partially_effective', 'ineffective', 'not_tested', null] as $rating) {
            $this->makeControl(['effectiveness_rating' => $rating]);
        }

        $this->assertSame(
            ['total' => 6, 'effective' => 1, 'partial' => 2, 'ineffective' => 1],
            $this->controlsSummary()
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Readers — the one place that knows where the figures come from */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function props(string $route, array $query = []): array
    {
        return $this->get(route($route, $query))->assertOk()->viewData('page')['props'];
    }

    /** @return array{total:int,completed:int,in_progress:int,not_started:int,overdue:int,completion_rate:int} */
    private function dashboardKpis(): array
    {
        $kpis = $this->props('risk.rcsa.dashboard')['kpis'];

        return [
            'total' => $kpis['total'],
            'completed' => $kpis['completed'],
            'in_progress' => $kpis['inProgress'],
            'not_started' => $kpis['notStarted'],
            'overdue' => $kpis['overdue'],
            'completion_rate' => $kpis['completionRate'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function dashboardUnits(): array
    {
        return $this->props('risk.rcsa.dashboard')['unitProgress'];
    }

    /** @return array{0:int,1:int,2:int,3:int,4:string} total, assessed, progress, high, status */
    private function unitTuple(array $unit): array
    {
        return [
            (int) $unit['totalRisks'],
            (int) $unit['assessed'],
            (int) $unit['progress'],
            (int) $unit['highRisks'],
            (string) $unit['status'],
        ];
    }

    private function dashboardRiskDistribution(): array
    {
        $bands = $this->props('risk.rcsa.dashboard')['riskDistribution'];

        return [
            'labels' => array_column($bands, 'rating'),
            'values' => array_column($bands, 'value'),
        ];
    }

    private function dashboardControlEffectiveness(): array
    {
        $bands = $this->props('risk.rcsa.dashboard')['controlEffectiveness'];

        return [
            'labels' => array_column($bands, 'label'),
            'values' => array_column($bands, 'value'),
        ];
    }

    /** @return list<string> */
    private function dashboardTopRiskTitles(): array
    {
        return array_column($this->props('risk.rcsa.dashboard')['topRisks'], 'title');
    }

    /** @return array{controls: list<string>, risks: array<string, array{cells: list<string>, coverage: int}>} */
    private function matrix(): array
    {
        $props = $this->props('risk.rcsa.matrix');

        return [
            'controls' => array_column($props['controls'], 'code'),
            'risks' => collect($props['risks'])->mapWithKeys(fn (array $risk) => [
                $risk['code'] => [
                    'cells' => array_column($risk['cells'], 'effectiveness'),
                    'coverage' => (int) $risk['coverage'],
                ],
            ])->all(),
        ];
    }

    /** @return array{total:int,effective:int,partial:int,ineffective:int} */
    private function controlsSummary(): array
    {
        return $this->props('risk.rcsa.controls')['summary'];
    }
}

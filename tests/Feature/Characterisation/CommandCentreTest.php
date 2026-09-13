<?php

namespace Tests\Feature\Characterisation;

use App\Models\IcaapAssessment;
use App\Models\KeyRiskIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The Command Centre's figures (migration Phase 5, criterion 7).
 *
 * Written when `DashboardController::index()` — 360 lines of counting inside a
 * controller — moved into `CommandCentreService`. This is the product's landing
 * page and it had no test.
 *
 * ONE FIGURE IS CHANGED, and it is the FOURTH place this same defect has been
 * found: the ICAAP screen, the quantification dashboard (5.2), the board pack
 * (5.4), and here. The capital tile was
 * `number_format((float) ($latestIcaap->car_actual ?? 0), 1)`. `car_actual` is
 * NULLABLE — a preparer records the balance sheet before typing a ratio — and
 * `(float) null` is `0.0`, so an assessment on file without one put **"0.0%"**
 * on the landing page of a bank's risk platform.
 *
 * WP-08 named this number: *"0% CAR is a specific, catastrophic claim about a
 * bank's solvency — the one number the screen must never invent."*
 */
class CommandCentreTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('dashboard.view');
        $this->actor->givePermissionTo('dashboard.view');
    }

    /** THE CHANGED FIGURE. An assessment with no typed ratio is not 0% CAR. */
    #[Test]
    public function an_assessment_without_a_typed_ratio_does_not_report_zero_capital(): void
    {
        $this->assessment(['car_actual' => null, 'total_qualifying_capital_kobo' => null, 'total_rwa_kobo' => null]);

        $this->assertNull($this->dashboard()['carPercentage']);
    }

    /** With a balance sheet on file, the ratio is computed from it. */
    #[Test]
    public function capital_adequacy_is_computed_from_capital_and_rwa(): void
    {
        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,
            'total_rwa_kobo' => 200_000_000_000_000,
        ]);

        $this->assertEqualsWithDelta(13.0, $this->dashboard()['carPercentage'], 0.05);
    }

    /** No assessment at all reports nothing. */
    #[Test]
    public function no_assessment_reports_no_capital_position(): void
    {
        $this->assertNull($this->dashboard()['carPercentage']);
    }

    /** The counters follow the register. */
    #[Test]
    public function the_counters_follow_the_register(): void
    {
        $this->makeRisk(['status' => 'active', 'residual_rating' => 'Critical']);
        $this->makeRisk(['status' => 'active', 'residual_rating' => 'High']);
        $this->makeRisk(['status' => 'archived', 'residual_rating' => 'Critical']);

        $this->kri(['current_status' => 'red']);
        $this->kri(['current_status' => 'green']);

        $data = $this->dashboard();

        $this->assertSame(2, $data['totalActiveRisks'], 'The archived one is out.');
        $this->assertSame(1, $data['criticalRisks']);
        $this->assertSame(1, $data['highRisks']);
        $this->assertSame(1, $data['kriBreaches']);
        $this->assertSame(1, $data['kriStatusCounts']['red']);
        $this->assertSame(1, $data['kriStatusCounts']['green']);
    }

    /**
     * The twelve-month series are twelve buckets, whatever is in them.
     *
     * The page draws these directly, so a short array would silently truncate
     * the chart rather than fail.
     */
    #[Test]
    public function the_trend_series_always_carry_twelve_months(): void
    {
        $data = $this->dashboard();

        $this->assertCount(12, $data['riskTrendData']);
        $this->assertCount(12, $data['lossTrendData']);

        foreach (['critical', 'high', 'medium', 'low'] as $band) {
            $this->assertArrayHasKey($band, $data['riskTrendData'][0]);
        }
    }

    /** The heat map is a 5x5 grid, always. */
    #[Test]
    public function the_heat_map_is_a_five_by_five_grid(): void
    {
        $heatmap = $this->dashboard()['heatmapData'];

        $this->assertCount(5, $heatmap);

        foreach ($heatmap as $row) {
            $this->assertCount(5, $row);
        }
    }

    /**
     * The erm-hq widgets reach the page, or the page copes without them.
     *
     * Criterion 7 composes this page from the seeded dashboard where it can.
     * A tenant whose dashboard has not been published gets an empty layout, and
     * the page falls back to its own heat map rather than rendering nothing —
     * so both props are always present and always arrays.
     */
    #[Test]
    public function the_widget_props_are_always_present(): void
    {
        $data = $this->dashboard();

        $this->assertIsArray($data['widgetLayout']);
        $this->assertIsArray($data['widgetPayloads']);
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        return $this->actingAs($this->actor)
            ->get(route('risk.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard'))
            ->inertiaProps();
    }

    private function assessment(array $attributes = []): IcaapAssessment
    {
        return IcaapAssessment::create(array_merge([
            'organization_id' => $this->organization->id,
            'period' => '2026-Q2',
            'status' => 'draft',
            'prepared_by' => $this->actor->id,
        ], $attributes));
    }

    private function kri(array $attributes = []): KeyRiskIndicator
    {
        $n = KeyRiskIndicator::count() + 1;

        return KeyRiskIndicator::create(array_merge([
            'organization_id' => $this->organization->id,
            'kri_code' => 'KRI-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'name' => 'Indicator '.$n,
            'data_source' => 'Core banking',
            'measurement_frequency' => 'monthly',
            'unit_of_measure' => 'count',
            'created_by' => $this->actor->id,
        ], $attributes));
    }
}

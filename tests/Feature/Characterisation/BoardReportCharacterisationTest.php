<?php

namespace Tests\Feature\Characterisation;

use App\Models\Control;
use App\Models\IcaapAssessment;
use App\Models\TreatmentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The Board report's figures (migration Phase 5.4).
 *
 * Written against the running Blade screen before `BoardReportService` existed,
 * and kept permanently. This is the pack a bank's board reads, so the numbers
 * on it have to survive the extraction unchanged.
 *
 * MOST OF THIS SCREEN IS ALREADY RIGHT, and these assertions defend it. An
 * earlier work package found the appetite chart drawing a flat literal 3 as
 * though it were a Board-approved limit, "Appetite Utilization" computed from
 * risk ratings no appetite statement mentions, a treatment column reading a
 * column that has never existed on `risks`, and "3.2/5" printed as a risk
 * profile when nothing was scored. The rules that replaced them are pinned
 * below.
 *
 * ONE FIGURE IS CHANGED, and it is the reason to read this file. The capital
 * adequacy tile was `round((float) $latestIcaap->car_actual, 1)`. `car_actual`
 * is NULLABLE — a preparer records the balance sheet before typing a ratio —
 * and `(float) null` is `0.0`, so an assessment on file without one rendered
 * **"0%"** in the board pack. The tile's own `unavailable` flag only fired
 * when there was no assessment at all, so it could not catch this. WP-08 named
 * exactly this number: *"0% CAR is a specific, catastrophic claim about a
 * bank's solvency — the one number the screen must never invent."*
 *
 * The ratio is now IcaapService's: computed from stored capital and RWA, with
 * the preparer's typed figure as a stated fallback, which is what the ICAAP
 * screen and all four quantification reports already do. The Board pack and
 * the ICAAP screen could previously disagree about the bank's CAR.
 */
class BoardReportCharacterisationTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('report.view');
        $this->actor->givePermissionTo('report.view');
    }

    /* ------------------------------------------------------------------ */

    /** THE CHANGED FIGURE. An assessment with no typed ratio is not 0% CAR. */
    #[Test]
    public function an_assessment_without_a_typed_ratio_does_not_report_zero_capital(): void
    {
        $this->assessment(['car_actual' => null, 'total_qualifying_capital_kobo' => null, 'total_rwa_kobo' => null]);

        $this->assertNull($this->board()['capitalAdequacyRatio'], '0% CAR is a claim about solvency, not a placeholder.');
    }

    /** With a balance sheet on file the ratio is computed from it. */
    #[Test]
    public function capital_adequacy_is_computed_from_capital_and_rwa(): void
    {
        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,
            'total_rwa_kobo' => 200_000_000_000_000,
            'cbn_minimum_car' => 15.0,
        ]);

        $data = $this->board();

        $this->assertEqualsWithDelta(13.0, $data['capitalAdequacyRatio'], 0.05);
        $this->assertEqualsWithDelta(15.0, $data['capitalAdequacyMinimum'], 0.001);
    }

    /** No assessment at all reports nothing, and no minimum to caption it. */
    #[Test]
    public function no_assessment_reports_no_capital_position(): void
    {
        $data = $this->board();

        $this->assertNull($data['capitalAdequacyRatio']);
        $this->assertNull($data['capitalAdequacyMinimum']);
    }

    /**
     * Control effectiveness is null, not 0, when nothing has been rated.
     *
     * "0%" and "nobody has tested anything" are different statements and only
     * one of them is true.
     */
    #[Test]
    public function control_effectiveness_is_absent_until_something_is_rated(): void
    {
        $this->assertNull($this->board()['controlEffectiveness']);

        $this->makeControl(['effectiveness_rating' => 'effective']);
        $this->makeControl(['effectiveness_rating' => 'partially_effective']);

        $this->assertEqualsWithDelta(50.0, $this->board()['controlEffectiveness'], 0.001);
    }

    /** And the risk profile is absent until something is scored. */
    #[Test]
    public function the_risk_profile_is_absent_until_something_is_scored(): void
    {
        $this->assertNull($this->board()['riskProfileScore'], 'A literal "3.2/5" used to be printed here.');

        $this->makeRisk(['inherent_score' => 20, 'inherent_rating' => 'Critical']);

        $this->assertSame('4/5', $this->board()['riskProfileScore']);
    }

    /**
     * Appetite utilization is measured against DECLARED tolerances, and is
     * absent when no category has declared one.
     *
     * It was previously the share of active risks not rated Critical — a
     * number with no relationship to any appetite statement, printed on a tile
     * captioned "Appetite Utilization".
     */
    #[Test]
    public function appetite_utilization_needs_a_declared_tolerance(): void
    {
        $this->makeRisk(['inherent_score' => 16, 'inherent_rating' => 'Critical']);

        $data = $this->board();

        $this->assertNull($data['appetiteUtilization']);
        $this->assertSame(0, $data['appetiteCategoriesWithTolerance']);
    }

    /**
     * The treatment column is derived from real plans.
     *
     * It used to read `$risk->treatment_status`, a column that has never
     * existed on `risks`, so every critical risk in the product was badged
     * "In Progress" whether or not anyone had opened a plan.
     */
    #[Test]
    public function the_treatment_column_is_derived_from_the_plans_on_the_risk(): void
    {
        $untreated = $this->makeRisk(['inherent_rating' => 'Critical', 'inherent_score' => 20]);
        $overdue = $this->makeRisk(['inherent_rating' => 'Critical', 'inherent_score' => 19]);
        $done = $this->makeRisk(['inherent_rating' => 'Critical', 'inherent_score' => 18]);

        $this->plan($overdue->id, ['status' => 'in_progress', 'target_date' => now()->subWeek()]);
        $this->plan($done->id, ['status' => 'completed', 'target_date' => now()->addWeek()]);

        $statuses = collect($this->board()['criticalRisksForBoard'])
            ->keyBy('id')
            ->map(fn (array $risk) => $risk['derived_treatment_status']);

        $this->assertSame('Not started', $statuses[$untreated->id]);
        $this->assertSame('Overdue', $statuses[$overdue->id]);
        $this->assertSame('Completed', $statuses[$done->id]);
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function board(): array
    {
        return $this->actingAs($this->actor)
            ->get(route('risk.reports.board'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/Board'))
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

    private function plan(int $riskId, array $attributes = []): TreatmentPlan
    {
        $n = TreatmentPlan::count() + 1;

        return TreatmentPlan::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_id' => $riskId,
            'plan_reference' => sprintf('TP-%04d', $n),
            'title' => "Plan {$n}",
            'treatment_strategy' => 'mitigate',
            'status' => 'in_progress',
            'created_by' => $this->actor->id,
        ], $attributes));
    }
}

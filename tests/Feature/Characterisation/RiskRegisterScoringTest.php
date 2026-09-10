<?php

namespace Tests\Feature\Characterisation;

use App\Models\BusinessUnit;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\ScoringProfile;
use App\Support\Scoring\ScoringProfileTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * CHARACTERISATION — Phase 3.2, written against the Blade risk register
 * BEFORE its figures moved into App\Services\Register\RiskRegisterService,
 * then re-pointed at the Inertia props with the same expected numbers.
 *
 * Three things are pinned because a port could plausibly "tidy" any of them
 * into a different number:
 *
 *  1. Which scores the detail screen shows. Inherent comes off the risk and
 *     only the risk — `Risk::inherentScore()` is an accessor that derives
 *     likelihood × impact when the column is null, so it NEVER returns null
 *     and the view's `?? $latestAssessment->inherent_score` fallback can
 *     never fire (there is no `inherent_score` column on `risk_assessments`
 *     either). Residual has no such accessor, so it does fall back to the
 *     latest assessment, and says "pending approval" when it has.
 *  2. Control effectiveness has three sources in priority order: the explicit
 *     percentage on the risk, else the unweighted mean across the mapped
 *     controls, else nothing.
 *  3. Create and update DISAGREE about how the four impact dimensions
 *     collapse into one. store() runs them through RiskScoringService, so a
 *     tenant on an `average` profile gets the mean; update() takes the plain
 *     maximum, ignoring the profile. Editing a risk without touching its
 *     impacts can therefore change its score. That is a scoring decision to
 *     make deliberately, not something the port should quietly unify, so both
 *     are pinned exactly as they are.
 *
 * The ONE figure this phase deliberately changes is called out in
 * an_unassessed_risk_reports_no_score_rather_than_a_derived_low().
 */
class RiskRegisterScoringTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();

        foreach (['risk.view', 'risk.create', 'risk.edit'] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  1. Which scores the detail screen shows */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_detail_screen_shows_the_risks_own_scores(): void
    {
        $risk = $this->makeRisk([
            'inherent_likelihood' => 4,
            'inherent_impact' => 5,
            'inherent_score' => 20,
            'inherent_rating' => 'Critical',
            'residual_score' => 9,
            'residual_rating' => 'Medium',
        ]);

        $this->assertDetailFigures($risk, [
            'inherentScore' => 20,
            'inherentRating' => 'Critical',
            'residualScore' => 9,
            'residualRating' => 'Medium',
            'pendingApproval' => false,
        ]);
    }

    #[Test]
    public function the_residual_score_falls_back_to_the_latest_assessment_and_says_so(): void
    {
        $risk = $this->makeRisk([
            'inherent_likelihood' => 4,
            'inherent_impact' => 4,
            'inherent_score' => 16,
            'inherent_rating' => 'High',
            'residual_score' => null,
            'residual_rating' => null,
        ]);

        // Two assessments; the screen orders by assessment_date descending and
        // reads the first, so the June one is the one that shows.
        $this->makeAssessment($risk, '2026-01-15', 4, 'Low');
        $this->makeAssessment($risk, '2026-06-15', 12, 'Medium');

        $this->assertDetailFigures($risk, [
            'inherentScore' => 16,
            'inherentRating' => 'High',
            'residualScore' => 12,
            'residualRating' => 'Medium',
            // The risk has no residual score of its own but the assessment
            // does: the screen labels that gap rather than showing the
            // assessment's number as though it had been approved onto the risk.
            'pendingApproval' => true,
        ]);
    }

    #[Test]
    public function the_inherent_score_never_falls_back_to_an_assessment(): void
    {
        $risk = $this->makeRisk([
            'inherent_likelihood' => 2,
            'inherent_impact' => 3,
            'inherent_score' => null,
            'inherent_rating' => null,
        ]);

        $this->makeAssessment($risk, '2026-06-15', 12, 'Medium');

        // Not the assessment's numbers: the accessor's derivation of the
        // risk's own likelihood × impact, 2 × 3 = 6, which bands as Medium.
        $this->assertDetailFigures($risk, [
            'inherentScore' => 6,
            'inherentRating' => 'Medium',
        ]);
    }

    #[Test]
    public function an_unassessed_risk_reports_no_score_rather_than_a_derived_low(): void
    {
        // DELIBERATE CHANGE, the only one in this file. The Blade screen read
        // Risk::inherent_score, whose accessor multiplies two nulls to 0 and
        // bands that as "Low", and printed "0/25 · Low" on the KPI tile of a
        // risk nobody had scored. Nothing measured that. The port keeps the
        // accessor untouched — grids, widgets and exports all read it — and
        // draws the tile as not-assessed when the risk carries neither a
        // stored score nor a likelihood and impact to derive one from.
        $risk = $this->makeRisk([
            'inherent_likelihood' => null,
            'inherent_impact' => null,
            'inherent_score' => null,
            'inherent_rating' => null,
            'residual_score' => null,
            'residual_rating' => null,
        ]);

        $this->assertDetailFigures($risk, [
            'inherentScore' => null,
            'inherentRating' => null,
            'residualScore' => null,
            'residualRating' => null,
            'pendingApproval' => false,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  2. Control effectiveness, in priority order */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function control_effectiveness_prefers_the_explicit_percentage_on_the_risk(): void
    {
        $risk = $this->makeRisk(['control_effectiveness_pct' => 72.4]);

        // A mapped control that would average to something else is ignored
        // while the explicit percentage is set.
        $this->attachControl($risk, $this->makeControl(['effectiveness_rating' => 'ineffective']));

        $this->assertSame(72, $this->detailFigures($risk)['controlEffectivenessPct']);
    }

    #[Test]
    public function control_effectiveness_averages_the_mapped_controls(): void
    {
        $risk = $this->makeRisk(['control_effectiveness_pct' => null]);

        // The five-band map in config/risk.php: effective 95, partially 60.
        $this->attachControl($risk, $this->makeControl(['effectiveness_rating' => 'effective']));
        $this->attachControl($risk, $this->makeControl(['effectiveness_rating' => 'partially_effective']));

        // Unweighted mean of the per-control percentages — the pivot's
        // control_weight is NOT consulted here, unlike ControlEffectivenessService.
        $this->assertSame(78, $this->detailFigures($risk)['controlEffectivenessPct']);

        $this->assertSame('2 mapped controls', $this->detailFigures($risk)['controlEffectivenessSubtitle']);
    }

    #[Test]
    public function control_effectiveness_has_no_value_when_nothing_is_mapped(): void
    {
        $risk = $this->makeRisk(['control_effectiveness_pct' => null]);

        $figures = $this->detailFigures($risk);

        // The Blade screen printed "0%" here, beside the subtitle explaining
        // that there was nothing to compute it from. Same subtitle, no number.
        $this->assertNull($figures['controlEffectivenessPct']);
        $this->assertSame('No controls mapped', $figures['controlEffectivenessSubtitle']);
    }

    /* ------------------------------------------------------------------ */
    /*  3. Create and update collapse impacts differently */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function create_collapses_the_impact_dimensions_through_the_scoring_profile(): void
    {
        $this->averageAggregationProfile();

        $this->actingAs($this->actor)
            ->post(route('risk.register.store'), $this->riskPayload([
                'inherent_likelihood' => 4,
                'impact_financial' => 5,
                'impact_operational' => 3,
                'impact_reputational' => 2,
                'impact_regulatory' => 2,
            ]))
            ->assertRedirect();

        $risk = Risk::where('title', 'Characterisation risk')->firstOrFail();

        // mean(5, 3, 2, 2) = 3 — NOT the maximum, 5.
        $this->assertSame(3, (int) $risk->inherent_impact);
        $this->assertSame(12, (int) $risk->inherent_score);
        $this->assertSame('High', $risk->inherent_rating);
    }

    #[Test]
    public function update_takes_the_plain_maximum_and_ignores_the_profile(): void
    {
        $this->averageAggregationProfile();

        $risk = $this->makeRisk([
            'business_unit_id' => $this->unit->id,
            'inherent_likelihood' => 4,
            'inherent_impact' => 3,
            'inherent_score' => 12,
            'inherent_rating' => 'High',
        ]);

        $this->actingAs($this->actor)
            ->put(route('risk.register.update', $risk), $this->riskPayload([
                'status' => 'active',
                'risk_source' => 'Audit Finding',
                'inherent_likelihood' => 4,
                'impact_financial' => 5,
                'impact_operational' => 3,
                'impact_reputational' => 2,
                'impact_regulatory' => 2,
            ]))
            ->assertRedirect();

        $risk->refresh();

        // max(5, 3, 2, 2) = 5 — the same inputs the create path scored as 3.
        $this->assertSame(5, (int) $risk->inherent_impact);
        $this->assertSame(20, (int) $risk->inherent_score);
        $this->assertSame('Critical', $risk->inherent_rating);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * The figures the detail screen derives, however it is rendered.
     *
     * While the screen was Blade these came out of an `@php` block in the view
     * and had to be read back off the rendered KPI tiles; the port moves them
     * into one named Inertia prop and this helper reads that instead. The
     * expectations in the tests above did not change with it.
     *
     * @return array<string, mixed>
     */
    private function detailFigures(Risk $risk): array
    {
        return $this->actingAs($this->actor)
            ->get(route('risk.register.show', $risk))
            ->assertOk()
            ->inertiaProps()['metrics'];
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertDetailFigures(Risk $risk, array $expected): void
    {
        $figures = $this->detailFigures($risk);

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $figures[$key], "metrics.{$key}");
        }
    }

    private function makeAssessment(
        Risk $risk,
        string $date,
        ?int $residualScore,
        ?string $residualRating,
    ): RiskAssessment {
        return RiskAssessment::create([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'assessment_type' => 'periodic',
            'assessment_date' => $date,
            'assessor_id' => $this->actor->id,
            'residual_score' => $residualScore,
            'residual_rating' => $residualRating,
            'status' => 'approved',
        ]);
    }

    /** A tenant whose profile means the impact dimensions rather than maxing them. */
    private function averageAggregationProfile(): ScoringProfile
    {
        $profile = ScoringProfile::create(ScoringProfileTemplates::default('NGN', [
            'organization_id' => $this->organization->id,
            'code' => 'averaging',
            'is_system' => false,
            'impact_aggregation' => 'average',
        ]));

        ScoringProfile::flushResolutionCache();

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function riskPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Characterisation risk',
            'description' => 'Pinned by tests/Feature/Characterisation/RiskRegisterScoringTest.',
            'category_id' => $this->category->id,
            'business_unit_id' => $this->unit->id,
            'risk_owner_id' => $this->actor->id,
            'inherent_likelihood' => 3,
            'impact_financial' => 3,
            'impact_operational' => 3,
            'impact_reputational' => 3,
            'impact_regulatory' => 3,
        ], $overrides);
    }
}

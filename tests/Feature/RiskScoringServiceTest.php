<?php

namespace Tests\Feature;

use App\Models\RiskAssessment;
use App\Models\ScoringProfile;
use App\Services\RiskScoringService;
use App\Support\RiskCalculationSettings;
use App\Support\Scoring\ScoringProfileTemplates;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-01 TASK 6 — RiskScoringService.
 *
 * The fourth calculation service. The other three are covered by
 * tests/Feature/Characterisation/; this one changed shape in TASK 2 (strategic
 * impact, configurable aggregation, sole owner of the rating bands) so its
 * coverage lives here rather than as a characterisation of the old behaviour.
 */
class RiskScoringServiceTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private RiskScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        RiskCalculationSettings::flush();
        ScoringProfile::flushResolutionCache();
        $this->service = new RiskScoringService;
    }

    protected function tearDown(): void
    {
        RiskCalculationSettings::flush();
        ScoringProfile::flushResolutionCache();
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  calculateScore */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_score_is_likelihood_times_impact(): void
    {
        $this->assertSame(1, $this->service->calculateScore(1, 1));
        $this->assertSame(12, $this->service->calculateScore(3, 4));
        $this->assertSame(25, $this->service->calculateScore(5, 5));
    }

    #[Test]
    public function a_zero_on_either_axis_scores_zero(): void
    {
        $this->assertSame(0, $this->service->calculateScore(0, 5));
        $this->assertSame(0, $this->service->calculateScore(5, 0));
    }

    /* ------------------------------------------------------------------ */
    /*  calculateRating — every band boundary */
    /* ------------------------------------------------------------------ */

    #[Test]
    #[DataProvider('ratingBoundaries')]
    public function the_rating_bands_hold_at_every_boundary(int $score, string $expected): void
    {
        $this->assertSame($expected, $this->service->calculateRating($score));
    }

    public static function ratingBoundaries(): array
    {
        return [
            // Low: 1-4
            'zero' => [0, 'Low'],
            'one' => [1, 'Low'],
            'top of Low' => [4, 'Low'],
            // Medium: 5-11. 5 is the score the old duplicate implementations
            // disagreed about — the model accessor called it Low.
            'bottom of Medium' => [5, 'Medium'],
            'top of Medium' => [11, 'Medium'],
            // High: 12-19
            'bottom of High' => [12, 'High'],
            'top of High' => [19, 'High'],
            // Critical: 20+
            'bottom of Critical' => [20, 'Critical'],
            'maximum on a 5x5 matrix' => [25, 'Critical'],
            'beyond the matrix' => [100, 'Critical'],
        ];
    }

    #[Test]
    public function a_negative_score_is_low_rather_than_an_error(): void
    {
        // Not reachable through the UI, but a rating function that throws on
        // unexpected input takes a dashboard down; Low is the safe answer.
        $this->assertSame('Low', $this->service->calculateRating(-5));
    }

    /* ------------------------------------------------------------------ */
    /*  calculateResidualScore */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_residual_score_discounts_the_inherent_score_by_control_effectiveness(): void
    {
        $this->assertSame(10, $this->service->calculateResidualScore(20, 50.0));
        $this->assertSame(5, $this->service->calculateResidualScore(20, 75.0));
    }

    #[Test]
    public function no_controls_leaves_the_residual_equal_to_the_inherent_score(): void
    {
        $this->assertSame(20, $this->service->calculateResidualScore(20, 0.0));
    }

    #[Test]
    public function the_residual_score_floors_at_one(): void
    {
        // No control removes a risk entirely, so a fully effective control set
        // still leaves 1 rather than 0 — otherwise the risk drops off the
        // register.
        $this->assertSame(1, $this->service->calculateResidualScore(20, 100.0));
        $this->assertSame(1, $this->service->calculateResidualScore(1, 99.9));
    }

    #[Test]
    public function the_residual_score_rounds_to_the_nearest_whole_number(): void
    {
        // 25 × (1 − 0.37) = 15.75 → 16
        $this->assertSame(16, $this->service->calculateResidualScore(25, 37.0));
    }

    #[Test]
    public function an_effectiveness_above_one_hundred_percent_still_floors_at_one(): void
    {
        // Bad data must not produce a negative residual score.
        $this->assertSame(1, $this->service->calculateResidualScore(20, 150.0));
    }

    /* ------------------------------------------------------------------ */
    /*  calculateImpact — nulls, extremes */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_dimension_at_maximum_scores_five(): void
    {
        $this->assertSame(5, $this->service->calculateMaxImpact(5, 5, 5, 5, 5, $this->organization->id));
    }

    #[Test]
    public function all_null_dimensions_score_zero(): void
    {
        $this->assertSame(0, $this->service->calculateMaxImpact(null, null, null, null, null, $this->organization->id));
    }

    #[Test]
    public function a_single_scored_dimension_carries_the_impact(): void
    {
        $this->assertSame(4, $this->service->calculateMaxImpact(null, 4, null, null, null, $this->organization->id));
    }

    #[Test]
    public function an_unknown_dimension_key_is_ignored(): void
    {
        $this->assertSame(3, $this->service->calculateImpact([
            'financial' => 3,
            'reputation' => 5,   // misspelled — not one of the five dimensions
        ], $this->organization->id));
    }

    /*
     * The three aggregation tests below used to configure
     * organizations.settings->risk. WP-05 TASK 3 moved that configuration into
     * the scoring profile, which is now the single definition of how a score
     * is arrived at — the settings key seeds a profile at provisioning time
     * and is not consulted by any calculation afterwards. The behaviour under
     * test is unchanged; only the surface that configures it has moved.
     */

    #[Test]
    public function weighted_aggregation_respects_the_configured_weights(): void
    {
        $this->useProfile([
            'impact_aggregation' => 'weighted',
            'dimension_weights' => ['financial' => 4.0, 'operational' => 1.0],
        ]);

        // (4 × 5 + 1 × 1) / 5 = 4.2 → 4
        $this->assertSame(4, $this->service->calculateImpact([
            'financial' => 5,
            'operational' => 1,
        ], $this->organization->id));
    }

    #[Test]
    public function all_zero_weights_fall_back_to_the_worst_dimension(): void
    {
        // Weighting everything to zero is a configuration mistake, not an
        // instruction to report no impact.
        $this->useProfile([
            'impact_aggregation' => 'weighted',
            'dimension_weights' => array_fill_keys(
                ScoringProfileTemplates::DEFAULT_IMPACT_DIMENSIONS,
                0.0
            ),
        ]);

        $this->assertSame(5, $this->service->calculateImpact(
            ['financial' => 5, 'operational' => 2],
            $this->organization->id
        ));
    }

    #[Test]
    public function worst_two_uses_the_single_dimension_when_only_one_is_scored(): void
    {
        $this->useProfile(['impact_aggregation' => 'worst_two']);

        $this->assertSame(3, $this->service->calculateImpact(['financial' => 3], $this->organization->id));
    }

    #[Test]
    public function worst_two_averages_the_two_highest_dimensions(): void
    {
        $this->useProfile(['impact_aggregation' => 'worst_two']);

        // (5 + 4) / 2 = 4.5 → 5
        $this->assertSame(5, $this->service->calculateImpact(
            ['financial' => 5, 'operational' => 4, 'reputational' => 1],
            $this->organization->id
        ));
    }

    #[Test]
    public function average_aggregation_means_the_mean_of_the_scored_dimensions(): void
    {
        $this->useProfile(['impact_aggregation' => 'average']);

        // (5 + 2) / 2 = 3.5 → 4. The unscored three are excluded, not zeroed.
        $this->assertSame(4, $this->service->calculateImpact(
            ['financial' => 5, 'operational' => 2],
            $this->organization->id
        ));
    }

    /**
     * Give this organisation its own profile, overriding the seeded 5×5.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function useProfile(array $overrides): ScoringProfile
    {
        $profile = ScoringProfile::create(ScoringProfileTemplates::default('NGN', array_merge([
            'organization_id' => $this->organization->id,
            'code' => 'test-profile',
            'is_system' => false,
        ], $overrides)));

        ScoringProfile::flushResolutionCache();

        return $profile;
    }

    /* ------------------------------------------------------------------ */
    /*  updateRiskFromAssessment */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_assessment_writes_scores_and_ratings_onto_the_risk(): void
    {
        $risk = $this->makeRisk();
        $assessment = $this->makeAssessment($risk, [
            'likelihood_score' => 4,
            'impact_financial' => 5,
            'impact_operational' => 3,
            'impact_reputational' => 2,
            'impact_regulatory' => 1,
            'impact_strategic' => 1,
            'residual_likelihood' => 2,
            'residual_impact' => 3,
        ]);

        $updated = $this->service->updateRiskFromAssessment($risk, $assessment);

        $this->assertSame(4, $updated->inherent_likelihood);
        $this->assertSame(5, $updated->inherent_impact);
        $this->assertSame(20, $updated->inherent_score);
        $this->assertSame('Critical', $updated->inherent_rating);
        $this->assertSame(6, $updated->residual_score);
        $this->assertSame('Medium', $updated->residual_rating);
    }

    #[Test]
    public function the_strategic_dimension_can_drive_the_inherent_score_on_its_own(): void
    {
        // It was excluded from this path, so an assessment whose only severe
        // dimension was strategic was scored as if it were not severe at all.
        $risk = $this->makeRisk();
        $assessment = $this->makeAssessment($risk, [
            'likelihood_score' => 5,
            'impact_financial' => 1,
            'impact_operational' => 1,
            'impact_reputational' => 1,
            'impact_regulatory' => 1,
            'impact_strategic' => 5,
        ]);

        $updated = $this->service->updateRiskFromAssessment($risk, $assessment);

        $this->assertSame(5, $updated->inherent_impact);
        $this->assertSame(25, $updated->inherent_score);
        $this->assertSame('Critical', $updated->inherent_rating);
    }

    #[Test]
    public function an_assessment_without_residual_scores_leaves_them_null(): void
    {
        $risk = $this->makeRisk();
        $assessment = $this->makeAssessment($risk, [
            'likelihood_score' => 3,
            'impact_financial' => 3,
            'residual_likelihood' => null,
            'residual_impact' => null,
        ]);

        $updated = $this->service->updateRiskFromAssessment($risk, $assessment);

        $this->assertNull($updated->residual_score);
        $this->assertNull($updated->residual_rating);
    }

    /* ------------------------------------------------------------------ */
    /*  Matrix and distribution */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_matrix_is_a_full_five_by_five_grid_even_with_no_risks(): void
    {
        $matrix = $this->service->getRiskMatrix($this->organization->id);

        $this->assertCount(5, $matrix);

        for ($likelihood = 1; $likelihood <= 5; $likelihood++) {
            $this->assertCount(5, $matrix[$likelihood]);

            for ($impact = 1; $impact <= 5; $impact++) {
                $cell = $matrix[$likelihood][$impact];
                $this->assertSame(0, $cell['count']);
                $this->assertSame($likelihood * $impact, $cell['score']);
                $this->assertSame($this->service->calculateRating($likelihood * $impact), $cell['rating']);
            }
        }
    }

    #[Test]
    public function risks_land_in_the_matrix_cell_matching_their_scores(): void
    {
        $risk = $this->makeRisk([
            'inherent_likelihood' => 4,
            'inherent_impact' => 3,
            'status' => 'active',
        ]);

        $matrix = $this->service->getRiskMatrix($this->organization->id);

        $this->assertSame(1, $matrix[4][3]['count']);
        $this->assertSame($risk->risk_code, $matrix[4][3]['risks'][0]['risk_code']);
        $this->assertSame(0, $matrix[3][4]['count'], 'likelihood and impact must not be transposed');
    }

    #[Test]
    public function the_residual_matrix_reads_the_residual_scores(): void
    {
        $this->makeRisk([
            'inherent_likelihood' => 5,
            'inherent_impact' => 5,
            'residual_likelihood' => 2,
            'residual_impact' => 2,
            'status' => 'active',
        ]);

        $matrix = $this->service->getRiskMatrix($this->organization->id, 'residual');

        $this->assertSame(1, $matrix[2][2]['count']);
        $this->assertSame(0, $matrix[5][5]['count']);
    }

    #[Test]
    public function out_of_range_scores_are_clamped_into_the_matrix_rather_than_crashing_it(): void
    {
        // smallInteger columns will accept a 9 that no UI can produce, and an
        // organisation that moves from 5×5 to 4×4 leaves real 5s behind. The
        // heat map must not fatal on either.
        //
        // WP-05 TASK 3 changed what "must not fatal" means here. This test
        // used to assert the risk was dropped from the grid entirely, which
        // kept the page up at the cost of a heat map that claimed to show
        // every active risk while quietly omitting one. Clamping puts it in
        // the top-right cell, where it is both visible and honest.
        $this->makeRisk([
            'inherent_likelihood' => 9,
            'inherent_impact' => 9,
            'status' => 'active',
        ]);

        $matrix = $this->service->getRiskMatrix($this->organization->id);

        $total = 0;
        foreach ($matrix as $row) {
            foreach ($row as $cell) {
                $total += $cell['count'];
            }
        }

        $this->assertSame(1, $total, 'the out-of-range risk should still be counted somewhere');
        $this->assertSame(1, $matrix[5][5]['count'], 'and it should be clamped into the top-right cell');
    }

    #[Test]
    public function the_matrix_excludes_risks_that_are_not_active(): void
    {
        $this->makeRisk(['inherent_likelihood' => 3, 'inherent_impact' => 3, 'status' => 'draft']);

        $this->assertSame(0, $this->service->getRiskMatrix($this->organization->id)[3][3]['count']);
    }

    #[Test]
    public function the_distribution_counts_each_rating(): void
    {
        $this->makeRisk(['inherent_rating' => 'Critical', 'status' => 'active']);
        $this->makeRisk(['inherent_rating' => 'Critical', 'status' => 'active']);
        $this->makeRisk(['inherent_rating' => 'Low', 'status' => 'active']);
        $this->makeRisk(['inherent_rating' => 'High', 'status' => 'draft']);

        $this->assertSame([
            'Critical' => 2,
            'High' => 0,
            'Medium' => 0,
            'Low' => 1,
        ], $this->service->getRiskDistribution($this->organization->id));
    }

    #[Test]
    public function the_matrix_and_distribution_ignore_another_organizations_risks(): void
    {
        $other = \App\Models\Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHR',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $this->makeRisk(['inherent_likelihood' => 3, 'inherent_impact' => 3, 'inherent_rating' => 'Medium', 'status' => 'active']);

        $this->assertSame(0, $this->service->getRiskMatrix($other->id)[3][3]['count']);
        $this->assertSame(0, $this->service->getRiskDistribution($other->id)['Medium']);
    }

    /* ------------------------------------------------------------------ */

    private function makeAssessment(\App\Models\Risk $risk, array $attributes): RiskAssessment
    {
        static $n = 0;
        $n++;

        return RiskAssessment::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'assessment_type' => 'periodic',
            'assessment_date' => now()->toDateString(),
            'assessor_id' => $this->actor->id,
            'likelihood_score' => 3,
            'impact_financial' => 3,
            'rationale' => 'Fixture assessment '.$n,
        ], $attributes));
    }
}

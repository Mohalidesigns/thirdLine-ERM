<?php

namespace Tests\Feature\Characterisation;

use App\Models\RiskAssessment;
use App\Models\ScoringProfile;
use App\Services\AssessmentChainService;
use App\Services\RiskScoringService;
use App\Support\RiskCalculationSettings;
use App\Support\Scoring\ScoringProfileTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * CHARACTERISATION — migration Phase 3.3.
 *
 * Pins the figures AssessmentChainService produces for steps 4, 5, 7 and 8 of
 * the chain as they stood before the preview endpoint was added, so that the
 * live preview on Assessments/Create can be shown to equal them and so that
 * a later refactor of the service cannot move a residual score unnoticed.
 *
 * The expected values are written out as numbers, not derived by calling the
 * service: the whole point is that the test disagrees with the service if the
 * service changes.
 *
 *   inherent 20 (L4 × I5), one preventive control at 80%
 *     default formula  → target 4, reduction 0.20, split 0.85
 *                        L = round(4 × 0.20^0.85) = 1, I = round(5 × 0.20^0.15) = 4 → 4, Low
 *   the same, detective → split 0.15 → L 3, I 1 → 3, Low
 *   inherent 25, preventive 60% → target 10 → L 2, I 4 → 8, Medium
 *   inherent 12 (L3 × I4), detective 37% → target 8 → L 3, I 3 → 9, Medium
 *   custom formula `inherent * (1 - effectiveness / 200)`, inherent 20 at 80%
 *                     → target 12, reduction 0.60 → L 3, I 5 → 15, High
 */
class AssessmentChainServiceTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private AssessmentChainService $chain;

    private RiskScoringService $scoring;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        RiskCalculationSettings::flush();
        ScoringProfile::flushResolutionCache();

        $this->scoring = new RiskScoringService;
        $this->chain = new AssessmentChainService($this->scoring);
    }

    protected function tearDown(): void
    {
        RiskCalculationSettings::flush();
        ScoringProfile::flushResolutionCache();
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Step 8 — deriveResidual() on the platform default profile */
    /* ------------------------------------------------------------------ */

    #[Test]
    #[DataProvider('residualScenarios')]
    public function residual_risk_is_derived_exactly_as_it_was(
        int $likelihood,
        int $impact,
        float $likelihoodAssurance,
        float $impactAssurance,
        array $expected,
    ): void {
        $overall = $likelihoodAssurance > 0 ? $likelihoodAssurance : $impactAssurance;

        $derived = $this->chain->deriveResidual(
            $likelihood,
            $impact,
            $likelihood * $impact,
            [
                'overall' => $overall,
                'likelihood' => $likelihoodAssurance > 0 ? $likelihoodAssurance : null,
                'impact' => $impactAssurance > 0 ? $impactAssurance : null,
                'rated' => 1,
                'total' => 1,
                'unrated_key_controls' => 0,
            ],
        );

        $this->assertNotNull($derived);
        $this->assertSame($expected['target'], $derived['target']);
        $this->assertSame($expected['likelihood'], $derived['likelihood']);
        $this->assertSame($expected['impact'], $derived['impact']);
        $this->assertSame($expected['score'], $derived['score']);
        $this->assertSame($expected['rating'], $derived['rating']);
        $this->assertSame($expected['split'], $derived['split']);
    }

    public static function residualScenarios(): array
    {
        return [
            'L4 I5, preventive 80%' => [4, 5, 80.0, 0.0, ['target' => 4, 'likelihood' => 1, 'impact' => 4, 'score' => 4, 'rating' => 'Low', 'split' => 0.85]],
            'L4 I5, detective 80%' => [4, 5, 0.0, 80.0, ['target' => 4, 'likelihood' => 3, 'impact' => 1, 'score' => 3, 'rating' => 'Low', 'split' => 0.15]],
            'L5 I5, preventive 60%' => [5, 5, 60.0, 0.0, ['target' => 10, 'likelihood' => 2, 'impact' => 4, 'score' => 8, 'rating' => 'Medium', 'split' => 0.85]],
            'L3 I4, detective 37%' => [3, 4, 0.0, 37.0, ['target' => 8, 'likelihood' => 3, 'impact' => 3, 'score' => 9, 'rating' => 'Medium', 'split' => 0.15]],
        ];
    }

    #[Test]
    public function an_even_split_applies_when_both_axes_carry_equal_assurance(): void
    {
        $derived = $this->chain->deriveResidual(4, 4, 16, [
            'overall' => 60.0, 'likelihood' => 60.0, 'impact' => 60.0,
            'rated' => 2, 'total' => 2, 'unrated_key_controls' => 0,
        ]);

        // target round(16 × 0.4) = 6, reduction 0.375, split 0.5:
        // L = round(4 × 0.375^0.5) = round(2.449) = 2, I likewise 2 → 4, Low.
        $this->assertSame(6, $derived['target']);
        $this->assertSame(0.5, $derived['split']);
        $this->assertSame(2, $derived['likelihood']);
        $this->assertSame(2, $derived['impact']);
        $this->assertSame(4, $derived['score']);
        $this->assertSame('Low', $derived['rating']);
    }

    #[Test]
    public function nothing_rated_means_no_residual(): void
    {
        $this->assertNull($this->chain->deriveResidual(4, 5, 20, [
            'overall' => null, 'likelihood' => null, 'impact' => null,
            'rated' => 0, 'total' => 1, 'unrated_key_controls' => 0,
        ]));
    }

    /* ------------------------------------------------------------------ */
    /*  Step 8 on a tenant-configured residual formula */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_custom_residual_formula_moves_the_target_and_the_cell(): void
    {
        $profile = ScoringProfile::create(ScoringProfileTemplates::default('NGN', [
            'organization_id' => $this->organization->id,
            'code' => 'halving',
            'residual_formula' => 'inherent * (1 - effectiveness / 200)',
            'is_system' => false,
        ]));
        ScoringProfile::flushResolutionCache();

        $derived = $this->chain->deriveResidual(4, 5, 20, [
            'overall' => 80.0, 'likelihood' => 80.0, 'impact' => null,
            'rated' => 1, 'total' => 1, 'unrated_key_controls' => 0,
        ], $profile);

        // 20 × (1 − 80/200) = 12, reduction 0.6, split 0.85:
        // L = round(4 × 0.6^0.85) = round(2.59) = 3, I = round(5 × 0.6^0.15) = round(4.63) = 5.
        $this->assertSame(12, $derived['target']);
        $this->assertSame(3, $derived['likelihood']);
        $this->assertSame(5, $derived['impact']);
        $this->assertSame(15, $derived['score']);
        $this->assertSame('High', $derived['rating']);

        // The same inputs on the default formula land somewhere else entirely,
        // which is what the old Alpine preview could not show a tenant.
        $default = $this->chain->deriveResidual(4, 5, 20, [
            'overall' => 80.0, 'likelihood' => 80.0, 'impact' => null,
            'rated' => 1, 'total' => 1, 'unrated_key_controls' => 0,
        ], ScoringProfile::fallback());

        $this->assertSame(4, $default['score']);
    }

    /* ------------------------------------------------------------------ */
    /*  Step 7 — effectiveness() over rated controls */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function effectiveness_is_split_by_the_axis_each_control_acts_on(): void
    {
        $risk = $this->makeRisk();
        $assessment = $this->assessmentFor($risk, 4, 5);

        $preventive = $this->makeControl(['control_type' => 'preventive']);
        $detective = $this->makeControl(['control_type' => 'detective']);
        $unrated = $this->makeControl(['control_type' => 'corrective']);

        $this->attachControl($risk, $preventive, 3.0, true);
        $this->attachControl($risk, $detective, 1.0);
        $this->attachControl($risk, $unrated, 1.0, true);

        $rows = $this->chain->syncControls($assessment, [
            $preventive->id => ['design_effectiveness' => 'effective', 'operating_effectiveness' => 'mostly_effective'],
            $detective->id => ['design_effectiveness' => 'partially_effective', 'operating_effectiveness' => 'partially_effective'],
            $unrated->id => ['design_effectiveness' => null, 'operating_effectiveness' => null],
        ]);

        $effectiveness = $this->chain->effectiveness($rows->load('control'));

        // Weaker of design/operating: preventive 80 (weight 3), detective 60 (weight 1).
        // (3 × 80 + 1 × 60) / 4 = 75.
        $this->assertSame(75.0, $effectiveness['overall']);
        $this->assertSame(80.0, $effectiveness['likelihood']);
        $this->assertSame(60.0, $effectiveness['impact']);
        $this->assertSame(2, $effectiveness['rated']);
        $this->assertSame(3, $effectiveness['total']);
        $this->assertSame(1, $effectiveness['unrated_key_controls']);
    }

    /* ------------------------------------------------------------------ */
    /*  Steps 4-5 — impact aggregation and inherent score */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function impact_aggregates_by_the_profiles_method_and_unscored_dimensions_are_excluded(): void
    {
        $impacts = ['financial' => 5, 'operational' => 3, 'reputational' => 1, 'regulatory' => null, 'strategic' => null];

        $this->assertSame(5, $this->scoring->calculateImpact($impacts, $this->organization->id));

        $average = ScoringProfile::fallback();
        $average->impact_aggregation = 'average';
        $this->assertSame(3, $this->scoring->calculateImpact($impacts, null, $average));

        $worstTwo = ScoringProfile::fallback();
        $worstTwo->impact_aggregation = 'worst_two';
        $this->assertSame(4, $this->scoring->calculateImpact($impacts, null, $worstTwo));

        $weighted = ScoringProfile::fallback();
        $weighted->impact_aggregation = 'weighted';
        $weighted->dimension_weights = ['financial' => 2, 'operational' => 1, 'reputational' => 1];
        // (2×5 + 1×3 + 1×1) / 4 = 3.5 → 4
        $this->assertSame(4, $this->scoring->calculateImpact($impacts, null, $weighted));

        $this->assertSame(0, $this->scoring->calculateImpact(['financial' => null], $this->organization->id));
    }

    #[Test]
    public function the_inherent_score_is_likelihood_times_aggregated_impact_clamped_to_the_matrix(): void
    {
        $this->assertSame(20, $this->scoring->calculateScore(4, 5));
        $this->assertSame(25, $this->scoring->calculateScore(9, 9), 'inputs beyond the axis are clamped, not rejected');
        $this->assertSame(0, $this->scoring->calculateScore(0, 5), 'zero means not scored and stays zero');
        $this->assertSame('Critical', $this->scoring->calculateRating(20));
        $this->assertSame('Low', $this->scoring->calculateRating(4));
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function assessmentFor($risk, int $likelihood, int $impact): RiskAssessment
    {
        $score = $this->scoring->calculateScore($likelihood, $impact);

        return RiskAssessment::create([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'assessment_type' => 'periodic',
            'assessment_date' => now()->toDateString(),
            'assessor_id' => $this->actor->id,
            'likelihood_score' => $likelihood,
            'impact_financial' => $impact,
            'impact_score' => $impact,
            'overall_score' => $score,
            'overall_rating' => $this->scoring->calculateRating($score),
            'status' => 'draft',
        ]);
    }
}

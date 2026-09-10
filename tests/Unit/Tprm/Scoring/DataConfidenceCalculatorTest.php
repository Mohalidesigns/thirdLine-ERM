<?php

namespace Tests\Unit\Tprm\Scoring;

use App\Services\Tprm\Scoring\DataConfidenceCalculator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TRD §7.6 — the honest number beside the confident one.
 *
 * The test that matters most is the last one: a residual score computed from
 * inputs nobody has refreshed must not be usable to close a review. Without
 * that, the badge is decoration, and the module joins every other product that
 * prints a confident number derived from stale data.
 */
class DataConfidenceCalculatorTest extends TestCase
{
    private DataConfidenceCalculator $calculator;

    /** @var array<string, int> */
    private array $intervals = [
        'assessment_age' => 365,
        'evidence_age' => 365,
        'screening_age' => 180,
        'monitoring_recency' => 30,
    ];

    /** @var array<string, float> */
    private array $weights = [
        'assessment_age' => 0.35,
        'evidence_age' => 0.30,
        'screening_age' => 0.20,
        'monitoring_recency' => 0.15,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new DataConfidenceCalculator;
    }

    #[Test]
    public function everything_inside_its_interval_scores_one(): void
    {
        $result = $this->calculator->calculate(
            ['assessment_age' => 30, 'evidence_age' => 10, 'screening_age' => 5, 'monitoring_recency' => 1],
            $this->intervals,
            $this->weights,
        );

        $this->assertSame(1.0, round($result->dc, 3));
        $this->assertSame('Current', $result->badge());
        $this->assertSame([], $result->weaknesses());
    }

    #[Test]
    public function the_decay_is_linear_between_the_interval_and_twice_it(): void
    {
        // Exactly halfway through the grace period: halfway between 1.0 and
        // the 0.2 floor, which is 0.6.
        $result = $this->calculator->calculate(
            ['assessment_age' => 547, 'evidence_age' => 10, 'screening_age' => 5, 'monitoring_recency' => 1],
            $this->intervals,
            $this->weights,
        );

        $this->assertSame(0.6, round($result->components['assessment_age']['score'], 1));
        $this->assertSame('ageing', $result->components['assessment_age']['state']);
    }

    #[Test]
    public function beyond_twice_the_interval_it_sits_on_the_floor(): void
    {
        $result = $this->calculator->calculate(
            ['assessment_age' => 900, 'evidence_age' => 900, 'screening_age' => 900, 'monitoring_recency' => 900],
            $this->intervals,
            $this->weights,
        );

        $this->assertSame(0.2, round($result->dc, 3));
        $this->assertSame('Stale', $result->badge());
    }

    #[Test]
    public function a_thing_that_never_happened_scores_the_floor_and_not_one(): void
    {
        // The failure mode this guards: a calculator treating absence as
        // freshness would give its highest confidence to the vendors nobody
        // has ever looked at.
        $never = $this->calculator->calculate(
            ['assessment_age' => null, 'evidence_age' => null, 'screening_age' => null, 'monitoring_recency' => null],
            $this->intervals,
            $this->weights,
        );

        $this->assertSame(0.2, round($never->dc, 3));
        $this->assertSame('Stale', $never->badge());
        $this->assertSame('never', $never->components['assessment_age']['state']);
    }

    #[Test]
    public function a_component_with_no_policy_interval_is_dropped_rather_than_scored(): void
    {
        // Including it at the floor would penalise a tenant for a policy they
        // were never asked to set. Renormalising over the weights actually
        // used is what stops the composite sagging.
        $result = $this->calculator->calculate(
            ['assessment_age' => 10, 'evidence_age' => 10, 'screening_age' => 10],
            ['assessment_age' => 365, 'evidence_age' => 365, 'screening_age' => 180],
            $this->weights,
        );

        $this->assertSame(1.0, round($result->dc, 3));
        $this->assertArrayNotHasKey('monitoring_recency', $result->components);
    }

    #[Test]
    public function the_weaknesses_are_ordered_by_what_is_worth_fixing_first(): void
    {
        // "The assessment is two years old" is actionable; "confidence is
        // 0.41" is not.
        $result = $this->calculator->calculate(
            [
                'assessment_age' => 900,      // stale, weight 0.35 — the biggest loss
                'evidence_age' => 10,         // current
                'screening_age' => 400,       // stale, weight 0.20
                'monitoring_recency' => 45,   // ageing, weight 0.15
            ],
            $this->intervals,
            $this->weights,
        );

        $weaknesses = $result->weaknesses();

        $this->assertCount(3, $weaknesses);
        $this->assertStringContainsString('questionnaire assessment', $weaknesses[0]);
        $this->assertStringContainsString('900 days old', $weaknesses[0]);
        $this->assertStringContainsString('screening', $weaknesses[1]);
    }

    #[Test]
    public function a_score_below_the_threshold_cannot_close_a_review(): void
    {
        // TRD §7.6's teeth. Without this the badge is decoration.
        $stale = $this->calculator->calculate(
            ['assessment_age' => 900, 'evidence_age' => 900, 'screening_age' => 900, 'monitoring_recency' => 900],
            $this->intervals,
            $this->weights,
        );

        $this->assertFalse($stale->mayCloseReview());

        $current = $this->calculator->calculate(
            ['assessment_age' => 30, 'evidence_age' => 30, 'screening_age' => 30, 'monitoring_recency' => 5],
            $this->intervals,
            $this->weights,
        );

        $this->assertTrue($current->mayCloseReview());
    }

    #[Test]
    public function a_recent_assessment_alone_does_not_carry_the_composite(): void
    {
        // The realistic bad case: the questionnaire was done last month and
        // nothing else has been touched in two years. The composite has to
        // land below the threshold, or a review closes on the strength of one
        // fresh input.
        $result = $this->calculator->calculate(
            ['assessment_age' => 30, 'evidence_age' => 900, 'screening_age' => 900, 'monitoring_recency' => 900],
            $this->intervals,
            $this->weights,
        );

        // 0.35×1 + 0.65×0.2 = 0.48
        $this->assertSame(0.48, round($result->dc, 3));
        $this->assertFalse($result->mayCloseReview());
        $this->assertSame('Stale', $result->badge());
    }
}

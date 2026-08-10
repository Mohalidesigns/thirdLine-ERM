<?php

namespace Tests\Feature\Characterisation;

use App\Services\ControlEffectivenessService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * CHARACTERISATION — crown jewel.
 *
 * Pins the weighted aggregation in ControlEffectivenessService: the five-band
 * rating map, the SUM(w·e)/SUM(w) formula, and the residual score it drives.
 *
 * The five-band map is the one that survives WP-01 TASK 2; Control's
 * three-band EFFECTIVENESS_PERCENT_MAP constant is the one that goes. These
 * assertions are what makes that consolidation safe to perform.
 */
class ControlEffectivenessServiceTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private ControlEffectivenessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        $this->service = new ControlEffectivenessService;
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  The five-band rating map */
    /* ------------------------------------------------------------------ */

    #[Test]
    #[DataProvider('ratingBands')]
    public function each_rating_band_maps_to_its_percentage(string $rating, float $expected): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl(['effectiveness_rating' => $rating]);
        $this->attachControl($risk, $control);

        $this->assertSame($expected, $this->service->calculateForRisk($risk));
    }

    public static function ratingBands(): array
    {
        return [
            'effective' => ['effective', 95.0],
            'mostly effective' => ['mostly_effective', 80.0],
            'partially effective' => ['partially_effective', 60.0],
            'ineffective' => ['ineffective', 37.0],
            'not operating' => ['not_operating', 12.0],
        ];
    }

    #[Test]
    public function an_unrecognised_rating_contributes_zero_rather_than_being_skipped(): void
    {
        // It still carries its weight into the denominator — an unclassified
        // control drags the average down instead of vanishing from it.
        $risk = $this->makeRisk();
        $this->attachControl($risk, $this->makeControl(['effectiveness_rating' => 'effective']));
        $this->attachControl($risk, $this->makeControl(['effectiveness_rating' => 'gibberish']));

        $this->assertSame(47.5, $this->service->calculateForRisk($risk));
    }

    #[Test]
    public function a_null_rating_contributes_zero(): void
    {
        $risk = $this->makeRisk();
        $this->attachControl($risk, $this->makeControl(['effectiveness_rating' => null]));

        $this->assertSame(0.0, $this->service->calculateForRisk($risk));
    }

    /* ------------------------------------------------------------------ */
    /*  Explicit percentage wins over the band */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_explicit_effectiveness_pct_overrides_the_rating_band(): void
    {
        $risk = $this->makeRisk();
        $this->attachControl($risk, $this->makeControl([
            'effectiveness_rating' => 'effective',   // would be 95
            'effectiveness_pct' => 42.5,             // measured value wins
        ]));

        $this->assertSame(42.5, $this->service->calculateForRisk($risk));
    }

    #[Test]
    public function an_explicit_zero_percent_is_honoured_and_not_treated_as_absent(): void
    {
        // Guards the null-coalescing operator: 0.0 is a measurement, not a
        // missing value, so it must not fall through to the rating band.
        $risk = $this->makeRisk();
        $this->attachControl($risk, $this->makeControl([
            'effectiveness_rating' => 'effective',
            'effectiveness_pct' => 0,
        ]));

        $this->assertSame(0.0, $this->service->calculateForRisk($risk));
    }

    /* ------------------------------------------------------------------ */
    /*  Weighted aggregation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function aggregation_is_weighted_by_the_pivot_control_weight(): void
    {
        $risk = $this->makeRisk();
        // (3 × 95 + 1 × 37) / 4 = 80.5
        $this->attachControl($risk, $this->makeControl(['effectiveness_rating' => 'effective']), 3.0);
        $this->attachControl($risk, $this->makeControl(['effectiveness_rating' => 'ineffective']), 1.0);

        $this->assertSame(80.5, $this->service->calculateForRisk($risk));
    }

    #[Test]
    public function fractional_weights_are_respected(): void
    {
        $risk = $this->makeRisk();
        // (0.25 × 95 + 0.75 × 60) / 1.0 = 68.75
        $this->attachControl($risk, $this->makeControl(['effectiveness_rating' => 'effective']), 0.25);
        $this->attachControl($risk, $this->makeControl(['effectiveness_rating' => 'partially_effective']), 0.75);

        $this->assertSame(68.75, $this->service->calculateForRisk($risk));
    }

    #[Test]
    public function the_result_is_rounded_to_two_decimal_places(): void
    {
        $risk = $this->makeRisk();
        // (95 + 80 + 12) / 3 = 62.333... → 62.33
        $this->attachControl($risk, $this->makeControl(['effectiveness_rating' => 'effective']));
        $this->attachControl($risk, $this->makeControl(['effectiveness_rating' => 'mostly_effective']));
        $this->attachControl($risk, $this->makeControl(['effectiveness_rating' => 'not_operating']));

        $this->assertSame(62.33, $this->service->calculateForRisk($risk));
    }

    #[Test]
    public function a_risk_with_no_controls_is_zero_percent_effective(): void
    {
        $this->assertSame(0.0, $this->service->calculateForRisk($this->makeRisk()));
    }

    #[Test]
    public function controls_that_all_carry_zero_weight_yield_zero_rather_than_dividing_by_zero(): void
    {
        $risk = $this->makeRisk();
        $this->attachControl($risk, $this->makeControl(['effectiveness_rating' => 'effective']), 0.0);

        $this->assertSame(0.0, $this->service->calculateForRisk($risk));
    }

    /* ------------------------------------------------------------------ */
    /*  Residual recalculation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function recalculating_writes_effectiveness_and_derives_the_residual_score(): void
    {
        $risk = $this->makeRisk([
            'inherent_likelihood' => 4,
            'inherent_impact' => 5,
            'inherent_score' => 20,
            'inherent_rating' => 'Critical',
        ]);
        $this->attachControl($risk, $this->makeControl(['effectiveness_rating' => 'mostly_effective']));

        $updated = $this->service->recalculateForRisk($risk);

        $this->assertSame('80.00', $updated->control_effectiveness_pct);
        // 20 × (1 − 0.80) = 4 → Low
        $this->assertSame(4, $updated->residual_score);
        $this->assertSame('Low', $updated->residual_rating);
    }

    #[Test]
    public function the_residual_score_never_falls_below_one(): void
    {
        // No control removes a risk entirely. The floor is what stops a
        // heavily-controlled risk from disappearing off the register.
        $risk = $this->makeRisk(['inherent_score' => 2]);
        $this->attachControl($risk, $this->makeControl(['effectiveness_pct' => 99.99]));

        $updated = $this->service->recalculateForRisk($risk);

        $this->assertSame(1, $updated->residual_score);
        $this->assertSame('Low', $updated->residual_rating);
    }

    #[Test]
    public function an_unscored_risk_gets_no_residual_score(): void
    {
        $risk = $this->makeRisk(['inherent_score' => null, 'inherent_likelihood' => null, 'inherent_impact' => null]);
        $this->attachControl($risk, $this->makeControl(['effectiveness_rating' => 'effective']));

        $updated = $this->service->recalculateForRisk($risk);

        $this->assertSame('95.00', $updated->control_effectiveness_pct);
        $this->assertNull($updated->residual_score);
        $this->assertNull($updated->residual_rating);
    }

    #[Test]
    public function an_uncontrolled_risk_keeps_its_inherent_score_as_its_residual(): void
    {
        $risk = $this->makeRisk([
            'inherent_likelihood' => 4,
            'inherent_impact' => 4,
            'inherent_score' => 16,
        ]);

        $updated = $this->service->recalculateForRisk($risk);

        $this->assertSame('0.00', $updated->control_effectiveness_pct);
        $this->assertSame(16, $updated->residual_score);
        $this->assertSame('High', $updated->residual_rating);
    }
}

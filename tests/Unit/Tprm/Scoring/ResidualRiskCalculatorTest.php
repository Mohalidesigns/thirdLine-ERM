<?php

namespace Tests\Unit\Tprm\Scoring;

use App\Enums\Tprm\RiskBand;
use App\Services\Tprm\Scoring\FindingContribution;
use App\Services\Tprm\Scoring\ResidualInputs;
use App\Services\Tprm\Scoring\ResidualRiskCalculator;
use App\Services\Tprm\Scoring\SignalContribution;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TRD §7.5, to the decimal.
 *
 * THE TWO WORKED EXAMPLES ARE THE GOLDEN FIXTURES. They are the numbers a
 * client will check the product against, and the specification prints them:
 * 75.9 Critical, then 44.3 Moderate after remediation. If either moves, either
 * the calculator changed or the specification did, and both are things
 * somebody has to decide deliberately rather than discover.
 *
 * A NOTE ON 44.3 versus 44.4. The implementation prompt for this phase says
 * the second example lands on 44.4; the TRD says 44.3 and shows its own
 * arithmetic as `88 × 0.50368`, which is full precision. The difference is
 * entirely intermediate rounding: rounding M to three decimals first gives
 * 0.496 and 44.352 → 44.4, while carrying M at full precision gives 0.49632
 * and 44.32384 → 44.3. The TRD is internally consistent and is the
 * specification, so full precision it is — and this test pins it so the choice
 * is visible rather than accidental.
 */
class ResidualRiskCalculatorTest extends TestCase
{
    private ResidualRiskCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new ResidualRiskCalculator;
    }

    /* ------------------------------------------------------------------ */
    /*  The TRD's worked examples */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_core_banking_vendor_before_remediation_scores_75_point_9_critical(): void
    {
        // "Core banking application support vendor. IR = 88 … AC = 0.82,
        // EC = 0.58 … M = 0.285. Two High findings inside SLA → FU = 7. Cyber
        // rating dropped one band → SU = 6. RR = 75.9 → Critical."
        $result = $this->calculator->calculate(ResidualInputs::fromConfig(
            inherentScore: 88,
            ac: 0.82,
            ec: 0.58,
            findings: [
                new FindingContribution('high', withinSlaWithAcceptedPlan: true, reference: 'TPF-2026-0001'),
                new FindingContribution('high', withinSlaWithAcceptedPlan: true, reference: 'TPF-2026-0002'),
            ],
            signals: [
                new SignalContribution('cyber_rating_band_drop', 'Cyber rating dropped from A to B'),
            ],
        ));

        $this->assertSame(0.285, round($result->m, 3));
        $this->assertSame(7.0, $result->fu);
        $this->assertSame(6.0, $result->su);
        $this->assertSame(75.9, $result->displayScore());
        $this->assertSame(RiskBand::Critical, $result->band);
    }

    #[Test]
    public function the_same_vendor_after_remediation_scores_44_point_3_moderate(): void
    {
        // "AC = 0.94, EC = 0.88 → M = 0.496; findings closed → FU = 0; rating
        // recovered → SU = 0. RR = 88 × 0.50368 = 44.3 → Moderate. The score
        // moved because evidence improved, not because answers changed."
        $result = $this->calculator->calculate(ResidualInputs::fromConfig(
            inherentScore: 88,
            ac: 0.94,
            ec: 0.88,
        ));

        $this->assertSame(0.496, round($result->m, 3));
        $this->assertSame(0.0, $result->fu);
        $this->assertSame(0.0, $result->su);
        $this->assertSame(44.3, $result->displayScore());
        $this->assertSame(RiskBand::Moderate, $result->band);
    }

    #[Test]
    public function intermediate_rounding_would_move_the_second_example_and_does_not(): void
    {
        // The guard on the decision above. Rounding M to three decimals before
        // the multiplication yields 44.4; carrying it at full precision yields
        // the 44.3 the TRD prints. This test fails if anybody "tidies up" the
        // calculator by rounding as it goes.
        $result = $this->calculator->calculate(ResidualInputs::fromConfig(88, 0.94, 0.88));

        $this->assertSame(44.3, $result->displayScore());
        $this->assertNotSame(44.4, $result->displayScore());
        // And the full-precision figure is available to anyone reproducing it.
        $this->assertEqualsWithDelta(44.32384, $result->rr, 0.00001);
    }

    #[Test]
    public function the_explanation_writes_the_arithmetic_out(): void
    {
        // A reader who cannot reproduce the number will not believe it.
        $result = $this->calculator->calculate(ResidualInputs::fromConfig(
            88, 0.82, 0.58,
            findings: [
                new FindingContribution('high', withinSlaWithAcceptedPlan: true),
                new FindingContribution('high', withinSlaWithAcceptedPlan: true),
            ],
            signals: [new SignalContribution('cyber_rating_band_drop', 'Rating dropped')],
        ));

        $this->assertSame('88 × (1 − 0.285) + 7 + 6 = 75.9', $result->workingOut());
    }

    /* ------------------------------------------------------------------ */
    /*  The multipliers */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_overdue_finding_raises_the_uplift_by_the_specified_multiplier(): void
    {
        // The phase acceptance: "a finding moving from in-SLA to overdue
        // raises FU by the specified multiplier".
        $inSla = $this->calculator->calculate(ResidualInputs::fromConfig(
            50, 0.8, 0.8,
            findings: [new FindingContribution('high', withinSlaWithAcceptedPlan: true)],
        ));

        $overdue = $this->calculator->calculate(ResidualInputs::fromConfig(
            50, 0.8, 0.8,
            findings: [new FindingContribution('high', overdueBeyondThreshold: true)],
        ));

        $this->assertSame(3.5, $inSla->fu);   // 7 × 0.5
        $this->assertSame(10.5, $overdue->fu); // 7 × 1.5
        $this->assertSame(7.0, round($overdue->rr - $inSla->rr, 2));
    }

    #[Test]
    public function the_multipliers_do_not_compound(): void
    {
        // An overdue risk-accepted finding must not be 7 × 0.5 × 1.5. The
        // order of the checks is the rule, and risk acceptance wins: it is a
        // decision somebody made, and an overdue clock on a risk that was
        // accepted is not a fact about the vendor.
        $result = $this->calculator->calculate(ResidualInputs::fromConfig(
            50, 0.8, 0.8,
            findings: [new FindingContribution('high', overdueBeyondThreshold: true, riskAccepted: true)],
        ));

        $this->assertSame(3.5, $result->fu);
    }

    #[Test]
    public function a_risk_accepted_finding_still_counts_at_half(): void
    {
        // Not zero. A score that dropped to nothing on acceptance would make
        // acceptance the cheapest way to improve a rating.
        $result = $this->calculator->calculate(ResidualInputs::fromConfig(
            50, 0.8, 0.8,
            findings: [new FindingContribution('critical', riskAccepted: true)],
        ));

        $this->assertSame(6.0, $result->fu);
    }

    /* ------------------------------------------------------------------ */
    /*  The caps */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_findings_uplift_is_capped_and_says_so(): void
    {
        // A vendor with forty open findings and one with four both showing
        // FU = 20 is a fact a reader has to be told, or the score looks like
        // it stopped responding.
        $findings = array_fill(0, 5, new FindingContribution('critical'));

        $result = $this->calculator->calculate(ResidualInputs::fromConfig(50, 0.8, 0.8, findings: $findings));

        $this->assertSame(20.0, $result->fu);

        $cap = collect($result->findingContributions)->firstWhere('title', 'Findings uplift capped');
        $this->assertNotNull($cap, 'The cap bit and the explanation did not say so.');
        $this->assertSame(60.0, $cap['base_penalty']);
    }

    #[Test]
    public function expired_evidence_signals_have_their_own_sub_cap(): void
    {
        // A vendor with nine lapsed certificates would otherwise exhaust the
        // whole signal budget on paperwork and leave no room for a breach.
        $signals = array_fill(0, 5, new SignalContribution('expired_mandatory_evidence', 'A certificate lapsed'));
        $signals[] = new SignalContribution('confirmed_breach_12m', 'Confirmed breach in June');

        $result = $this->calculator->calculate(ResidualInputs::fromConfig(50, 0.8, 0.8, signals: $signals));

        // 4 + 4 = 8 (the sub-cap), plus the breach's 10.
        $this->assertSame(18.0, $result->su);
    }

    #[Test]
    public function the_signal_uplift_is_capped_at_twenty(): void
    {
        $result = $this->calculator->calculate(ResidualInputs::fromConfig(50, 0.8, 0.8, signals: [
            new SignalContribution('confirmed_breach_12m', 'Breach'),
            new SignalContribution('financial_distress', 'Distress'),
            new SignalContribution('regulatory_action', 'Action'),
        ]));

        $this->assertSame(20.0, $result->su);
    }

    /* ------------------------------------------------------------------ */
    /*  The sanctions override — AC-08 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_confirmed_sanctions_match_forces_one_hundred_whatever_the_assurance(): void
    {
        // A vendor with perfect assurance and no findings. Dealing with a
        // sanctioned entity is a criminal offence, not a risk to be offset.
        $result = $this->calculator->calculate(ResidualInputs::fromConfig(
            10, 1.0, 1.0,
            signals: [new SignalContribution('sanctions_true_match', 'Confirmed match on the OFAC SDN list')],
        ));

        $this->assertTrue($result->sanctionsOverride);
        $this->assertSame(100.0, $result->rr);
        $this->assertSame(RiskBand::Critical, $result->band);
        $this->assertStringContainsString('criminal offence', $result->workingOut());
    }

    /* ------------------------------------------------------------------ */
    /*  Bounds and bands */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_mitigation_ceiling_holds_even_if_a_tenant_raises_kmax(): void
    {
        // A tenant able to set Kmax to 1.0 could score every vendor as fully
        // mitigated. The ceiling is a product position, not a default.
        $result = $this->calculator->calculate(ResidualInputs::fromConfig(88, 1.0, 1.0, kmax: 1.0));

        $this->assertSame(0.75, $result->kmax);
        $this->assertSame(22.0, $result->displayScore());
    }

    #[Test]
    public function the_score_is_clamped_to_one_hundred(): void
    {
        $result = $this->calculator->calculate(ResidualInputs::fromConfig(
            95, 0.1, 0.1,
            findings: array_fill(0, 3, new FindingContribution('critical')),
            signals: [new SignalContribution('confirmed_breach_12m', 'Breach')],
        ));

        $this->assertSame(100.0, $result->rr);
    }

    #[Test]
    public function the_band_is_taken_from_the_rounded_score(): void
    {
        // 74.6 displays as 74.6 and rounds to 75, so it is Critical. A board
        // pack showing a number in one band and a colour from another costs a
        // client's confidence in everything else on the page.
        $result = $this->calculator->calculate(ResidualInputs::fromConfig(74.6, 0.0, 0.0));

        $this->assertSame(74.6, $result->displayScore());
        $this->assertSame(RiskBand::Critical, $result->band);

        $below = $this->calculator->calculate(ResidualInputs::fromConfig(74.4, 0.0, 0.0));
        $this->assertSame(RiskBand::High, $below->band);
    }

    #[Test]
    public function a_perfectly_assured_critical_vendor_still_carries_forty_percent(): void
    {
        // The policy position behind Kmax: no amount of paperwork makes a
        // vendor holding core banking data a low risk.
        $result = $this->calculator->calculate(ResidualInputs::fromConfig(90, 1.0, 1.0));

        $this->assertSame(36.0, $result->displayScore());
        $this->assertSame(RiskBand::Moderate, $result->band);
    }

    #[Test]
    public function the_inputs_snapshot_carries_everything_needed_to_replay_the_score(): void
    {
        // TRD §7.9: a score has to be reproducible from what was stored, not
        // from today's configuration.
        $inputs = ResidualInputs::fromConfig(
            88, 0.82, 0.58,
            findings: [new FindingContribution('high', withinSlaWithAcceptedPlan: true, reference: 'TPF-1')],
            signals: [new SignalContribution('cyber_rating_band_drop', 'Dropped')],
        );

        $snapshot = $inputs->toArray();

        $this->assertSame(88.0, $snapshot['ir']);
        $this->assertSame(0.6, $snapshot['kmax']);
        $this->assertCount(1, $snapshot['findings']);
        $this->assertSame(7.0, $snapshot['coefficients']['severity_penalties']['high']);
        $this->assertSame(20, $snapshot['coefficients']['findings_cap']);
    }
}

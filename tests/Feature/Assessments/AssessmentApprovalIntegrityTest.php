<?php

namespace Tests\Feature\Assessments;

use App\Models\RiskAssessment;
use App\Models\RiskAssessmentControl;
use App\Models\ScoringProfile;
use App\Services\AssessmentChainService;
use App\Services\ControlEffectivenessService;
use App\Services\RiskScoringService;
use App\Services\Workflow\Subjects\RiskAssessmentBinding;
use App\Support\RiskCalculationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * What approving an assessment is allowed to do to the risk it assesses.
 *
 * Approval is the one moment where an assessment's numbers become the risk's
 * numbers, and until now two different mappings did it, in sequence, on the
 * same row:
 *
 *   RiskAssessmentBinding::onApproved()      — inside the approval transaction
 *   └─ AssessmentApproved
 *      └─ UpdateRiskFromAssessment
 *         └─ RiskScoringService::updateRiskFromAssessment()
 *
 * The first took the assessment's scalar `impact_score` and copied its residual
 * verbatim. The second re-aggregated the impact DIMENSION columns and nulled
 * any residual that had no likelihood/impact pair. The second ran last, so the
 * second won, and a reassessment carrying a scalar impact and no dimensions —
 * exactly what a treatment-driven reassessment carries — was written onto the
 * risk as ZERO.
 *
 * MEASURED BEFORE THE FIX, on a risk assessed at likelihood 4, impact 5,
 * overall 20 "Critical", with no impact dimensions:
 *
 *   inherent_likelihood 4, inherent_impact 0, inherent_score 0,
 *   inherent_rating "Low"
 *
 * A Critical risk came out of its own approval rated Low, on a number nothing
 * had assessed. That is the fabrication NoFabricatedNumbersTest exists to stop,
 * arriving through a service rather than a Blade default.
 */
class AssessmentApprovalIntegrityTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private RiskScoringService $scoring;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        RiskCalculationSettings::flush();
        ScoringProfile::flushResolutionCache();

        $this->scoring = app(RiskScoringService::class);
    }

    protected function tearDown(): void
    {
        RiskCalculationSettings::flush();
        ScoringProfile::flushResolutionCache();
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  The reproduction */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function approving_an_assessment_with_a_scalar_impact_and_no_dimensions_keeps_the_inherent_score(): void
    {
        $risk = $this->makeRisk([
            'inherent_likelihood' => 4,
            'inherent_impact' => 5,
            'inherent_score' => 20,
            'inherent_rating' => 'Critical',
        ]);

        // A reassessment that restates the pair as a likelihood and one impact
        // number, which is all the reassessment path has to carry forward.
        $assessment = $this->makeAssessment($risk, [
            'likelihood_score' => 4,
            'impact_score' => 5,
            'overall_score' => 20,
            'overall_rating' => 'Critical',
        ]);

        $this->approve($assessment);

        $risk->refresh();

        // PRE-FIX: 4 / 0 / 0 / "Low". The dimension aggregation returned 0 for
        // an assessment that scored no dimensions, and 4 × 0 = 0 was written
        // over a Critical rating as though it had been assessed.
        $this->assertSame(4, $risk->inherent_likelihood);
        $this->assertSame(5, $risk->inherent_impact, 'The scalar impact the assessment DID state is the impact.');
        $this->assertSame(20, $risk->inherent_score);
        $this->assertSame('Critical', $risk->inherent_rating);
    }

    #[Test]
    public function an_assessment_that_states_no_impact_at_all_leaves_the_risk_untouched(): void
    {
        $risk = $this->makeRisk([
            'inherent_likelihood' => 3,
            'inherent_impact' => 4,
            'inherent_score' => 12,
            'inherent_rating' => 'High',
        ]);

        // No dimensions, no scalar. Nothing to write, so nothing is written —
        // not a zero, and not last quarter's impact against this quarter's
        // likelihood either.
        $assessment = $this->makeAssessment($risk, [
            'likelihood_score' => 5,
            'impact_score' => null,
        ]);

        $this->approve($assessment);

        $risk->refresh();

        $this->assertSame(3, $risk->inherent_likelihood);
        $this->assertSame(4, $risk->inherent_impact);
        $this->assertSame(12, $risk->inherent_score);
        $this->assertSame('High', $risk->inherent_rating);
    }

    /* ------------------------------------------------------------------ */
    /*  The good path, pinned */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_assessment_with_full_impact_dimensions_updates_inherent_exactly_as_before(): void
    {
        $risk = $this->makeRisk([
            'inherent_likelihood' => 1,
            'inherent_impact' => 1,
            'inherent_score' => 1,
            'inherent_rating' => 'Low',
        ]);

        $assessment = $this->makeAssessment($risk, [
            'likelihood_score' => 4,
            'impact_financial' => 5,
            'impact_operational' => 3,
            'impact_reputational' => 2,
            'impact_regulatory' => 1,
            'impact_strategic' => 1,
            // Deliberately absent: `impact_score`. The dimensions are the more
            // specific statement and must keep winning.
        ]);

        $this->approve($assessment);

        $risk->refresh();

        // Identical to the numbers RiskScoringServiceTest has always pinned for
        // this input: max(5,3,2,1,1) = 5, 4 × 5 = 20, Critical.
        $this->assertSame(4, $risk->inherent_likelihood);
        $this->assertSame(5, $risk->inherent_impact);
        $this->assertSame(20, $risk->inherent_score);
        $this->assertSame('Critical', $risk->inherent_rating);
    }

    #[Test]
    public function a_scalar_impact_does_not_override_the_dimensions_when_both_are_present(): void
    {
        $risk = $this->makeRisk();

        $assessment = $this->makeAssessment($risk, [
            'likelihood_score' => 3,
            'impact_financial' => 4,
            'impact_operational' => 3,
            'impact_score' => 4,
            'overall_score' => 12,
            'overall_rating' => 'High',
        ]);

        $this->approve($assessment);

        $risk->refresh();

        $this->assertSame(4, $risk->inherent_impact);
        $this->assertSame(12, $risk->inherent_score);
    }

    /* ------------------------------------------------------------------ */
    /*  Residual: the axis-split pair, not a scalar recomputation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_residual_written_on_approval_is_the_axis_split_pair(): void
    {
        $risk = $this->makeRisk();

        // Two detective controls: the assurance is on the IMPACT axis, so
        // AssessmentChainService::deriveResidual() moves the risk down the
        // impact axis rather than shrinking a single number.
        $this->attachControl($risk, $this->makeControl([
            'control_type' => 'detective',
            'effectiveness_rating' => 'effective',
        ]));
        $this->attachControl($risk, $this->makeControl([
            'control_type' => 'detective',
            'effectiveness_rating' => 'mostly_effective',
        ]));

        $assessment = $this->makeAssessment($risk, [
            'likelihood_score' => 4,
            'impact_financial' => 5,
            'impact_score' => 5,
            'overall_score' => 20,
            'overall_rating' => 'Critical',
        ]);

        $this->rateControls($assessment);

        $chain = app(AssessmentChainService::class)->applyToAssessment($assessment->fresh());

        $this->assertSame(RiskAssessment::RESIDUAL_DERIVED, $chain->residual_source);
        $this->assertNotNull($chain->residual_likelihood);
        $this->assertNotNull($chain->residual_impact);

        $this->approve($chain);

        $risk->refresh();

        // The pair reaches the risk row intact, and the score on the row is the
        // product of that pair — the number a heat map cell and a board paper
        // can both be read off.
        $this->assertSame((int) $chain->residual_likelihood, $risk->residual_likelihood);
        $this->assertSame((int) $chain->residual_impact, $risk->residual_impact);
        $this->assertSame(
            $risk->residual_likelihood * $risk->residual_impact,
            $risk->residual_score,
            'residual_score must be the product of the pair beside it, not a free-standing number.',
        );
        $this->assertSame($chain->residual_rating, $risk->residual_rating);

        // Observed: effectiveness 87.5%, inherent 4 × 5 = 20, derived residual
        // likelihood 3, impact 1, score 3 "Low". The whole reduction is on the
        // impact axis, which is the point of the L·r^p × I·r^(1-p) split and
        // the one thing a scalar recomputation of residual_score cannot say.
        $this->assertSame(3, $risk->residual_likelihood);
        $this->assertSame(1, $risk->residual_impact);
        $this->assertLessThan(
            5 - $risk->residual_impact,
            4 - $risk->residual_likelihood,
            'Detective controls must move impact further than likelihood.',
        );
    }

    #[Test]
    public function an_assessment_silent_on_residual_does_not_erase_the_risks_residual(): void
    {
        $risk = $this->makeRisk([
            'residual_likelihood' => 2,
            'residual_impact' => 3,
            'residual_score' => 6,
            'residual_rating' => 'Medium',
        ]);

        // PRE-FIX: updateRiskFromAssessment nulled all four residual columns
        // whenever the assessment had no residual pair, so an inherent-only
        // reassessment silently withdrew the last residual assessment.
        $assessment = $this->makeAssessment($risk, [
            'likelihood_score' => 3,
            'impact_financial' => 3,
        ]);

        $this->approve($assessment);

        $risk->refresh();

        $this->assertSame(2, $risk->residual_likelihood);
        $this->assertSame(3, $risk->residual_impact);
        $this->assertSame(6, $risk->residual_score);
        $this->assertSame('Medium', $risk->residual_rating);
    }

    /* ------------------------------------------------------------------ */
    /*  The double write */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_binding_and_the_listener_now_run_the_same_mapping(): void
    {
        // The double write is resolved by making RiskScoringService the single
        // authority and having RiskAssessmentBinding::onApproved() delegate to
        // it. Proof: a risk taken through the full approval path lands on
        // exactly the columns the service alone produces for the same
        // assessment. Before the fix these diverged — the binding wrote
        // impact 5 / score 20 and the service overwrote it with 0 / 0.
        $approvedRisk = $this->makeRisk();
        $approvedAssessment = $this->makeAssessment($approvedRisk, [
            'likelihood_score' => 4,
            'impact_score' => 5,
            'overall_score' => 20,
            'overall_rating' => 'Critical',
            'residual_likelihood' => 2,
            'residual_impact' => 3,
            'residual_score' => 6,
            'residual_rating' => 'Medium',
        ]);

        $serviceRisk = $this->makeRisk();
        $serviceAssessment = $this->makeAssessment($serviceRisk, [
            'likelihood_score' => 4,
            'impact_score' => 5,
            'overall_score' => 20,
            'overall_rating' => 'Critical',
            'residual_likelihood' => 2,
            'residual_impact' => 3,
            'residual_score' => 6,
            'residual_rating' => 'Medium',
        ]);

        $this->approve($approvedAssessment);
        $this->scoring->updateRiskFromAssessment($serviceRisk, $serviceAssessment);

        $this->assertSame(
            $this->scoreColumns($serviceRisk->fresh()),
            $this->scoreColumns($approvedRisk->fresh()),
            'The approval path must not produce a different risk row from the service it delegates to.',
        );

        // Both mappings agreeing on a zero would satisfy the assertion above
        // and satisfy nobody else, so the agreed value is pinned too.
        $this->assertSame(5, $approvedRisk->fresh()->inherent_impact);
        $this->assertSame(20, $approvedRisk->fresh()->inherent_score);
        $this->assertSame(6, $approvedRisk->fresh()->residual_score);
    }

    #[Test]
    public function the_second_pass_over_the_same_assessment_writes_nothing(): void
    {
        // Two callers still touch the row on one approval — the binding inside
        // the transaction and the listener on AssessmentApproved. That is
        // harmless precisely because they are the same code: the second pass
        // finds nothing dirty, so Eloquent issues no UPDATE and updated_at does
        // not move.
        $risk = $this->makeRisk();
        $assessment = $this->makeAssessment($risk, [
            'likelihood_score' => 4,
            'impact_financial' => 5,
            'residual_likelihood' => 2,
            'residual_impact' => 3,
        ]);

        $this->approve($assessment);

        $afterApproval = $risk->fresh();

        $this->travel(5)->minutes();

        $this->scoring->updateRiskFromAssessment($risk->fresh(), $assessment->fresh());

        $afterSecondPass = $risk->fresh();

        $this->assertSame($this->scoreColumns($afterApproval), $this->scoreColumns($afterSecondPass));
        $this->assertTrue(
            $afterApproval->updated_at->equalTo($afterSecondPass->updated_at),
            'A repeat of the same mapping must not rewrite the row.',
        );

        $this->travelBack();
    }

    #[Test]
    public function the_control_update_path_still_moves_residual_score_without_the_pair(): void
    {
        // THE THIRD WRITER, pinned as it stands rather than described.
        //
        // ControlEffectivenessService::recalculateForRisk() runs from
        // RecalculateResidualRisk on ControlUpdated — a different trigger from
        // approval — and writes residual_score and residual_rating on their
        // own. On a risk whose residual came from an approved assessment, that
        // leaves the row internally inconsistent: a residual_score that is no
        // longer residual_likelihood × residual_impact.
        //
        // Not fixed here. Making this path axis-aware means giving it the
        // preventive/detective split AssessmentChainService derives from
        // control_type, which is a larger change than the approval-path repair
        // this test file exists for, and it would move the numbers pinned by
        // tests/Feature/Characterisation/ControlEffectivenessServiceTest.php.
        // This assertion is the follow-up's starting point: when that work
        // lands, this test should be the one that fails.
        $risk = $this->makeRisk([
            'inherent_likelihood' => 4,
            'inherent_impact' => 5,
            'inherent_score' => 20,
            'inherent_rating' => 'Critical',
            'residual_likelihood' => 4,
            'residual_impact' => 3,
            'residual_score' => 12,
            'residual_rating' => 'High',
        ]);

        $this->attachControl($risk, $this->makeControl([
            'control_type' => 'preventive',
            'effectiveness_rating' => 'mostly_effective',
        ]));

        $updated = app(ControlEffectivenessService::class)->recalculateForRisk($risk);

        // 20 × (1 − 0.80) = 4.
        $this->assertSame(4, $updated->residual_score);

        // …while the pair it is supposed to be the product of stands still.
        $this->assertSame(4, $updated->residual_likelihood);
        $this->assertSame(3, $updated->residual_impact);
        $this->assertNotSame(
            $updated->residual_likelihood * $updated->residual_impact,
            $updated->residual_score,
            'Documented gap: the control path writes a residual score that contradicts the residual pair.',
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Approve through the binding, which is the single entry point every
     * approval takes — review screen, My Tasks, SLA auto-approval or API — and
     * which dispatches AssessmentApproved to the listener chain.
     */
    private function approve(RiskAssessment $assessment): void
    {
        (new RiskAssessmentBinding)->onApproved($assessment, null, $this->actor, null);
    }

    private function makeAssessment(\App\Models\Risk $risk, array $attributes = []): RiskAssessment
    {
        return RiskAssessment::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'assessment_type' => 'periodic',
            'assessment_date' => now()->toDateString(),
            'assessor_id' => $this->actor->id,
            'status' => 'in_review',
        ], $attributes));
    }

    /**
     * Rate every control mapped to the risk, so the chain has something to
     * derive a residual from.
     */
    private function rateControls(RiskAssessment $assessment): void
    {
        foreach ($assessment->risk->controls as $control) {
            RiskAssessmentControl::create([
                'organization_id' => $this->organization->id,
                'risk_assessment_id' => $assessment->id,
                'control_id' => $control->id,
                'control_code' => $control->control_code,
                'control_name' => $control->name,
                'design_effectiveness' => $control->effectiveness_rating,
                'operating_effectiveness' => $control->effectiveness_rating,
                'control_weight' => 1.0,
            ])->applyEffectiveness()->save();
        }
    }

    /**
     * @return array<string, int|string|null>
     */
    private function scoreColumns(\App\Models\Risk $risk): array
    {
        return $risk->only([
            'inherent_likelihood',
            'inherent_impact',
            'inherent_impact_financial',
            'inherent_impact_operational',
            'inherent_impact_reputational',
            'inherent_impact_regulatory',
            'inherent_score',
            'inherent_rating',
            'residual_likelihood',
            'residual_impact',
            'residual_score',
            'residual_rating',
        ]);
    }
}

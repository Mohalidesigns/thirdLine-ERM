<?php

namespace Tests\Feature;

use App\Models\RiskAssessment;
use App\Models\RiskAssessmentControl;
use App\Models\RiskCause;
use App\Models\RiskCauseCategory;
use App\Models\ScoringProfile;
use App\Services\AssessmentChainService;
use App\Services\RiskScoringService;
use App\Support\RiskCalculationSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-10a — the assessment chain.
 *
 *   Risk → Root Cause → Likelihood → Impact → Inherent Risk →
 *   Existing Controls → Control Effectiveness → Residual Risk →
 *   Risk Treatment → Action Plan → Owner → Due Date → KRI
 *
 * The links these tests actually defend are 6 → 7 → 8. Residual risk used to be
 * a number an assessor typed; if it silently goes back to being one — a control
 * rating that stops counting, an override that stops being recorded — every
 * screen still renders and no other test fails.
 */
class AssessmentChainTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private AssessmentChainService $chain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        RiskCalculationSettings::flush();
        ScoringProfile::flushResolutionCache();

        $this->chain = new AssessmentChainService(new RiskScoringService);
    }

    protected function tearDown(): void
    {
        RiskCalculationSettings::flush();
        ScoringProfile::flushResolutionCache();
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Step 7 — control effectiveness */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_weaker_of_design_and_operating_effectiveness_wins(): void
    {
        // A control that is well designed but not performed delivers the
        // assurance of a control that is not performed. Averaging the two would
        // let paper controls inflate the residual score.
        $this->assertSame(
            37.0,
            RiskAssessmentControl::resolveEffectiveness('effective', 'ineffective', $this->organization->id),
        );

        $this->assertSame(
            37.0,
            RiskAssessmentControl::resolveEffectiveness('ineffective', 'effective', $this->organization->id),
        );
    }

    #[Test]
    public function a_control_rated_on_only_one_axis_uses_that_rating(): void
    {
        $this->assertSame(
            95.0,
            RiskAssessmentControl::resolveEffectiveness('effective', null, $this->organization->id),
        );

        $this->assertNull(
            RiskAssessmentControl::resolveEffectiveness(null, null, $this->organization->id),
        );
    }

    #[Test]
    public function effectiveness_aggregates_by_mapping_weight(): void
    {
        $rows = collect([
            (object) ['effectiveness_pct' => 100.0, 'control_weight' => 3.0],
            (object) ['effectiveness_pct' => 20.0, 'control_weight' => 1.0],
        ]);

        // (3×100 + 1×20) / 4 = 80, not the unweighted 60.
        $this->assertSame(80.0, RiskAssessmentControl::aggregateEffectiveness($rows));
    }

    #[Test]
    public function unrated_controls_are_excluded_rather_than_counted_as_zero(): void
    {
        $rows = collect([
            (object) ['effectiveness_pct' => 80.0, 'control_weight' => 1.0],
            (object) ['effectiveness_pct' => null, 'control_weight' => 1.0],
        ]);

        // A half-finished assessment must not report the risk as half
        // uncontrolled.
        $this->assertSame(80.0, RiskAssessmentControl::aggregateEffectiveness($rows));
        $this->assertNull(RiskAssessmentControl::aggregateEffectiveness(collect()));
    }

    /* ------------------------------------------------------------------ */
    /*  Step 8 — residual risk */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function residual_risk_is_derived_from_inherent_risk_and_control_effectiveness(): void
    {
        $risk = $this->makeRisk();
        $assessment = $this->assessmentFor($risk, likelihood: 4, impact: 5);

        $control = $this->makeControl(['control_type' => 'preventive']);
        $this->attachControl($risk, $control);

        $this->chain->syncControls($assessment, [
            $control->id => [
                'design_effectiveness' => 'mostly_effective',
                'operating_effectiveness' => 'mostly_effective',
            ],
        ]);

        $this->chain->applyToAssessment($assessment);
        $assessment->refresh();

        $this->assertSame('80.00', (string) $assessment->control_effectiveness_pct);
        $this->assertSame(RiskAssessment::RESIDUAL_DERIVED, $assessment->residual_source);

        // Inherent 20, discounted by 80% assurance, lands well below inherent.
        $this->assertNotNull($assessment->residual_score);
        $this->assertLessThan($assessment->overall_score, $assessment->residual_score);
    }

    #[Test]
    public function the_residual_score_always_equals_its_own_likelihood_times_impact(): void
    {
        $risk = $this->makeRisk();
        $assessment = $this->assessmentFor($risk, likelihood: 4, impact: 5);

        $control = $this->makeControl(['control_type' => 'detective']);
        $this->attachControl($risk, $control);

        $this->chain->syncControls($assessment, [
            $control->id => [
                'design_effectiveness' => 'effective',
                'operating_effectiveness' => 'partially_effective',
            ],
        ]);

        $this->chain->applyToAssessment($assessment);
        $assessment->refresh();

        // The matrix is discrete, so the projection rounds. Printing the
        // formula's raw number beside the rounded cell would put
        // "residual 7 (L3 × I2)" on a board paper.
        $this->assertSame(
            (int) $assessment->residual_likelihood * (int) $assessment->residual_impact,
            (int) $assessment->residual_score,
        );
    }

    #[Test]
    public function preventive_controls_move_the_risk_down_the_likelihood_axis(): void
    {
        $preventive = $this->residualFor('preventive');
        $detective = $this->residualFor('detective');

        // The same assurance, applied by controls that stop the event versus
        // controls that limit it, has to land in different cells — otherwise
        // `controls.control_type` is collected for nothing.
        $this->assertLessThan($detective['likelihood'], $preventive['likelihood']);
        $this->assertLessThan($preventive['impact'], $detective['impact']);
    }

    #[Test]
    public function residual_cannot_be_derived_when_no_control_has_been_rated(): void
    {
        $risk = $this->makeRisk();
        $assessment = $this->assessmentFor($risk, likelihood: 4, impact: 5);

        $control = $this->makeControl();
        $this->attachControl($risk, $control);

        $this->chain->syncControls($assessment, [
            $control->id => ['design_effectiveness' => null, 'operating_effectiveness' => null],
        ]);

        $this->chain->applyToAssessment($assessment);
        $assessment->refresh();

        // Null, not "no reduction". An unassessed control set and a control set
        // assessed as useless are different findings, and reporting residual ==
        // inherent would hide the first inside the second.
        $this->assertNull($assessment->control_effectiveness_pct);
        $this->assertNull($assessment->residual_source);
    }

    #[Test]
    public function an_assessor_override_is_recorded_as_one(): void
    {
        $risk = $this->makeRisk();
        $assessment = $this->assessmentFor($risk, likelihood: 4, impact: 5);

        $control = $this->makeControl(['control_type' => 'preventive']);
        $this->attachControl($risk, $control);

        $this->chain->syncControls($assessment, [
            $control->id => [
                'design_effectiveness' => 'effective',
                'operating_effectiveness' => 'effective',
            ],
        ]);

        $this->chain->applyToAssessment($assessment, [
            'likelihood' => 4,
            'impact' => 4,
            'justification' => 'Control is effective on paper but has never been tested under load.',
        ]);

        $assessment->refresh();

        $this->assertSame(RiskAssessment::RESIDUAL_OVERRIDE, $assessment->residual_source);
        $this->assertTrue($assessment->residualWasOverridden());
        $this->assertSame(16, (int) $assessment->residual_score);
        $this->assertStringContainsString('never been tested', $assessment->residual_justification);
    }

    #[Test]
    public function posting_back_the_derived_values_is_not_an_override(): void
    {
        $risk = $this->makeRisk();
        $assessment = $this->assessmentFor($risk, likelihood: 4, impact: 5);

        $control = $this->makeControl(['control_type' => 'preventive']);
        $this->attachControl($risk, $control);

        $this->chain->syncControls($assessment, [
            $control->id => [
                'design_effectiveness' => 'mostly_effective',
                'operating_effectiveness' => 'mostly_effective',
            ],
        ]);

        $this->chain->applyToAssessment($assessment);
        $derived = $assessment->fresh();

        // The form pre-fills the override inputs with the derived values. If
        // echoing them back counted as an override, every assessment would be
        // branded judgement and the distinction would carry no information.
        $this->chain->applyToAssessment($assessment, [
            'likelihood' => (int) $derived->residual_likelihood,
            'impact' => (int) $derived->residual_impact,
            'justification' => null,
        ]);

        $this->assertSame(RiskAssessment::RESIDUAL_DERIVED, $assessment->fresh()->residual_source);
    }

    /* ------------------------------------------------------------------ */
    /*  Step 6 — the control set */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_control_not_mapped_to_the_risk_cannot_be_rated_into_the_assessment(): void
    {
        $risk = $this->makeRisk();
        $assessment = $this->assessmentFor($risk, likelihood: 3, impact: 3);

        $mapped = $this->makeControl();
        $this->attachControl($risk, $mapped);

        $unmapped = $this->makeControl();

        $this->chain->syncControls($assessment, [
            $mapped->id => ['design_effectiveness' => 'effective', 'operating_effectiveness' => 'effective'],
            $unmapped->id => ['design_effectiveness' => 'effective', 'operating_effectiveness' => 'effective'],
        ]);

        // A forged control id in the request body must not attach a control the
        // risk does not have — still less one belonging to another tenant.
        $this->assertSame([$mapped->id], $assessment->assessedControls()->pluck('control_id')->all());
    }

    #[Test]
    public function control_ratings_are_a_snapshot_not_a_live_read_of_the_library(): void
    {
        $risk = $this->makeRisk();
        $assessment = $this->assessmentFor($risk, likelihood: 4, impact: 5);

        $control = $this->makeControl([
            'control_type' => 'preventive',
            'effectiveness_rating' => 'effective',
        ]);
        $this->attachControl($risk, $control);

        $this->chain->syncControls($assessment, [
            $control->id => [
                'design_effectiveness' => 'effective',
                'operating_effectiveness' => 'effective',
            ],
        ]);
        $this->chain->applyToAssessment($assessment);

        $scoreAtAssessment = $assessment->fresh()->residual_score;

        // Next month's test downgrades the control in the library.
        $control->update(['effectiveness_rating' => 'ineffective', 'effectiveness_pct' => 10]);

        // The approved assessment must not move. Otherwise a board paper stops
        // being reproducible the moment someone re-tests a control.
        $this->assertSame($scoreAtAssessment, $assessment->fresh()->residual_score);
        $this->assertSame(95.0, (float) $assessment->fresh()->assessedControls->first()->effectiveness_pct);
    }

    /* ------------------------------------------------------------------ */
    /*  Step 2 — root cause */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_system_cause_taxonomy_is_visible_to_every_tenant(): void
    {
        // Seeded with organization_id NULL by the WP-10a migration, so a new
        // organization can classify a cause before configuring anything.
        $codes = RiskCauseCategory::options()->pluck('code');

        $this->assertContains('people', $codes);
        $this->assertContains('third_party', $codes);
    }

    #[Test]
    public function causes_belong_to_the_risk_and_the_assessment_snapshots_them(): void
    {
        $risk = $this->makeRisk();

        $cause = RiskCause::create([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'description' => 'Manual reconciliation with no maker-checker',
            'source' => 'workshop',
            'is_primary' => true,
        ]);

        $assessment = $this->assessmentFor($risk, likelihood: 3, impact: 3);
        $assessment->update(['cause_snapshot' => [$cause->toSnapshot()]]);

        // Editing the cause afterwards must not rewrite what the assessment
        // reasoned about.
        $cause->update(['description' => 'Rewritten after the fact']);

        $this->assertSame(
            'Manual reconciliation with no maker-checker',
            $assessment->fresh()->causesConsidered()->first()['description'],
        );
    }

    #[Test]
    public function an_assessment_without_a_snapshot_falls_back_to_the_risks_current_causes(): void
    {
        $risk = $this->makeRisk();

        RiskCause::create([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'description' => 'Legacy cause',
        ]);

        // Assessments recorded before WP-10a have no snapshot; the risk's
        // current causes are the best available answer, and the view says so.
        $assessment = $this->assessmentFor($risk, likelihood: 3, impact: 3);

        $this->assertNull($assessment->cause_snapshot);
        $this->assertSame('Legacy cause', $assessment->causesConsidered()->first()['description']);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function assessmentFor($risk, int $likelihood, int $impact): RiskAssessment
    {
        $scoring = new RiskScoringService;
        $score = $scoring->calculateScore($likelihood, $impact);

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
            'overall_rating' => $scoring->calculateRating($score),
            'status' => 'draft',
        ]);
    }

    /**
     * The residual cell produced when the risk's only control is of the given
     * type, holding everything else equal.
     *
     * @return array{likelihood: int, impact: int}
     */
    private function residualFor(string $controlType): array
    {
        $risk = $this->makeRisk();
        $assessment = $this->assessmentFor($risk, likelihood: 5, impact: 5);

        $control = $this->makeControl(['control_type' => $controlType]);
        $this->attachControl($risk, $control);

        $this->chain->syncControls($assessment, [
            $control->id => [
                'design_effectiveness' => 'mostly_effective',
                'operating_effectiveness' => 'mostly_effective',
            ],
        ]);

        $this->chain->applyToAssessment($assessment);
        $assessment->refresh();

        return [
            'likelihood' => (int) $assessment->residual_likelihood,
            'impact' => (int) $assessment->residual_impact,
        ];
    }
}

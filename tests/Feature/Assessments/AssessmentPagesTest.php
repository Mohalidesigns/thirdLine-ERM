<?php

namespace Tests\Feature\Assessments;

use App\Models\RiskAssessment;
use App\Models\TreatmentPlan;
use App\Support\Migration\Ported;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;

/** Phase 3.3: the assessment pages, and the chain they write. */
class AssessmentPagesTest extends AssessmentsTestCase
{
    #[Test]
    public function every_ported_route_is_listed_in_the_registry(): void
    {
        foreach (['risk.assessments.create', 'risk.assessments.show', 'risk.assessments.edit'] as $name) {
            $this->assertTrue(Ported::isRoute($name), $name);
        }
    }

    #[Test]
    public function a_request_without_a_risk_gets_the_picker(): void
    {
        $this->actingAs($this->actor)
            ->get(route('risk.assessments.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assessments/SelectRisk')
                ->has('risks', 1)
                ->where('risks.0.risk_code', $this->risk->risk_code)
            );
    }

    #[Test]
    public function the_form_draws_the_whole_chain_for_a_chosen_risk(): void
    {
        $this->actingAs($this->actor)
            ->get(route('risk.assessments.create', ['risk_id' => $this->risk->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assessments/Create')
                ->where('risk.id', $this->risk->id)
                ->has('stages', 5)
                ->has('dimensions')
                ->has('likelihoodLabels')
                ->has('impactLabels')
                // Steps 6-7: both mapped controls, with the axis each acts on.
                ->has('controls', 2)
                ->has('effectivenessRatings', 5)
                ->has('strategies')
                ->has('users')
                ->where('assessment', null)
            );
    }

    #[Test]
    public function the_form_offers_only_the_dimensions_the_profile_scores(): void
    {
        // The reason the axes come from the scoring profile rather than a
        // hardcoded 5x5: a dimension the tenant does not score has nowhere to
        // be stored, so it is never asked for.
        $props = $this->actingAs($this->actor)
            ->get(route('risk.assessments.create', ['risk_id' => $this->risk->id]))
            ->inertiaProps();

        $codes = collect($props['dimensions'])->pluck('code')->all();

        $this->assertContains('financial', $codes);
        $this->assertNotContains('people', $codes);

        foreach ($props['dimensions'] as $dimension) {
            $this->assertArrayHasKey('label', $dimension);
            $this->assertArrayHasKey('hint', $dimension);
        }
    }

    #[Test]
    public function the_edit_form_is_the_same_page_prefilled(): void
    {
        $assessment = $this->makeAssessment(['likelihood_score' => 3, 'impact_financial' => 4]);

        $this->actingAs($this->actor)
            ->get(route('risk.assessments.edit', $assessment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assessments/Create')
                ->where('assessment.id', $assessment->id)
                ->where('assessment.likelihood', 3)
                ->where('assessment.impacts.financial', 4)
            );
    }

    #[Test]
    public function an_approved_assessment_cannot_be_edited(): void
    {
        $assessment = $this->makeAssessment(['status' => 'approved']);

        $this->actingAs($this->actor)
            ->get(route('risk.assessments.edit', $assessment))
            ->assertRedirect(route('risk.assessments.show', $assessment))
            ->assertSessionHas('error', 'Only draft or rejected assessments can be edited.');
    }

    #[Test]
    public function the_detail_page_draws_every_link_in_the_chain(): void
    {
        $this->actingAs($this->actor)->post(route('risk.assessments.store'), $this->validChain());

        $assessment = RiskAssessment::latest('id')->firstOrFail();

        $this->actingAs($this->actor)
            ->get(route('risk.assessments.show', $assessment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assessments/Show')
                ->where('assessment.id', $assessment->id)
                ->where('assessment.reference', 'ASS-'.str_pad((string) $assessment->id, 4, '0', STR_PAD_LEFT))
                ->where('risk.risk_code', $this->risk->risk_code)
                ->has('ratedControls', 2)
                ->has('effectiveness')
                ->has('actionPlans')
                ->has('kris')
                ->has('assessmentHistory', 1)
                ->has('dimensionData.labels', 5)
                ->has('comparisonData.labels', 6)
                ->where('can.update', true)
                ->where('can.submit', true)
            );
    }

    #[Test]
    public function the_form_computes_no_scores_of_its_own(): void
    {
        // Decision 5b. The Alpine component this replaced reimplemented impact
        // aggregation, effectiveness weighting, the axis split and the band
        // lookup in JavaScript, and could not evaluate a tenant's residual
        // formula. Every figure on the page now arrives from the preview
        // endpoint, which is the code that saves. This asserts there is no
        // second implementation to drift — a grep, deliberately, because the
        // failure mode is someone reintroducing the arithmetic for speed.
        // Comments are stripped first: both files EXPLAIN the arithmetic they
        // no longer do, and a grep that matched prose would fail on the very
        // sentence documenting the fix.
        $code = fn (string $path) => preg_replace(
            ['#/\*.*?\*/#s', '#^\s*//.*$#m'],
            '',
            (string) file_get_contents(resource_path($path)),
        );

        $page = $code('js/Pages/Assessments/Create.jsx');
        $hook = $code('js/hooks/useAssessmentPreview.js');

        foreach (['Math.pow', 'Math.max(...', 'reduce((a, b)', 'aggregation', 'effectiveness['] as $arithmetic) {
            $this->assertStringNotContainsString($arithmetic, $page, "the form is scoring the chain itself: {$arithmetic}");
            $this->assertStringNotContainsString($arithmetic, $hook, "the preview hook is scoring the chain itself: {$arithmetic}");
        }

        // And the Blade form, with its assessmentChain() mirror, is gone.
        $this->assertDirectoryDoesNotExist(resource_path('views/risk/assessments'));
    }

    /* ------------------------------------------------------------------ */
    /*  The chain, written */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function storing_writes_each_step_to_the_object_that_owns_it(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.assessments.store'), $this->validChain([
                'causes' => [[
                    'id' => null,
                    'description' => 'Legacy batch job with no retry.',
                    'source' => 'incident',
                    'is_primary' => true,
                ]],
                'actions' => [[
                    'action_title' => 'Add a retry with alerting',
                    'owner_id' => $this->actor->id,
                    'target_date' => '2026-09-30',
                    'priority' => 'high',
                ]],
                // The assessment's vocabulary, which is not the register's:
                // RiskAssessment::TREATMENT_STRATEGIES says `reduce` where
                // `risks.treatment_strategy` says `mitigate`.
                'treatment_strategy' => 'reduce',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $assessment = RiskAssessment::latest('id')->firstOrFail();

        // The assessment is the journey, not a duplicate store: each step is
        // in the table that owns it.
        $this->assertDatabaseHas('risk_causes', [
            'risk_id' => $this->risk->id,
            'description' => 'Legacy batch job with no retry.',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('risk_assessment_controls', [
            'risk_assessment_id' => $assessment->id,
            'control_id' => $this->preventive->id,
            'design_effectiveness' => 'effective',
        ]);
        $this->assertDatabaseHas('treatment_plans', [
            'risk_id' => $this->risk->id,
            'action_title' => 'Add a retry with alerting',
            'owner_id' => $this->actor->id,
        ]);

        // And the derived figures are on the assessment.
        $this->assertSame(20, (int) $assessment->overall_score);
        $this->assertNotNull($assessment->residual_score);
        $this->assertNotEmpty($assessment->cause_snapshot);
    }

    #[Test]
    public function submitting_from_the_form_moves_the_assessment_into_review(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.assessments.store'), $this->validChain(['action' => 'submit']))
            ->assertRedirect()
            ->assertSessionHas('success', 'Risk assessment submitted for review.');

        $this->assertSame('in_review', RiskAssessment::latest('id')->firstOrFail()->status);
    }

    #[Test]
    public function an_override_without_a_justification_is_refused(): void
    {
        // Checked after the service has decided whether the posted values
        // actually differ from the derivation, which is why it is not a rule
        // on the Form Request.
        $this->actingAs($this->actor)
            ->post(route('risk.assessments.store'), $this->validChain([
                'residual_likelihood' => 1,
                'residual_impact' => 1,
            ]))
            ->assertSessionHasErrors('residual_justification');
    }

    #[Test]
    public function echoing_back_the_derived_residual_is_not_an_override(): void
    {
        $preview = $this->actingAs($this->actor)
            ->postJson(route('risk.assessments.preview'), [
                'risk_id' => $this->risk->id,
                'likelihood' => 4,
                'impacts' => ['financial' => 5, 'operational' => 3],
                'controls' => $this->validChain()['controls'],
            ])->json();

        // A form pre-filled from the preview posts these values back. If that
        // counted as an override, every assessment would demand a
        // justification for agreeing with the arithmetic.
        $this->actingAs($this->actor)
            ->post(route('risk.assessments.store'), $this->validChain([
                'residual_likelihood' => $preview['residual']['likelihood'],
                'residual_impact' => $preview['residual']['impact'],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNotSame('override', RiskAssessment::latest('id')->firstOrFail()->residual_source);
    }

    #[Test]
    public function an_action_plan_needs_an_owner_and_a_due_date(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.assessments.store'), $this->validChain([
                'actions' => [['action_title' => 'Do something about it']],
            ]))
            ->assertSessionHasErrors(['actions.0.owner_id', 'actions.0.target_date']);

        $this->assertSame(0, TreatmentPlan::count());
    }

    #[Test]
    public function the_reviewer_can_approve_and_the_scores_reach_the_risk(): void
    {
        $this->actingAs($this->actor)->post(route('risk.assessments.store'), $this->validChain(['action' => 'submit']));

        $assessment = RiskAssessment::latest('id')->firstOrFail();
        $manager = $this->userWith(['assessment.approve', 'assessment.view'], 'approver@example.test', ['risk-manager']);

        $this->actingAs($manager)
            ->post(route('risk.assessments.approve', $assessment), ['comments' => 'Agreed.'])
            ->assertRedirect(route('risk.assessments.show', $assessment));

        $this->assertSame('approved', $assessment->fresh()->status);
        $this->assertSame((int) $assessment->overall_score, (int) $this->risk->fresh()->inherent_score);
    }
}

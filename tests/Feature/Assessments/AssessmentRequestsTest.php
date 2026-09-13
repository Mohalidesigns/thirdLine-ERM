<?php

namespace Tests\Feature\Assessments;

use App\Models\RiskCauseCategory;
use App\Models\ScoringProfile;
use App\Support\Scoring\ScoringProfileTemplates;
use PHPUnit\Framework\Attributes\Test;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Phase 3.3: the assessment Form Requests.
 *
 * The rules moved out of RiskAssessmentController::validateChain() unchanged
 * except for one thing, which is what most of this file is about: every
 * foreign key is now checked INSIDE THE TENANT.
 */
class AssessmentRequestsTest extends AssessmentsTestCase
{
    #[Test]
    public function no_form_request_uses_the_string_exists_rule(): void
    {
        foreach (glob(app_path('Http/Requests/Assessments/*.php')) as $file) {
            $this->assertStringNotContainsString(
                "'exists:",
                (string) file_get_contents($file),
                basename($file).' uses a string exists rule, which is not tenant-scoped',
            );
        }
    }

    #[Test]
    public function an_action_owner_from_another_organisation_is_rejected(): void
    {
        // `exists:users,id` accepted any user in the database, so a forged id
        // could make somebody at another bank the owner of an action plan —
        // and their name would then render on the treatment register.
        $this->actingAs($this->actor)
            ->post(route('risk.assessments.store'), $this->validChain([
                'actions' => [[
                    'action_title' => 'Something',
                    'owner_id' => $this->otherActor->id,
                    'target_date' => '2026-09-30',
                ]],
            ]))
            ->assertSessionHasErrors('actions.0.owner_id');
    }

    #[Test]
    public function a_kri_from_another_organisation_is_rejected(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.assessments.store'), $this->validChain([
                'kri_ids' => [$this->foreignKri->id],
            ]))
            ->assertSessionHasErrors('kri_ids.0');
    }

    #[Test]
    public function a_cause_category_from_another_organisation_is_rejected(): void
    {
        $theirs = TenantContext::bypass(fn () => RiskCauseCategory::create([
            'organization_id' => $this->otherOrg->id,
            'code' => 'THEIRS',
            'name' => 'Their taxonomy',
        ]), 'test fixture');

        $this->actingAs($this->actor)
            ->post(route('risk.assessments.store'), $this->validChain([
                'causes' => [['description' => 'A cause.', 'cause_category_id' => $theirs->id]],
            ]))
            ->assertSessionHasErrors('causes.0.cause_category_id');
    }

    #[Test]
    public function a_system_cause_category_is_accepted(): void
    {
        // Rows with a NULL organization_id are the shared taxonomy every
        // tenant inherits. A rule that demanded the caller's own id would
        // reject the defaults the form itself offers.
        $system = TenantContext::bypass(fn () => RiskCauseCategory::create([
            'organization_id' => null,
            'code' => 'PEOPLE',
            'name' => 'People',
        ]), 'test fixture');

        $this->actingAs($this->actor)
            ->post(route('risk.assessments.store'), $this->validChain([
                'causes' => [['description' => 'A cause.', 'cause_category_id' => $system->id]],
            ]))
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_risk_from_another_organisation_cannot_be_assessed(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.assessments.store'), $this->validChain(['risk_id' => $this->foreignRisk->id]))
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /*  The chain's own rules */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_chain_requires_a_likelihood_and_a_rationale(): void
    {
        $chain = $this->validChain();
        unset($chain['likelihood'], $chain['rationale']);

        $this->actingAs($this->actor)
            ->post(route('risk.assessments.store'), $chain)
            ->assertSessionHasErrors([
                'likelihood' => 'Likelihood is required — it is step 3 of the assessment.',
                'rationale' => 'An assessment rationale is required.',
            ]);
    }

    #[Test]
    public function at_least_one_impact_dimension_has_to_be_scored(): void
    {
        $chain = $this->validChain();
        unset($chain['impact_financial'], $chain['impact_operational']);

        $this->actingAs($this->actor)
            ->post(route('risk.assessments.store'), $chain)
            ->assertSessionHasErrors(['impact_financial' => 'Score at least one impact dimension.']);
    }

    #[Test]
    public function the_axis_bounds_come_from_the_scoring_profile(): void
    {
        // A tenant on a 4x4 matrix is validated against the grid they use,
        // not against a hardcoded 1-5.
        ScoringProfile::create(ScoringProfileTemplates::default('NGN', [
            'organization_id' => $this->organization->id,
            'code' => 'four-by-four',
            'is_system' => false,
            'matrix_rows' => 4,
            'matrix_cols' => 4,
        ]));
        ScoringProfile::flushResolutionCache();

        $this->actingAs($this->actor)
            ->post(route('risk.assessments.store'), $this->validChain([
                'likelihood' => 5,
                'impact_financial' => 5,
            ]))
            ->assertSessionHasErrors(['likelihood', 'impact_financial']);
    }

    #[Test]
    public function an_unknown_effectiveness_rating_is_rejected(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.assessments.store'), $this->validChain([
                'controls' => [$this->preventive->id => ['design_effectiveness' => 'excellent']],
            ]))
            ->assertSessionHasErrors("controls.{$this->preventive->id}.design_effectiveness");
    }

    #[Test]
    public function the_create_route_refuses_a_caller_without_the_permission(): void
    {
        $viewer = $this->userWith(['assessment.view'], 'viewer-req@example.test');

        $this->actingAs($viewer)->get(route('risk.assessments.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('risk.assessments.store'), $this->validChain())->assertForbidden();
        $this->actingAs($viewer)->postJson(route('risk.assessments.preview'), ['risk_id' => $this->risk->id])->assertForbidden();
    }
}

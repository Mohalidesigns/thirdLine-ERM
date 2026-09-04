<?php

namespace Tests\Feature\Rcsa;

use App\Events\RcsaWorksheetSubmitted;
use App\Models\AssessmentCampaign;
use App\Models\CampaignAssignment;
use App\Models\CampaignResponse;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Filing a worksheet (migration Phase 3.8).
 *
 * This write path once consisted of a comment and a redirect carrying a
 * success message — every worksheet was discarded while the screen said it had
 * been saved. These tests exist so that can never be true again quietly.
 */
class WorksheetFilingTest extends RcsaTestCase
{
    #[Test]
    public function a_submitted_worksheet_is_recorded_against_a_campaign(): void
    {
        Event::fake([RcsaWorksheetSubmitted::class]);

        $this->actingAs($this->actor)
            ->post(route('risk.rcsa.worksheet.store'), $this->validWorksheet())
            ->assertRedirect();

        $assignment = CampaignAssignment::firstOrFail();

        $this->assertSame($this->unit->id, $assignment->business_unit_id);
        $this->assertSame($this->actor->id, $assignment->respondent_id);
        $this->assertSame('submitted', $assignment->status);
        $this->assertNotNull($assignment->submitted_at);

        $response = CampaignResponse::firstOrFail();

        // The scored position is the RESIDUAL one.
        $this->assertSame(2, $response->likelihood_score);
        $this->assertSame(3, $response->impact_score);
        $this->assertSame(6, $response->overall_score);
        $this->assertSame('partially_effective', $response->control_effectiveness);

        // The inherent pair is preserved so the reduction stays auditable.
        $data = $response->questionnaire_data;
        $this->assertSame(4, $data['inherent_likelihood']);
        $this->assertSame(5, $data['inherent_impact']);
        $this->assertSame(20, $data['inherent_score']);
        $this->assertSame(6, $data['residual_score']);
        $this->assertSame($this->process->id, $data['process_id']);

        Event::assertDispatched(RcsaWorksheetSubmitted::class);
    }

    /** An organisation with no campaign set up still gets its work kept. */
    #[Test]
    public function a_worksheet_with_no_campaign_opens_an_ad_hoc_one(): void
    {
        $this->assertSame(0, AssessmentCampaign::count());

        $this->actingAs($this->actor)
            ->post(route('risk.rcsa.worksheet.store'), $this->validWorksheet())
            ->assertRedirect();

        $campaign = AssessmentCampaign::firstOrFail();

        $this->assertSame('rcsa', $campaign->campaign_type);
        $this->assertSame($this->organization->id, $campaign->organization_id);
        $this->assertSame(1, CampaignResponse::count());
    }

    #[Test]
    public function a_draft_is_stored_without_submitting_or_raising_the_event(): void
    {
        Event::fake([RcsaWorksheetSubmitted::class]);

        $this->actingAs($this->actor)
            ->post(route('risk.rcsa.worksheet.store'), $this->validWorksheet(['action' => 'draft']))
            ->assertRedirect();

        $assignment = CampaignAssignment::firstOrFail();

        $this->assertSame('in_progress', $assignment->status);
        $this->assertNull($assignment->submitted_at);
        $this->assertSame(1, CampaignResponse::count());

        Event::assertNotDispatched(RcsaWorksheetSubmitted::class);
    }

    /**
     * A corrected worksheet REPLACES its lines. Appending would leave the
     * superseded scores in place and double-count the unit.
     */
    #[Test]
    public function resubmitting_replaces_the_previous_lines(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.rcsa.worksheet.store'), $this->validWorksheet())
            ->assertRedirect();

        $this->assertSame(1, CampaignResponse::count());

        $revised = $this->validWorksheet();
        $revised['risks'][] = [
            'description' => 'A second line found on review.',
            'inherent_likelihood' => 2,
            'inherent_impact' => 2,
            'residual_likelihood' => 1,
            'residual_impact' => 1,
        ];

        $this->actingAs($this->actor)
            ->post(route('risk.rcsa.worksheet.store'), $revised)
            ->assertRedirect();

        $this->assertSame(1, CampaignAssignment::count(), 'the same person revising the same unit keeps one assignment');
        $this->assertSame(2, CampaignResponse::count(), 'two lines, not three');
    }

    #[Test]
    public function a_worksheet_line_can_be_linked_to_a_register_risk(): void
    {
        $risk = $this->makeRisk(['risk_code' => 'RK-LINK']);

        $payload = $this->validWorksheet();
        $payload['risks'][0]['risk_id'] = $risk->id;

        $this->actingAs($this->actor)
            ->post(route('risk.rcsa.worksheet.store'), $payload)
            ->assertRedirect();

        $this->assertSame($risk->id, CampaignResponse::firstOrFail()->risk_id);
    }

    #[Test]
    public function another_tenants_ids_are_rejected(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.rcsa.worksheet.store'), $this->validWorksheet([
                'business_unit_id' => $this->foreignUnit->id,
            ]))
            ->assertSessionHasErrors('business_unit_id');

        $this->assertSame(0, CampaignResponse::count());
    }

    #[Test]
    public function a_worksheet_needs_at_least_one_described_line(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.rcsa.worksheet.store'), $this->validWorksheet(['risks' => []]))
            ->assertSessionHasErrors('risks');

        $payload = $this->validWorksheet();
        $payload['risks'][0]['description'] = '';

        $this->actingAs($this->actor)
            ->post(route('risk.rcsa.worksheet.store'), $payload)
            ->assertSessionHasErrors('risks.0.description');

        $this->assertSame(0, CampaignResponse::count());
    }

    #[Test]
    public function scores_outside_one_to_five_are_rejected(): void
    {
        $payload = $this->validWorksheet();
        $payload['risks'][0]['residual_impact'] = 9;

        $this->actingAs($this->actor)
            ->post(route('risk.rcsa.worksheet.store'), $payload)
            ->assertSessionHasErrors('risks.0.residual_impact');
    }

    #[Test]
    public function a_user_without_rcsa_submit_cannot_file(): void
    {
        $reader = $this->userWith(['rcsa.view'], 'reader@example.test');

        $this->actingAs($reader)
            ->post(route('risk.rcsa.worksheet.store'), $this->validWorksheet())
            ->assertForbidden();

        $this->assertSame(0, CampaignResponse::count());
    }

    /**
     * The rating band comes from RiskScoringService, which is also what the
     * campaigns module uses. A private copy here once used >= 6 for Medium
     * while the service used >= 5, so a risk scoring exactly 5 was Medium or
     * Low depending on which screen recorded it.
     */
    #[Test]
    public function the_rating_band_matches_the_scoring_service(): void
    {
        $payload = $this->validWorksheet();
        $payload['risks'][0]['residual_likelihood'] = 5;
        $payload['risks'][0]['residual_impact'] = 1;

        $this->actingAs($this->actor)
            ->post(route('risk.rcsa.worksheet.store'), $payload)
            ->assertRedirect();

        $response = CampaignResponse::firstOrFail();

        $this->assertSame(5, $response->overall_score);
        $this->assertSame(
            app(\App\Services\RiskScoringService::class)->calculateRating(5),
            $response->rating,
        );
    }

    /** The whole worksheet is one transaction: a bad line keeps nothing. */
    #[Test]
    public function a_rejected_worksheet_writes_nothing_at_all(): void
    {
        $payload = $this->validWorksheet();
        $payload['risks'][] = [
            'description' => 'Second line, bad score.',
            'inherent_likelihood' => 3,
            'inherent_impact' => 3,
            'residual_likelihood' => 3,
            'residual_impact' => 77,
        ];

        $this->actingAs($this->actor)
            ->post(route('risk.rcsa.worksheet.store'), $payload)
            ->assertSessionHasErrors('risks.1.residual_impact');

        $this->assertSame(0, CampaignAssignment::count());
        $this->assertSame(0, CampaignResponse::count());
        $this->assertSame(0, AssessmentCampaign::count());
    }
}

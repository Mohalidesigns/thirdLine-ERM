<?php

namespace Tests\Feature;

use App\Events\RcsaWorksheetSubmitted;
use App\Models\AssessmentCampaign;
use App\Models\BusinessUnit;
use App\Models\CampaignAssignment;
use App\Models\CampaignResponse;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The RCSA worksheet used to accept a submission, discard it, and report
 * success. These tests exist so that can never silently happen again — the
 * central assertion is simply that rows exist after a POST.
 */
class RcsaWorksheetSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private BusinessUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->org = Organization::create([
            'name' => 'RCSA Bank PLC',
            'short_name' => 'RCSA',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::set($this->org->id);

        $this->user = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Unit Respondent',
            'email' => 'respondent@rcsa.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->user->assignRole('chief-risk-officer');

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->org->id,
            'code' => 'RTL',
            'name' => 'Retail Banking',
        ]);
    }

    /**
     * @param  array<int, array<string,mixed>>|null  $risks
     * @return array<string,mixed>
     */
    private function payload(array $overrides = [], ?array $risks = null): array
    {
        return array_merge([
            'business_unit_id' => $this->unit->id,
            'assessment_date' => now()->toDateString(),
            'action' => 'submit',
            'risks' => $risks ?? [
                [
                    'description' => 'Manual reconciliation errors in branch settlement',
                    'category' => 'Operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 5,
                    'residual_likelihood' => 3,
                    'residual_impact' => 4,
                    'control_effectiveness' => 'partially_effective',
                    'existing_controls' => 'Daily four-eye review of settlement file',
                    'action_plan' => 'Automate the reconciliation by Q4',
                ],
            ],
        ], $overrides);
    }

    #[Test]
    public function a_submitted_worksheet_persists_campaign_responses(): void
    {
        // The respondent is returned to the submission itself, not to a fresh
        // worksheet: a worksheet becomes a campaign assignment rather than a
        // register risk, so the blank form gave no sign of where it had gone.
        $this->actingAs($this->user)
            ->post(route('risk.rcsa.worksheet.store'), $this->payload())
            ->assertRedirect(route('risk.campaigns.submission', CampaignAssignment::firstOrFail()))
            ->assertSessionHas('success');

        $this->assertSame(1, CampaignResponse::count(), 'The worksheet line must be persisted.');

        $response = CampaignResponse::first();

        // Residual position is what gets scored.
        $this->assertSame(3, $response->likelihood_score);
        $this->assertSame(4, $response->impact_score);
        $this->assertSame(12, $response->overall_score);
        $this->assertSame('High', $response->rating);
        $this->assertSame('partially_effective', $response->control_effectiveness);
        $this->assertSame('Automate the reconciliation by Q4', $response->comments);

        // The inherent pair stays auditable alongside it.
        $this->assertSame(4, $response->questionnaire_data['inherent_likelihood']);
        $this->assertSame(5, $response->questionnaire_data['inherent_impact']);
        $this->assertSame(20, $response->questionnaire_data['inherent_score']);
        $this->assertSame('Critical', $response->questionnaire_data['inherent_rating']);
        $this->assertSame(
            'Manual reconciliation errors in branch settlement',
            $response->questionnaire_data['description']
        );
    }

    #[Test]
    public function a_submission_creates_an_assignment_and_marks_it_submitted(): void
    {
        $this->actingAs($this->user)->post(route('risk.rcsa.worksheet.store'), $this->payload());

        $assignment = CampaignAssignment::firstOrFail();

        $this->assertSame($this->unit->id, $assignment->business_unit_id);
        $this->assertSame($this->user->id, $assignment->respondent_id);
        $this->assertSame('submitted', $assignment->status);
        $this->assertNotNull($assignment->submitted_at);
        $this->assertNotNull($assignment->started_at);
    }

    #[Test]
    public function saving_a_draft_records_the_lines_but_does_not_submit(): void
    {
        Event::fake([RcsaWorksheetSubmitted::class]);

        $this->actingAs($this->user)
            ->post(route('risk.rcsa.worksheet.store'), $this->payload(['action' => 'draft']));

        $this->assertSame(1, CampaignResponse::count(), 'A draft still has to be saved.');

        $assignment = CampaignAssignment::firstOrFail();
        $this->assertSame('in_progress', $assignment->status);
        $this->assertNull($assignment->submitted_at);

        Event::assertNotDispatched(RcsaWorksheetSubmitted::class);
    }

    #[Test]
    public function a_submission_dispatches_the_domain_event(): void
    {
        Event::fake([RcsaWorksheetSubmitted::class]);

        $this->actingAs($this->user)->post(route('risk.rcsa.worksheet.store'), $this->payload());

        Event::assertDispatched(
            RcsaWorksheetSubmitted::class,
            fn (RcsaWorksheetSubmitted $event) => $event->responseCount === 1
                && $event->assignment->respondent_id === $this->user->id
        );
    }

    #[Test]
    public function the_campaign_completion_percentage_is_recalculated(): void
    {
        $campaign = $this->makeCampaign();

        $this->actingAs($this->user)
            ->post(route('risk.rcsa.worksheet.store'), $this->payload(['campaign_id' => $campaign->id]));

        $campaign->refresh();

        // One assignment exists; it is submitted, not yet approved, so
        // completion stays at zero under the campaign's own definition of
        // "completed" (approved). The counters must still have been refreshed.
        $this->assertSame(1, $campaign->total_assignments);
        $this->assertSame(0, $campaign->completed_assignments);
        $this->assertEquals(0.0, (float) $campaign->completion_pct);

        // Approving it moves the needle, proving the recalculation is live.
        CampaignAssignment::firstOrFail()->update(['status' => 'approved']);
        $campaign->recalculateProgress();
        $campaign->refresh();

        $this->assertSame(1, $campaign->completed_assignments);
        $this->assertEquals(100.0, (float) $campaign->completion_pct);
    }

    #[Test]
    public function multiple_risk_lines_are_all_recorded(): void
    {
        $risks = [];
        foreach ([[1, 1], [3, 3], [5, 5]] as $i => [$likelihood, $impact]) {
            $risks[] = [
                'description' => 'Risk line '.$i,
                'inherent_likelihood' => 5,
                'inherent_impact' => 5,
                'residual_likelihood' => $likelihood,
                'residual_impact' => $impact,
            ];
        }

        $this->actingAs($this->user)
            ->post(route('risk.rcsa.worksheet.store'), $this->payload([], $risks));

        $this->assertSame(3, CampaignResponse::count());

        $ratings = CampaignResponse::orderBy('overall_score')->pluck('rating')->all();
        $this->assertSame(['Low', 'Medium', 'Critical'], $ratings);
    }

    #[Test]
    public function resubmitting_replaces_the_previous_lines_rather_than_appending(): void
    {
        $campaign = $this->makeCampaign();

        $this->actingAs($this->user)
            ->post(route('risk.rcsa.worksheet.store'), $this->payload(['campaign_id' => $campaign->id]));

        $this->assertSame(1, CampaignResponse::count());

        $revised = [
            [
                'description' => 'Revised assessment of the same exposure',
                'inherent_likelihood' => 2,
                'inherent_impact' => 2,
                'residual_likelihood' => 1,
                'residual_impact' => 2,
            ],
        ];

        $this->actingAs($this->user)
            ->post(route('risk.rcsa.worksheet.store'), $this->payload(['campaign_id' => $campaign->id], $revised));

        $this->assertSame(1, CampaignResponse::count(), 'A correction must supersede, not double-count.');
        $this->assertSame(1, CampaignAssignment::count());
        $this->assertSame(2, CampaignResponse::first()->overall_score);
    }

    #[Test]
    public function a_worksheet_is_still_recorded_when_no_campaign_exists(): void
    {
        $this->assertSame(0, AssessmentCampaign::count());

        $this->actingAs($this->user)
            ->post(route('risk.rcsa.worksheet.store'), $this->payload())
            ->assertSessionHas('success');

        // An ad-hoc campaign is opened rather than the submission being lost.
        $campaign = AssessmentCampaign::firstOrFail();
        $this->assertSame('rcsa', $campaign->campaign_type);
        $this->assertSame($this->org->id, $campaign->organization_id);
        $this->assertMatchesRegularExpression('/^RCSA-\d{4}-\d{4}$/', $campaign->campaign_code);
        $this->assertSame(1, CampaignResponse::count());
    }

    #[Test]
    public function an_invalid_worksheet_is_rejected_and_nothing_is_written(): void
    {
        $this->actingAs($this->user)
            ->post(route('risk.rcsa.worksheet.store'), $this->payload([], [
                [
                    'description' => 'Out of range scores',
                    'inherent_likelihood' => 9,
                    'inherent_impact' => 5,
                    'residual_likelihood' => 3,
                    'residual_impact' => 4,
                ],
            ]))
            ->assertSessionHasErrors('risks.0.inherent_likelihood');

        $this->assertSame(0, CampaignResponse::count());
        $this->assertSame(0, CampaignAssignment::count());
    }

    #[Test]
    public function a_worksheet_cannot_be_filed_against_another_tenants_business_unit(): void
    {
        $other = Organization::create([
            'name' => 'Rival Bank PLC',
            'short_name' => 'RIVL',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $foreignUnit = BusinessUnit::withoutGlobalScopes()->create([
            'organization_id' => $other->id,
            'code' => 'FGN',
            'name' => 'Foreign unit',
        ]);

        $this->actingAs($this->user)
            ->post(route('risk.rcsa.worksheet.store'), $this->payload(['business_unit_id' => $foreignUnit->id]))
            ->assertSessionHasErrors('business_unit_id');

        $this->assertSame(0, CampaignResponse::count());
    }

    #[Test]
    public function the_submission_screen_reads_back_the_lines_that_were_filed(): void
    {
        $this->actingAs($this->user)->post(route('risk.rcsa.worksheet.store'), $this->payload());

        $assignment = CampaignAssignment::firstOrFail();

        // Everything the respondent typed has to come back out. The respond
        // screen builds itself from register risks and shows none of it, which
        // is what made a filed worksheet look discarded.
        $this->actingAs($this->user)
            ->get(route('risk.campaigns.submission', $assignment))
            ->assertOk()
            ->assertSee('Manual reconciliation errors in branch settlement')
            ->assertSee('Daily four-eye review of settlement file')
            ->assertSee('Automate the reconciliation by Q4')
            ->assertSee('Retail Banking')
            ->assertSee('Critical')   // inherent rating, from questionnaire_data
            ->assertSee('High');      // residual rating, from the scored columns
    }

    #[Test]
    public function the_worksheet_screen_lists_the_respondents_own_submissions(): void
    {
        $this->actingAs($this->user)->post(route('risk.rcsa.worksheet.store'), $this->payload());

        $this->actingAs($this->user)
            ->get(route('risk.rcsa.worksheet'))
            ->assertOk()
            ->assertSee('Your recent worksheets')
            ->assertSee('Retail Banking');
    }

    #[Test]
    public function a_submission_from_another_tenant_is_not_readable(): void
    {
        $other = Organization::create([
            'name' => 'Rival Bank PLC',
            'short_name' => 'RIVL',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        // campaign_assignments has no organization_id of its own, so route
        // model binding on {assignment} will happily resolve a foreign row —
        // the guard has to come from the campaign it hangs off.
        $foreignCampaign = AssessmentCampaign::withoutGlobalScopes()->create([
            'organization_id' => $other->id,
            'campaign_code' => 'RCSA-2026-9999',
            'title' => 'Rival RCSA',
            'campaign_type' => 'rcsa',
            'status' => 'active',
            'start_date' => now()->startOfYear(),
            'end_date' => now()->endOfYear(),
            'created_by' => $this->user->id,
        ]);

        $foreignUnit = BusinessUnit::withoutGlobalScopes()->create([
            'organization_id' => $other->id,
            'code' => 'FGN',
            'name' => 'Foreign unit',
        ]);

        $foreignAssignment = CampaignAssignment::create([
            'campaign_id' => $foreignCampaign->id,
            'business_unit_id' => $foreignUnit->id,
            'respondent_id' => $this->user->id,
            'due_date' => now()->addMonth(),
            'status' => 'submitted',
        ]);

        $this->actingAs($this->user)
            ->get(route('risk.campaigns.submission', $foreignAssignment))
            ->assertNotFound();

        $this->actingAs($this->user)
            ->get(route('risk.campaigns.respond', $foreignAssignment))
            ->assertNotFound();

        $this->actingAs($this->user)
            ->post(route('risk.campaigns.review-assignment', $foreignAssignment), ['action' => 'approve'])
            ->assertNotFound();

        $this->assertSame('submitted', $foreignAssignment->fresh()->status);
    }

    #[Test]
    public function submitted_work_shows_as_its_own_progress_segment_without_counting_as_complete(): void
    {
        $this->actingAs($this->user)->post(route('risk.rcsa.worksheet.store'), $this->payload());

        $campaign = AssessmentCampaign::firstOrFail();
        $breakdown = $campaign->fresh()->progressBreakdown();

        // Handed in, nobody has reviewed it: visible on the bar, but not
        // completion. completion_pct is averaged on the dashboard, sorted on in
        // the grid and published through the API — it has to stay assurance.
        $this->assertSame(1, $breakdown['total']);
        $this->assertSame(0, $breakdown['completed']);
        $this->assertSame(1, $breakdown['awaiting_review']);
        $this->assertEqualsWithDelta(0.0, $breakdown['completed_pct'], 0.01);
        $this->assertEqualsWithDelta(100.0, $breakdown['awaiting_review_pct'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $campaign->fresh()->completion_pct, 0.01);

        $this->actingAs($this->user)
            ->get(route('risk.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('awaiting review');

        // Approving moves it across to the completed segment.
        $this->actingAs($this->user)->post(
            route('risk.campaigns.review-assignment', CampaignAssignment::firstOrFail()),
            ['action' => 'approve']
        );

        $breakdown = $campaign->fresh()->progressBreakdown();

        $this->assertSame(1, $breakdown['completed']);
        $this->assertSame(0, $breakdown['awaiting_review']);
        $this->assertEqualsWithDelta(100.0, (float) $campaign->fresh()->completion_pct, 0.01);
    }

    #[Test]
    public function the_progress_breakdown_agrees_however_the_campaign_was_loaded(): void
    {
        $this->actingAs($this->user)->post(route('risk.rcsa.worksheet.store'), $this->payload());

        $id = AssessmentCampaign::firstOrFail()->id;

        // Three resolution paths: an eager-loaded relation, the counts from
        // withProgressCounts(), and a bare model that has to query.
        $fromRelation = AssessmentCampaign::with('assignments')->findOrFail($id)->progressBreakdown();
        $fromCounts = AssessmentCampaign::withProgressCounts()->findOrFail($id)->progressBreakdown();
        $fromQuery = AssessmentCampaign::findOrFail($id)->progressBreakdown();

        $this->assertSame($fromRelation, $fromCounts);
        $this->assertSame($fromRelation, $fromQuery);
        $this->assertSame(1, $fromRelation['awaiting_review']);
    }

    private function makeCampaign(): AssessmentCampaign
    {
        return AssessmentCampaign::create([
            'organization_id' => $this->org->id,
            'campaign_code' => 'RCSA-2026-0001',
            'title' => 'Annual RCSA',
            'campaign_type' => 'rcsa',
            'status' => 'active',
            'start_date' => now()->startOfYear(),
            'end_date' => now()->endOfYear(),
            'created_by' => $this->user->id,
        ]);
    }
}

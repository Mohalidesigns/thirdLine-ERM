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
        $this->actingAs($this->user)
            ->post(route('risk.rcsa.worksheet.store'), $this->payload())
            ->assertRedirect(route('risk.rcsa.worksheet'))
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

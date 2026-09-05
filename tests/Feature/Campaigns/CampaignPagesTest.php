<?php

namespace Tests\Feature\Campaigns;

use App\Models\AssessmentCampaign;
use App\Models\BusinessUnit;
use App\Models\CampaignAssignment;
use App\Models\CampaignResponse;
use App\Models\Question;
use App\Models\Questionnaire;
use App\Models\QuestionnaireSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The eight campaign and questionnaire screens on Inertia (Phase 4.5).
 *
 * The bulk of this file is the QUESTIONNAIRE, because until this phase nothing
 * rendered one. CampaignController::respond() eager-loaded
 * `campaign.questionnaire.sections.questions` from the day it was written and
 * risk/campaigns/respond.blade.php referenced not one line of it, so a bank
 * could build a questionnaire, publish it, attach it to a campaign, send two
 * hundred people to answer it, and every one of them was shown a list of
 * register risks instead. `questionnaire_data` was accepted by submitResponse()
 * and no control on the page could produce it.
 */
class CampaignPagesTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private User $officer;

    private BusinessUnit $unit;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach ([
            'campaign.view', 'campaign.create', 'campaign.manage', 'campaign.respond', 'campaign.review',
            'questionnaire.view', 'questionnaire.create', 'questionnaire.edit', 'questionnaire.publish',
        ] as $permission) {
            Permission::findOrCreate($permission);
        }

        $this->officer = User::create([
            'name' => 'Campaign Officer',
            'email' => 'officer-'.$this->organization->id.'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $this->officer->givePermissionTo(Permission::all());

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  The screens */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_dashboard_renders_its_tiles_and_recent_campaigns(): void
    {
        $campaign = $this->campaign(['status' => 'active', 'title' => 'Q1 RCSA']);
        $this->assignment($campaign, ['status' => 'approved']);
        $campaign->recalculateProgress();

        $this->actingAs($this->officer)
            ->get(route('risk.campaigns.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Campaigns/Dashboard')
                ->where('stats.activeCampaigns', 1)
                ->where('stats.totalCampaigns', 1)
                ->where('stats.pendingReview', 0)
                ->where('canCreate', true)
                ->has('campaigns', 1, fn (Assert $row) => $row
                    ->where('title', 'Q1 RCSA')
                    ->where('assignmentsCount', 1)
                    ->where('progress.completed', 1)
                    ->etc()));
    }

    #[Test]
    public function the_create_screen_offers_published_questionnaires_only(): void
    {
        $this->questionnaire('Draft set', 'draft');
        $this->questionnaire('Published set', 'published');

        $this->actingAs($this->officer)
            ->get(route('risk.campaigns.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Campaigns/Create')
                ->has('questionnaires', 1)
                ->where('questionnaires.0.title', 'Published set')
                ->has('users')
                ->where('campaignTypes.rcsa', 'RCSA'));
    }

    /**
     * The Blade template queried BusinessUnit and User inside itself — a query
     * the controller could not see, scope or test. Both are props now.
     */
    #[Test]
    public function the_campaign_screen_carries_its_assignments_and_its_pick_lists(): void
    {
        $campaign = $this->campaign(['status' => 'active']);
        $assignment = $this->assignment($campaign, ['status' => 'submitted']);

        $this->actingAs($this->officer)
            ->get(route('risk.campaigns.show', $campaign))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Campaigns/Show')
                ->where('campaign.code', $campaign->campaign_code)
                ->where('can.manage', true)
                ->where('can.review', true)
                ->has('businessUnits', 1)
                ->has('users')
                ->has('assignments', 1, fn (Assert $row) => $row
                    ->where('id', $assignment->id)
                    ->where('businessUnit', 'Retail Banking')
                    ->where('status', 'submitted')
                    // Submitted work is not overdue, whatever the due date.
                    ->where('overdue', false)
                    ->etc()));
    }

    #[Test]
    public function the_questionnaire_builder_and_preview_render(): void
    {
        $questionnaire = $this->questionnaire('Operational Risk');
        $section = QuestionnaireSection::create(['questionnaire_id' => $questionnaire->id, 'title' => 'Controls']);
        Question::create([
            'section_id' => $section->id,
            'question_type' => 'yes_no',
            'question_text' => 'Are reconciliations performed daily?',
            'is_required' => true,
        ]);

        $this->actingAs($this->officer)
            ->get(route('risk.questionnaires.edit', $questionnaire))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questionnaires/Edit')
                ->where('canPublish', true)
                ->where('questionTypes.likert', 'Likert Scale')
                // matrix and file_upload are not offered: nothing in this
                // product can render an answer control for either.
                ->missing('questionTypes.matrix')
                ->missing('questionTypes.file_upload')
                ->has('sections', 1, fn (Assert $s) => $s
                    ->where('title', 'Controls')
                    ->has('questions', 1, fn (Assert $q) => $q
                        ->where('text', 'Are reconciliations performed daily?')
                        ->where('isRequired', true)
                        ->etc())
                    ->etc()));

        $this->actingAs($this->officer)
            ->get(route('risk.questionnaires.show', $questionnaire))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questionnaires/Show')
                ->where('questionnaire.title', 'Operational Risk')
                ->has('sections', 1));

        $this->actingAs($this->officer)
            ->get(route('risk.questionnaires.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questionnaires/Create')
                ->where('types.0', 'rcsa')
                ->where('scoringMethods.0', 'average'));
    }

    /* ------------------------------------------------------------------ */
    /*  The questionnaire, which had never reached a respondent */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_respond_screen_carries_the_campaigns_questionnaire(): void
    {
        [$campaign, $assignment] = $this->campaignWithQuestionnaire();

        $this->makeRisk(['risk_code' => 'R-001', 'title' => 'Settlement failure', 'business_unit_id' => $this->unit->id]);

        $this->actingAs($this->officer)
            ->get(route('risk.campaigns.respond', $assignment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Campaigns/Respond')
                ->where('campaign.code', $campaign->campaign_code)
                ->where('questionnaire.title', 'Fraud Risk Questionnaire')
                ->has('questionnaire.sections', 1, fn (Assert $section) => $section
                    ->where('title', 'Detection')
                    ->has('questions', 2, fn (Assert $question) => $question
                        ->where('text', 'How effective is transaction monitoring?')
                        ->where('type', 'likert')
                        ->where('isRequired', true)
                        ->has('options', 5)
                        ->etc())
                    ->etc())
                ->has('risks', 1)
                ->etc());
    }

    #[Test]
    public function a_campaign_without_a_questionnaire_carries_none(): void
    {
        $campaign = $this->campaign(['status' => 'active']);
        $assignment = $this->assignment($campaign);

        $this->actingAs($this->officer)
            ->get(route('risk.campaigns.respond', $assignment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Campaigns/Respond')
                ->where('questionnaire', null)
                ->etc());
    }

    /**
     * The answers land as ONE row, risk_id null, with each question's TEXT,
     * SECTION and TYPE stored beside its value — see QuestionnaireAnswerSheet
     * for why the text is stored rather than resolved at display time.
     */
    #[Test]
    public function questionnaire_answers_are_stored_with_the_questions_that_were_asked(): void
    {
        [, $assignment, $questions] = $this->campaignWithQuestionnaire();

        $this->actingAs($this->officer)
            ->post(route('risk.campaigns.submit-response', $assignment), [
                'responses' => [['likelihood_score' => 3, 'impact_score' => 4]],
                'questionnaire_answers' => [
                    $questions['likert']->id => '4',
                    $questions['free']->id => 'Monitoring rules were last tuned in 2024.',
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $sheet = CampaignResponse::where('assignment_id', $assignment->id)
            ->get()
            ->first(fn (CampaignResponse $r) => isset($r->questionnaire_data['answers']));

        $this->assertNotNull($sheet, 'The answers must be stored.');
        $this->assertNull($sheet->risk_id);

        $answers = collect($sheet->questionnaire_data['answers'])->keyBy('question_id');

        $this->assertSame('Detection', $answers[$questions['likert']->id]['section']);
        $this->assertSame('How effective is transaction monitoring?', $answers[$questions['likert']->id]['question']);
        $this->assertSame('likert', $answers[$questions['likert']->id]['type']);
        $this->assertSame('4', $answers[$questions['likert']->id]['value']);
        // The label is what the respondent SAW; "4" on its own is not an answer.
        $this->assertSame('Agree', $answers[$questions['likert']->id]['label']);

        $this->assertSame('Monitoring rules were last tuned in 2024.', $answers[$questions['free']->id]['value']);

        // The risk line is still its own row.
        $this->assertSame(2, CampaignResponse::where('assignment_id', $assignment->id)->count());
    }

    #[Test]
    public function a_required_question_must_be_answered(): void
    {
        [, $assignment, $questions] = $this->campaignWithQuestionnaire();

        $this->actingAs($this->officer)
            ->post(route('risk.campaigns.submit-response', $assignment), [
                'responses' => [['likelihood_score' => 3, 'impact_score' => 4]],
                'questionnaire_answers' => [$questions['free']->id => 'Only the optional one.'],
            ])
            ->assertSessionHasErrors("questionnaire_answers.{$questions['likert']->id}");

        $this->assertSame(0, CampaignResponse::count());
        // Nothing was filed, and the assignment has not been opened either —
        // it is respond() that moves a pending assignment to in_progress.
        $this->assertSame('pending', $assignment->fresh()->status);
    }

    /**
     * The ids come off a form; the questionnaire is the only authority on what
     * was asked.
     */
    #[Test]
    public function an_answer_to_a_question_that_is_not_on_this_questionnaire_is_dropped(): void
    {
        [, $assignment, $questions] = $this->campaignWithQuestionnaire();

        $strayQuestionnaire = $this->questionnaire('Somewhere else', 'published');
        $stray = Question::create([
            'section_id' => QuestionnaireSection::create([
                'questionnaire_id' => $strayQuestionnaire->id, 'title' => 'Other',
            ])->id,
            'question_type' => 'free_text',
            'question_text' => 'Not on this campaign',
        ]);

        $this->actingAs($this->officer)
            ->post(route('risk.campaigns.submit-response', $assignment), [
                'responses' => [['likelihood_score' => 3, 'impact_score' => 4]],
                'questionnaire_answers' => [
                    $questions['likert']->id => '4',
                    $stray->id => 'Should not be stored',
                ],
            ])
            ->assertSessionHasNoErrors();

        $sheet = CampaignResponse::whereNotNull('questionnaire_data')->get()
            ->first(fn (CampaignResponse $r) => isset($r->questionnaire_data['answers']));

        $ids = collect($sheet->questionnaire_data['answers'])->pluck('question_id')->all();

        $this->assertSame([$questions['likert']->id], $ids);
    }

    #[Test]
    public function the_submission_screen_reads_the_answers_back_grouped_by_section(): void
    {
        [, $assignment, $questions] = $this->campaignWithQuestionnaire();

        $this->actingAs($this->officer)->post(route('risk.campaigns.submit-response', $assignment), [
            'responses' => [['likelihood_score' => 3, 'impact_score' => 4]],
            'questionnaire_answers' => [$questions['likert']->id => '4'],
        ]);

        $this->actingAs($this->officer)
            ->get(route('risk.campaigns.submission', $assignment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Campaigns/Submission')
                ->where('canReview', true)
                // The answer sheet is not a risk line, and does not appear as one.
                ->has('lines', 1)
                ->has('answerSheet.sections', 1, fn (Assert $section) => $section
                    ->where('title', 'Detection')
                    ->has('answers', 1, fn (Assert $answer) => $answer
                        ->where('question', 'How effective is transaction monitoring?')
                        ->where('label', 'Agree')
                        ->etc()))
                ->etc());
    }

    /* ------------------------------------------------------------------ */

    private function campaign(array $attributes = []): AssessmentCampaign
    {
        return AssessmentCampaign::create(array_merge([
            'organization_id' => $this->organization->id,
            'campaign_code' => 'CAM-'.++$this->sequence,
            'title' => 'Q1 RCSA',
            'campaign_type' => 'rcsa',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
            'status' => 'draft',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function assignment(AssessmentCampaign $campaign, array $attributes = []): CampaignAssignment
    {
        return CampaignAssignment::create(array_merge([
            'campaign_id' => $campaign->id,
            'business_unit_id' => $this->unit->id,
            'respondent_id' => $this->officer->id,
            'due_date' => '2026-03-25',
            'status' => 'pending',
        ], $attributes));
    }

    private function questionnaire(string $title, string $status = 'draft'): Questionnaire
    {
        return Questionnaire::create([
            'organization_id' => $this->organization->id,
            'title' => $title,
            'questionnaire_type' => 'rcsa',
            'scoring_method' => 'average',
            'status' => $status,
            'created_by' => $this->actor->id,
        ]);
    }

    /**
     * A published questionnaire with one required likert question and one
     * optional free-text one, attached to a live campaign.
     *
     * @return array{0: AssessmentCampaign, 1: CampaignAssignment, 2: array<string, Question>}
     */
    private function campaignWithQuestionnaire(): array
    {
        $questionnaire = $this->questionnaire('Fraud Risk Questionnaire', 'published');

        $section = QuestionnaireSection::create([
            'questionnaire_id' => $questionnaire->id,
            'title' => 'Detection',
        ]);

        $likert = Question::create([
            'section_id' => $section->id,
            'question_type' => 'likert',
            'question_text' => 'How effective is transaction monitoring?',
            'is_required' => true,
            'sort_order' => 1,
            'options' => [
                ['label' => 'Strongly Disagree', 'value' => 1],
                ['label' => 'Disagree', 'value' => 2],
                ['label' => 'Neutral', 'value' => 3],
                ['label' => 'Agree', 'value' => 4],
                ['label' => 'Strongly Agree', 'value' => 5],
            ],
        ]);

        $free = Question::create([
            'section_id' => $section->id,
            'question_type' => 'free_text',
            'question_text' => 'Describe any gaps you have observed.',
            'is_required' => false,
            'sort_order' => 2,
        ]);

        $campaign = $this->campaign(['status' => 'active', 'questionnaire_id' => $questionnaire->id]);

        return [$campaign, $this->assignment($campaign), ['likert' => $likert, 'free' => $free]];
    }
}

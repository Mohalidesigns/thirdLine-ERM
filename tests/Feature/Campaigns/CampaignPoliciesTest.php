<?php

namespace Tests\Feature\Campaigns;

use App\Models\AssessmentCampaign;
use App\Models\BusinessUnit;
use App\Models\CampaignAssignment;
use App\Models\Organization;
use App\Models\Question;
use App\Models\Questionnaire;
use App\Models\QuestionnaireSection;
use App\Models\User;
use App\Policies\AssessmentCampaignPolicy;
use App\Policies\QuestionnairePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * AssessmentCampaignPolicy and QuestionnairePolicy (migration Phase 4.5), and
 * the four cross-tenant holes this phase closed.
 *
 * All four were confirmed against HEAD with a throwaway probe before a line was
 * written — each one succeeded and wrote its row. They are regression tests
 * now, and they are HTTP-level on purpose: the holes were never in a service,
 * they were in what route model binding will resolve and what the validator
 * would accept.
 */
class CampaignPoliciesTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private Organization $otherOrg;

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

        $this->otherOrg = Organization::create([
            'name' => 'Rival Bank PLC', 'short_name' => 'RIVL',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Discovery */
    /* ------------------------------------------------------------------ */

    /**
     * Laravel finds App\Policies\<Model>Policy and nothing else. A policy named
     * for the MODULE rather than the model is never reached, and every ability
     * on it silently returns false.
     */
    #[Test]
    public function the_policies_are_discovered_for_their_models(): void
    {
        $this->assertInstanceOf(AssessmentCampaignPolicy::class, Gate::getPolicyFor(AssessmentCampaign::class));
        $this->assertInstanceOf(QuestionnairePolicy::class, Gate::getPolicyFor(Questionnaire::class));
    }

    /* ------------------------------------------------------------------ */
    /*  Permissions */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function each_campaign_ability_asks_for_its_own_permission(): void
    {
        $campaign = $this->campaign();

        foreach (['view' => 'campaign.view', 'manage' => 'campaign.manage', 'respond' => 'campaign.respond', 'review' => 'campaign.review'] as $ability => $permission) {
            $holder = $this->userWith([$permission]);
            $viewerOnly = $this->userWith(['campaign.view']);

            $this->assertTrue($holder->can($ability, $campaign), "{$ability} allowed with {$permission}");

            if ($permission !== 'campaign.view') {
                $this->assertFalse($viewerOnly->can($ability, $campaign), "{$ability} denied without {$permission}");
            }
        }

        $this->assertTrue($this->userWith(['campaign.create'])->can('create', AssessmentCampaign::class));
        $this->assertFalse($this->userWith(['campaign.view'])->can('create', AssessmentCampaign::class));
    }

    /**
     * Responding and reviewing are different people, and neither is managing.
     * A respondent who could approve their own submission is not assurance.
     */
    #[Test]
    public function responding_reviewing_and_managing_do_not_imply_one_another(): void
    {
        $campaign = $this->campaign();
        $respondent = $this->userWith(['campaign.view', 'campaign.respond']);

        $this->assertTrue($respondent->can('respond', $campaign));
        $this->assertFalse($respondent->can('review', $campaign));
        $this->assertFalse($respondent->can('manage', $campaign));
    }

    #[Test]
    public function each_questionnaire_ability_asks_for_its_own_permission(): void
    {
        $questionnaire = $this->questionnaire($this->organization->id);

        foreach (['view' => 'questionnaire.view', 'update' => 'questionnaire.edit', 'publish' => 'questionnaire.publish'] as $ability => $permission) {
            $holder = $this->userWith([$permission]);
            $viewerOnly = $this->userWith(['questionnaire.view']);

            $this->assertTrue($holder->can($ability, $questionnaire), "{$ability} allowed with {$permission}");

            if ($permission !== 'questionnaire.view') {
                $this->assertFalse($viewerOnly->can($ability, $questionnaire), "{$ability} denied without {$permission}");
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Tenancy */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_ability_stops_at_the_tenant_boundary(): void
    {
        $foreignCampaign = AssessmentCampaign::withoutGlobalScopes()->create([
            'organization_id' => $this->otherOrg->id,
            'campaign_code' => 'CAM-FOREIGN',
            'title' => 'Theirs',
            'campaign_type' => 'rcsa',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
            'created_by' => $this->actor->id,
        ]);

        $foreignQuestionnaire = $this->questionnaire($this->otherOrg->id);

        $user = $this->userWith([
            'campaign.view', 'campaign.manage', 'campaign.respond', 'campaign.review',
            'questionnaire.view', 'questionnaire.edit', 'questionnaire.publish',
        ]);

        foreach (['view', 'manage', 'respond', 'review'] as $ability) {
            $this->assertFalse($user->can($ability, $foreignCampaign), "campaign {$ability} denied across tenants");
        }

        foreach (['view', 'update', 'publish'] as $ability) {
            $this->assertFalse($user->can($ability, $foreignQuestionnaire), "questionnaire {$ability} denied across tenants");
        }
    }

    /* ------------------------------------------------------------------ */
    /*  The four holes */
    /* ------------------------------------------------------------------ */

    /**
     * `questionnaire_sections` carries no organization_id, so route model
     * binding on {section} resolves any id in the table. Before this phase the
     * question landed.
     */
    #[Test]
    public function a_question_cannot_be_added_to_another_tenants_section(): void
    {
        $foreignSection = QuestionnaireSection::create([
            'questionnaire_id' => $this->questionnaire($this->otherOrg->id)->id,
            'title' => 'Rival section',
        ]);

        $this->actingAs($this->userWith(['questionnaire.edit']))
            ->post(route('risk.questionnaires.add-question', $foreignSection), [
                'question_text' => 'Injected from another tenant',
                'question_type' => 'free_text',
            ])
            ->assertNotFound();

        $this->assertSame(0, Question::where('section_id', $foreignSection->id)->count());
    }

    /** Same hole, on the delete side — and this one destroyed the row. */
    #[Test]
    public function a_question_cannot_be_deleted_from_another_tenants_questionnaire(): void
    {
        $foreignQuestion = Question::create([
            'section_id' => QuestionnaireSection::create([
                'questionnaire_id' => $this->questionnaire($this->otherOrg->id)->id,
                'title' => 'Rival section',
            ])->id,
            'question_type' => 'likert',
            'question_text' => 'Rival question',
        ]);

        $this->actingAs($this->userWith(['questionnaire.edit']))
            ->delete(route('risk.questionnaires.remove-question', $foreignQuestion))
            ->assertNotFound();

        $this->assertTrue(Question::whereKey($foreignQuestion->id)->exists());
    }

    /**
     * `business_unit_id` and `respondent_id` were the string `exists:` form,
     * which is not tenant-bound.
     */
    #[Test]
    public function an_assignment_cannot_name_another_tenants_unit_or_user(): void
    {
        $campaign = $this->campaign();

        $this->actingAs($this->userWith(['campaign.manage']))
            ->post(route('risk.campaigns.add-assignment', $campaign), [
                'business_unit_id' => $this->foreignUnit()->id,
                'respondent_id' => $this->foreignUser()->id,
                'due_date' => '2026-03-25',
            ])
            ->assertSessionHasErrors(['business_unit_id', 'respondent_id']);

        $this->assertSame(0, CampaignAssignment::count());
    }

    /**
     * `questionnaire_id` and `reviewer_id` were not validated at all — the
     * controller read them off the request and mass-assigned them.
     */
    #[Test]
    public function a_campaign_cannot_be_opened_on_another_tenants_questionnaire_or_reviewer(): void
    {
        $this->actingAs($this->userWith(['campaign.create']))
            ->post(route('risk.campaigns.store'), [
                'title' => 'Campaign on a foreign questionnaire',
                'campaign_type' => 'rcsa',
                'start_date' => '2026-01-01',
                'end_date' => '2026-03-31',
                'questionnaire_id' => $this->questionnaire($this->otherOrg->id, 'published')->id,
                'reviewer_id' => $this->foreignUser()->id,
            ])
            ->assertSessionHasErrors(['questionnaire_id', 'reviewer_id']);

        $this->assertSame(0, AssessmentCampaign::withoutGlobalScopes()->count());
    }

    /**
     * The create screen has only ever listed published questionnaires; the
     * validator now agrees with it, so a campaign cannot be hung off a draft
     * whose questions can still be rewritten under its respondents.
     */
    #[Test]
    public function a_campaign_cannot_be_opened_on_an_unpublished_questionnaire(): void
    {
        $draft = $this->questionnaire($this->organization->id, 'draft');

        $this->actingAs($this->userWith(['campaign.create']))
            ->post(route('risk.campaigns.store'), [
                'title' => 'Campaign on a draft',
                'campaign_type' => 'rcsa',
                'start_date' => '2026-01-01',
                'end_date' => '2026-03-31',
                'questionnaire_id' => $draft->id,
            ])
            ->assertSessionHasErrors('questionnaire_id');

        $published = $this->questionnaire($this->organization->id, 'published');

        $this->actingAs($this->userWith(['campaign.create']))
            ->post(route('risk.campaigns.store'), [
                'title' => 'Campaign on a published questionnaire',
                'campaign_type' => 'rcsa',
                'start_date' => '2026-01-01',
                'end_date' => '2026-03-31',
                'questionnaire_id' => $published->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $published->id,
            AssessmentCampaign::where('title', 'Campaign on a published questionnaire')->firstOrFail()->questionnaire_id,
        );
    }

    /**
     * The respond form offered `not_applicable`, which is not one of the four
     * values the rest of the product uses and which the read-back screen has no
     * label for — so the answer was stored and rendered as an em dash.
     */
    #[Test]
    public function a_response_is_bounded_to_the_one_effectiveness_vocabulary(): void
    {
        $campaign = $this->campaign(['status' => 'active']);
        $assignment = CampaignAssignment::create([
            'campaign_id' => $campaign->id,
            'business_unit_id' => BusinessUnit::create([
                'organization_id' => $this->organization->id, 'code' => 'OPS', 'name' => 'Operations',
            ])->id,
            'respondent_id' => $this->actor->id,
            'due_date' => '2026-03-25',
            'status' => 'pending',
        ]);

        $respondent = $this->userWith(['campaign.respond']);

        $this->actingAs($respondent)
            ->post(route('risk.campaigns.submit-response', $assignment), [
                'responses' => [['control_effectiveness' => 'not_applicable', 'likelihood_score' => 3, 'impact_score' => 4]],
            ])
            ->assertSessionHasErrors('responses.0.control_effectiveness');

        $this->actingAs($respondent)
            ->post(route('risk.campaigns.submit-response', $assignment), [
                'responses' => [['control_effectiveness' => 'not_tested', 'likelihood_score' => 3, 'impact_score' => 4]],
            ])
            ->assertSessionHasNoErrors();
    }

    /* ------------------------------------------------------------------ */

    private function userWith(array $permissions): User
    {
        $user = User::create([
            'name' => 'Holder '.++$this->sequence,
            'email' => 'holder-'.$this->sequence.'-'.$this->organization->id.'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    private function foreignUser(): User
    {
        return User::create([
            'name' => 'Rival Staffer '.++$this->sequence,
            'email' => 'rival-'.$this->sequence.'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->otherOrg->id,
            'is_active' => true,
        ]);
    }

    private function foreignUnit(): BusinessUnit
    {
        return BusinessUnit::withoutGlobalScopes()->create([
            'organization_id' => $this->otherOrg->id,
            'code' => 'FGN'.++$this->sequence,
            'name' => 'Foreign unit',
        ]);
    }

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

    private function questionnaire(int $organizationId, string $status = 'draft'): Questionnaire
    {
        return Questionnaire::withoutGlobalScopes()->create([
            'organization_id' => $organizationId,
            'title' => 'Questionnaire '.++$this->sequence,
            'questionnaire_type' => 'rcsa',
            'scoring_method' => 'average',
            'status' => $status,
            'created_by' => $this->actor->id,
        ]);
    }
}

<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\AssessmentStatus;
use App\Enums\Tprm\AssuranceLevel;
use App\Enums\Tprm\ComplianceLevel;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentMessage;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Question;
use App\Models\Tprm\QuestionnaireTemplate;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Assessment\AssessmentService;
use Database\Seeders\Tprm\TprmQuestionnairePackSeeder;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Phase 2 screens: the console, the review screen and the builder.
 *
 * Prop-level, as the suite's limits require (standard §10) — these prove the
 * server hands the page what it needs, not that the page draws it.
 */
class AssessmentScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(TprmReferenceSeeder::class);
        $this->seed(TprmQuestionnairePackSeeder::class);
        TenantContext::set($this->organization->id);

        $this->reviewer = $this->userWith([
            'tprm.view', 'tprm.create',
            'tprm.assessment.view', 'tprm.assessment.issue',
            'tprm.assessment.review', 'tprm.assessment.validate',
            'tprm.questionnaire.manage',
        ], 'reviewer@khb.test', 'tprm-reviewer');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  The console */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_console_renders_with_its_counters_and_reviewer_workload(): void
    {
        $assessment = $this->issued();
        $assessment->forceFill([
            'status' => AssessmentStatus::Submitted->value,
            'internal_reviewer_id' => $this->reviewer->id,
        ])->save();

        $this->actingAs($this->reviewer)
            ->get(route('tprm.assessments.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/Assessments/Index')
                ->where('summary.awaiting_review', 1)
                ->has('workload', 1)
                ->where('workload.0.reviewer', 'Reviewer')
                ->has('grid.rows.data', 1)
            );
    }

    #[Test]
    public function the_console_counts_overdue_from_the_date_rather_than_a_status(): void
    {
        // Overdue is a function of `due_at` and the clock. A stored status
        // would go stale at midnight and every report reading status would
        // disagree with every report reading dates.
        $assessment = $this->issued();
        $assessment->forceFill([
            'status' => AssessmentStatus::Issued->value,
            'due_at' => now()->subDays(5),
        ])->save();

        $this->actingAs($this->reviewer)
            ->get(route('tprm.assessments.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.overdue', 1));
    }

    /* ------------------------------------------------------------------ */
    /*  The review screen */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_review_screen_carries_the_live_score_and_the_scoping_trace(): void
    {
        $assessment = $this->issued();
        $assessment->responses()->update([
            'compliance' => ComplianceLevel::Compliant->value,
            'assurance_level' => AssuranceLevel::IndependentlyAssured->value,
        ]);

        $this->actingAs($this->reviewer)
            ->get(route('tprm.assessments.show', $assessment))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/Assessments/Review')
                // 1 rather than 1.0: round() gives a float whose JSON
                // encoding is an integer, and the assertion is strict.
                ->where('liveScore.ac', 1)
                ->where('liveScore.ec', 0.85)
                ->where('liveScore.m', 0.51)
                ->has('sections')
                // FR-ASM-03's defence, on the same screen as the questions.
                ->has('scoping.exclusions')
                ->where('scoping.total_count', $assessment->question_count)
            );
    }

    #[Test]
    public function the_live_score_shows_the_critical_cap_with_the_question_that_caused_it(): void
    {
        // A reader seeing 94% of the weight compliant and AC 0.5 needs the
        // reason on the same screen.
        $assessment = $this->issued();
        $responses = $assessment->responses()->with('question')->get();

        $responses->each->update([
            'compliance' => ComplianceLevel::Compliant->value,
            'assurance_level' => AssuranceLevel::Validated->value,
        ]);

        $critical = $responses->first(fn (AssessmentResponse $r) => (bool) $r->question->is_critical);
        $this->assertNotNull($critical, 'The CBN pack should contain a critical question.');
        $critical->update(['compliance' => ComplianceLevel::NonCompliant->value]);

        $this->actingAs($this->reviewer)
            ->get(route('tprm.assessments.show', $assessment))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('liveScore.coverage_capped', true)
                ->where('liveScore.ac', 0.5)
                ->has('liveScore.critical_failures', 1)
            );
    }

    #[Test]
    public function an_auto_answered_response_reaches_the_page_with_its_source(): void
    {
        $service = app(AssessmentService::class);
        $engagement = $this->engagement();
        $template = $this->pack('INTERNAL-LOW');

        $first = $service->issue($engagement, $template, null, $this->reviewer->id);
        $first->responses()->update([
            'compliance' => ComplianceLevel::Compliant->value,
            'assurance_level' => AssuranceLevel::Documented->value,
        ]);
        $service->send($first);
        $service->transition($first, AssessmentStatus::InProgress);
        $service->submit($first);
        $service->transition($first, AssessmentStatus::UnderReview);
        $service->validate($first, $this->reviewer->id);

        $second = $service->issue($engagement, $template, null, $this->reviewer->id, 'periodic');

        $this->actingAs($this->reviewer)
            ->get(route('tprm.assessments.show', $second))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sections.0.responses.0.is_auto_answered', true)
                ->where('sections.0.responses.0.carry_forward_cycles', 1)
                ->has('sections.0.responses.0.auto_answer_source.citation')
            );
    }

    /* ------------------------------------------------------------------ */
    /*  Reviewing */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_rejection_without_a_reason_is_refused(): void
    {
        // "Rejected" with no reason gives the vendor nothing to act on, and
        // the resubmission is a guess.
        $assessment = $this->issued();
        $response = $assessment->responses()->first();

        $this->actingAs($this->reviewer)
            ->from(route('tprm.assessments.show', $assessment))
            ->post(route('tprm.assessments.review', [$assessment, $response]), [
                'reviewer_status' => 'rejected',
            ])
            ->assertSessionHasErrors('reviewer_comment');

        $this->assertSame(AssessmentResponse::REVIEW_PENDING, $response->fresh()->reviewer_status);
    }

    #[Test]
    public function a_reviewer_can_correct_the_assurance_level_and_leave_a_note(): void
    {
        // The commonest correction: a vendor claiming `independently_assured`
        // with a policy PDF attached.
        $assessment = $this->issued();
        $response = $assessment->responses()->first();
        $response->update(['assurance_level' => AssuranceLevel::IndependentlyAssured->value]);

        $this->actingAs($this->reviewer)
            ->post(route('tprm.assessments.review', [$assessment, $response]), [
                'reviewer_status' => 'rejected',
                'reviewer_comment' => 'The attachment is a policy, not an audit report.',
                'assurance_level' => 'documented',
                'message' => 'Please attach the SOC 2 covering this control.',
            ])
            ->assertRedirect();

        $response->refresh();
        $this->assertSame(AssuranceLevel::Documented, $response->assurance_level);
        $this->assertSame(AssessmentResponse::REVIEW_REJECTED, $response->reviewer_status);

        // The exchange lives in the record rather than in two inboxes.
        $this->assertSame(1, AssessmentMessage::where('response_id', $response->id)->count());
    }

    #[Test]
    public function validation_is_refused_while_an_answer_is_unreviewed(): void
    {
        $service = app(AssessmentService::class);
        $assessment = $this->issued();
        $assessment->responses()->update(['compliance' => ComplianceLevel::Compliant->value]);

        $service->send($assessment);
        $service->transition($assessment, AssessmentStatus::InProgress);
        $service->submit($assessment);
        $service->transition($assessment, AssessmentStatus::UnderReview);

        $this->actingAs($this->reviewer)
            ->from(route('tprm.assessments.show', $assessment))
            ->post(route('tprm.assessments.validate', $assessment))
            ->assertSessionHas('error', fn (string $error) => str_contains($error, 'not been reviewed'));

        $this->assertNull($assessment->fresh()->ac);
    }

    #[Test]
    public function validating_a_fully_reviewed_assessment_scores_it(): void
    {
        $service = app(AssessmentService::class);
        $assessment = $this->issued();

        $assessment->responses()->update([
            'compliance' => ComplianceLevel::Compliant->value,
            'assurance_level' => AssuranceLevel::Validated->value,
            'reviewer_status' => AssessmentResponse::REVIEW_ACCEPTED,
        ]);

        $service->send($assessment);
        $service->transition($assessment, AssessmentStatus::InProgress);
        $service->submit($assessment);
        $service->transition($assessment, AssessmentStatus::UnderReview);

        $this->actingAs($this->reviewer)
            ->post(route('tprm.assessments.validate', $assessment))
            ->assertRedirect();

        $assessment->refresh();
        $this->assertSame(AssessmentStatus::Scored, $assessment->status);
        $this->assertSame('1.000', $assessment->ac);
        $this->assertSame('1.000', $assessment->ec);
    }

    /* ------------------------------------------------------------------ */
    /*  The builder */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_library_lists_the_shipped_packs_with_their_completeness(): void
    {
        $this->actingAs($this->reviewer)
            ->get(route('tprm.templates.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/Templates/Index')
                ->has('templates', 5)
                ->where('templates.0.is_system_pack', true)
                ->where('templates.0.unmapped_count', 0)
            );
    }

    #[Test]
    public function the_builder_shows_no_publish_blockers_for_a_shipped_pack(): void
    {
        $this->actingAs($this->reviewer)
            ->get(route('tprm.templates.show', $this->pack('CBN-CYBER-CORE')))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/Templates/Builder')
                ->where('template.is_system_pack', true)
                ->where('template.editable', false)
                ->has('publishBlockers', 0)
                ->has('facts')
            );
    }

    #[Test]
    public function the_builder_lists_the_unmapped_questions_that_block_publish(): void
    {
        $clone = $this->cloneOf('INTERNAL-LOW');

        $section = $clone->sections()->first();
        Question::create(['section_id' => $section->id, 'code' => 'NEW-Q', 'text' => 'A question with no control?']);

        $this->actingAs($this->reviewer)
            ->get(route('tprm.templates.show', $clone))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canPublish', false)
                ->has('publishBlockers', 1)
                ->where('publishBlockers.0.code', 'NEW-Q')
            );

        // And publishing is refused with the reason.
        $this->actingAs($this->reviewer)
            ->from(route('tprm.templates.show', $clone))
            ->post(route('tprm.templates.publish', $clone))
            ->assertSessionHas('unmappedQuestions');

        $this->assertSame(QuestionnaireTemplate::STATUS_DRAFT, $clone->fresh()->status);
    }

    #[Test]
    public function cloning_a_shipped_pack_copies_its_questions_and_their_mappings(): void
    {
        // A clone whose mappings did not come with it would be unpublishable
        // the moment it was created.
        $original = $this->pack('ISO-SUPPLIER');
        $clone = $this->cloneOf('ISO-SUPPLIER');

        $this->assertSame($this->organization->id, $clone->organization_id);
        $this->assertSame(QuestionnaireTemplate::STATUS_DRAFT, $clone->status);
        $this->assertSame($original->id, $clone->parent_template_id);
        $this->assertTrue($clone->canPublish());

        $this->assertSame(
            Question::whereIn('section_id', $original->sections()->pluck('id'))->count(),
            Question::whereIn('section_id', $clone->sections()->pluck('id'))->count()
        );

        // A tenant's own template declares no Appendix B size — it has no
        // external specification to be measured against.
        $this->assertNull($clone->declared_question_count);
    }

    #[Test]
    public function a_shipped_pack_cannot_be_edited_even_with_the_manage_permission(): void
    {
        $pack = $this->pack('PCI-TPSP');

        $this->assertFalse($this->reviewer->can('update', $pack));
        $this->assertTrue($this->reviewer->can('clone', $pack));
    }

    #[Test]
    public function the_rule_preview_evaluates_against_a_real_engagement(): void
    {
        $engagement = $this->engagement(['cross_border' => true, 'processes_personal_data' => true]);

        $this->actingAs($this->reviewer)
            ->postJson(route('tprm.templates.preview-rule'), [
                'engagement_id' => $engagement->id,
                'rule' => ['fact' => 'engagement.cross_border', 'op' => 'eq', 'value' => true],
            ])
            ->assertOk()
            ->assertJsonPath('matches', true)
            ->assertJsonPath('facts_used.0', 'engagement.cross_border');

        // The context is keyed by fact names, which contain dots — dot
        // notation cannot address them, so the payload is read directly.
        $context = $this->actingAs($this->reviewer)
            ->postJson(route('tprm.templates.preview-rule'), [
                'engagement_id' => $engagement->id,
                'rule' => ['fact' => 'engagement.cross_border', 'op' => 'eq', 'value' => true],
            ])
            ->json('context');

        $this->assertTrue($context['engagement.cross_border']);
    }

    #[Test]
    public function the_rule_preview_reports_a_fact_it_could_not_resolve(): void
    {
        $this->actingAs($this->reviewer)
            ->postJson(route('tprm.templates.preview-rule'), [
                'rule' => ['fact' => 'engagement.pci_in_scope', 'op' => 'eq', 'value' => true],
            ])
            ->assertOk()
            ->assertJsonPath('matches', false)
            ->assertJsonPath('unresolved_facts.0', 'engagement.pci_in_scope');
    }

    /* ------------------------------------------------------------------ */
    /*  Permissions */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function reviewing_and_validating_need_their_own_permissions(): void
    {
        $viewer = $this->userWith(['tprm.view', 'tprm.assessment.view'], 'viewer@khb.test', 'tprm-assessment-viewer');
        $assessment = $this->issued();
        $response = $assessment->responses()->first();

        $this->actingAs($viewer)->get(route('tprm.assessments.index'))->assertOk();
        $this->actingAs($viewer)->get(route('tprm.assessments.show', $assessment))->assertOk();

        $this->actingAs($viewer)
            ->post(route('tprm.assessments.review', [$assessment, $response]), [
                'reviewer_status' => 'accepted',
            ])->assertForbidden();

        $this->actingAs($viewer)->post(route('tprm.assessments.validate', $assessment))->assertForbidden();
    }

    #[Test]
    public function a_response_from_another_assessment_is_not_reviewable_through_this_one(): void
    {
        // The route binds both independently, so the pairing has to be checked.
        $first = $this->issued();
        $second = $this->issued();
        $foreign = $second->responses()->first();

        $this->actingAs($this->reviewer)
            ->post(route('tprm.assessments.review', [$first, $foreign]), [
                'reviewer_status' => 'accepted',
            ])
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ */

    /** @param  list<string>  $permissions */
    /**
     * Sequential, not random. A random four-digit reference collides against
     * the unique index often enough to fail a full-suite run for a reason
     * unrelated to the test — see RegisterScreensTest, where it did.
     */
    private int $engagementSequence = 0;

    private function userWith(array $permissions, string $email, string $roleName): User
    {
        $user = User::create([
            'name' => Str::of($roleName)->afterLast('-')->ucfirst()->toString(),
            'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate($roleName, 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole($role);

        return $user;
    }

    private function pack(string $code): QuestionnaireTemplate
    {
        return QuestionnaireTemplate::query()->withoutGlobalScopes()->where('code', $code)->firstOrFail();
    }

    private function cloneOf(string $code): QuestionnaireTemplate
    {
        $this->actingAs($this->reviewer)
            ->post(route('tprm.templates.clone', $this->pack($code)))
            ->assertRedirect();

        return QuestionnaireTemplate::query()
            ->where('organization_id', $this->organization->id)
            ->latest('id')->firstOrFail();
    }

    /** @param  array<string, mixed>  $attributes */
    private function engagement(array $attributes = []): Engagement
    {
        $vendor = ThirdParty::create([
            'legal_name' => 'Vendor '.Str::random(6), 'slug' => Str::random(10), 'entity_type' => 'company',
        ]);

        $engagement = Engagement::create($attributes + [
            'third_party_id' => $vendor->id,
            'reference' => sprintf('ENG-2026-%04d', ++$this->engagementSequence),
            'name' => 'Managed service',
            'engagement_type' => 'ict_service',
        ]);

        $engagement->businessFunctions()->attach(
            BusinessFunction::where('function_code', 'BF-ICT-01')->value('id'),
            ['organization_id' => $this->organization->id]
        );

        return $engagement->refresh();
    }

    private function issued(): Assessment
    {
        return app(AssessmentService::class)->issue(
            $this->engagement(),
            $this->pack('CBN-CYBER-CORE'),
            now()->addDays(21),
            $this->reviewer->id,
        );
    }
}

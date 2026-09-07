<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\AssessmentStatus;
use App\Enums\Tprm\AssuranceLevel;
use App\Enums\Tprm\ComplianceLevel;
use App\Exceptions\Tprm\UnmappedQuestionsException;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Question;
use App\Models\Tprm\QuestionControlMap;
use App\Models\Tprm\QuestionnaireSection;
use App\Models\Tprm\QuestionnaireTemplate;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Assessment\AssessmentScorer;
use App\Services\Tprm\Assessment\AssessmentService;
use Database\Seeders\Tprm\Reference\QuestionnairePacks;
use Database\Seeders\Tprm\TprmQuestionnairePackSeeder;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Phase 2 engine: the publish gate, the shipped packs, the lifecycle, and
 * AC-12's delta reassessment.
 */
class AssessmentEngineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

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
        TenantContext::set($this->organization->id);

        $this->user = User::create([
            'name' => 'Reviewer', 'email' => 'reviewer@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  FR-ASM-05 — the publish gate                                       */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_template_with_an_unmapped_question_cannot_be_published(): void
    {
        // "This is the mechanism that keeps questionnaires short." An author
        // who must justify every question against a named control writes forty;
        // one who need not writes two hundred and sixty.
        $template = $this->draftTemplate();
        $section = QuestionnaireSection::create([
            'template_id' => $template->id, 'code' => 'sec', 'title' => 'Section', 'sort_order' => 0,
        ]);

        $mapped = Question::create(['section_id' => $section->id, 'code' => 'Q-MAPPED', 'text' => 'Mapped?']);
        QuestionControlMap::create([
            'question_id' => $mapped->id, 'framework' => 'iso27002',
            'framework_version' => '2022', 'control_id' => '5.19',
        ]);

        Question::create(['section_id' => $section->id, 'code' => 'Q-ORPHAN', 'text' => 'Unmapped?']);

        try {
            $template->update(['status' => QuestionnaireTemplate::STATUS_PUBLISHED]);
            $this->fail('A template with an unmapped question was published.');
        } catch (UnmappedQuestionsException $exception) {
            $this->assertSame(['Q-ORPHAN'], array_column($exception->details(), 'code'));
            // The message explains the rule, or the author maps everything to
            // A.5.19 to get past it and the gate achieves nothing.
            $this->assertStringContainsString('keeps a questionnaire short', $exception->getMessage());
        }

        $this->assertSame(QuestionnaireTemplate::STATUS_DRAFT, $template->fresh()->status);
    }

    #[Test]
    public function mapping_the_last_question_lets_the_template_publish(): void
    {
        $template = $this->draftTemplate();
        $section = QuestionnaireSection::create([
            'template_id' => $template->id, 'code' => 'sec', 'title' => 'Section', 'sort_order' => 0,
        ]);
        $question = Question::create(['section_id' => $section->id, 'code' => 'Q1', 'text' => 'Mapped?']);

        $this->assertFalse($template->canPublish());

        QuestionControlMap::create([
            'question_id' => $question->id, 'framework' => 'iso27002',
            'framework_version' => '2022', 'control_id' => '5.19',
        ]);

        $this->assertTrue($template->fresh()->canPublish());

        $template->update(['status' => QuestionnaireTemplate::STATUS_PUBLISHED]);
        $this->assertSame(QuestionnaireTemplate::STATUS_PUBLISHED, $template->fresh()->status);
    }

    #[Test]
    public function a_template_cannot_be_created_already_published(): void
    {
        // At creation it has no questions, so it has no mappings either.
        $this->expectException(UnmappedQuestionsException::class);

        QuestionnaireTemplate::create([
            'organization_id' => $this->organization->id,
            'code' => 'INSTANT', 'name' => 'Instant', 'version' => '1.0',
            'status' => QuestionnaireTemplate::STATUS_PUBLISHED,
        ]);
    }

    #[Test]
    public function a_published_template_cannot_have_its_version_changed(): void
    {
        // `template_version` on an assessment promises the questions can still
        // be fetched. Editing the published row breaks that promise for every
        // assessment already answered against it.
        $template = $this->publishedTemplate();

        $this->expectExceptionMessage('is published and cannot be changed');
        $template->update(['version' => '2.0']);
    }

    /* ------------------------------------------------------------------ */
    /*  The shipped packs                                                  */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_shipped_pack_seeds_published_with_no_unmapped_question(): void
    {
        // The seeder is the first thing the publish gate is tested against.
        $this->seed(TprmQuestionnairePackSeeder::class);

        $packs = QuestionnaireTemplate::query()->withoutGlobalScopes()->whereNull('organization_id')->get();

        $this->assertCount(5, $packs);

        foreach ($packs as $pack) {
            $this->assertSame(
                QuestionnaireTemplate::STATUS_PUBLISHED,
                $pack->status,
                "Pack {$pack->code} did not publish."
            );
            $this->assertTrue($pack->unmappedQuestions()->isEmpty(), "Pack {$pack->code} has an unmapped question.");
        }
    }

    #[Test]
    public function the_packs_declare_how_much_of_appendix_b_they_ship(): void
    {
        // A 16-question pack presented as the whole of CBN §2.3 would be filed
        // as evidence of a coverage it does not have.
        $this->seed(TprmQuestionnairePackSeeder::class);

        $cbn = QuestionnaireTemplate::query()->withoutGlobalScopes()
            ->where('code', 'CBN-CYBER-CORE')->firstOrFail();

        $this->assertSame(55, (int) $cbn->declared_question_count);
        $this->assertSame('partial', $cbn->catalogue_status);
        $this->assertStringContainsString('of the approximately 55', (string) $cbn->catalogue_note);

        // And the declared number matches what the definition says, so the
        // note cannot drift from the pack.
        $definition = collect(QuestionnairePacks::all())->firstWhere('code', 'CBN-CYBER-CORE');
        $this->assertSame(
            QuestionnairePacks::completeness($definition)['shipped'],
            Question::query()->whereIn('section_id', $cbn->sections()->pluck('id'))->count()
        );
    }

    #[Test]
    public function the_packs_are_system_owned_and_belong_to_no_tenant(): void
    {
        $this->seed(TprmQuestionnairePackSeeder::class);

        $this->assertSame(
            0,
            QuestionnaireTemplate::query()->withoutGlobalScopes()->whereNotNull('organization_id')->count()
        );
    }

    #[Test]
    public function re_seeding_the_packs_does_not_duplicate_them(): void
    {
        $this->seed(TprmQuestionnairePackSeeder::class);
        $before = Question::count();

        $this->seed(TprmQuestionnairePackSeeder::class);

        $this->assertSame($before, Question::count());
        $this->assertSame(5, QuestionnaireTemplate::query()->withoutGlobalScopes()->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Issuing and scoping                                                */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function issuing_scopes_the_template_and_records_why_each_question_was_asked(): void
    {
        $this->seed(TprmQuestionnairePackSeeder::class);

        $engagement = $this->makeEngagement(['processes_personal_data' => true, 'cross_border' => false]);
        $template = $this->pack('NDPA-PROCESSOR');

        $assessment = app(AssessmentService::class)->issue($engagement, $template, null, $this->user->id);

        $this->assertSame(AssessmentStatus::Scoped, $assessment->status);
        $this->assertSame($template->version, $assessment->template_version);

        // The cross-border section is excluded, and the trace says so.
        $trace = $assessment->scoping_trace['trace'];
        $this->assertFalse($trace['NDPA-XB-01']['included']);
        $this->assertSame('section_rule', $trace['NDPA-XB-01']['decided_by']);

        // One response row per included question, and none for the excluded.
        $this->assertSame($assessment->applicable_count, $assessment->responses()->count());
        $this->assertFalse(
            $assessment->responses()->whereHas('question', fn ($q) => $q->where('code', 'NDPA-XB-01'))->exists()
        );
    }

    #[Test]
    public function a_cross_border_engagement_gets_the_transfer_questions(): void
    {
        $this->seed(TprmQuestionnairePackSeeder::class);

        $engagement = $this->makeEngagement(['processes_personal_data' => true, 'cross_border' => true]);
        $assessment = app(AssessmentService::class)->issue($engagement, $this->pack('NDPA-PROCESSOR'));

        $this->assertTrue($assessment->scoping_trace['trace']['NDPA-XB-01']['included']);
    }

    /* ------------------------------------------------------------------ */
    /*  The lifecycle                                                      */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_assessment_cannot_be_scored_before_it_is_validated(): void
    {
        // A score over unreviewed answers is a score over the vendor's own
        // claims, which is precisely what the module exists to distinguish.
        $assessment = $this->issuedAssessment();

        $result = app(AssessmentService::class)->score($assessment, AssessmentScorer::fromConfig());

        $this->assertFalse($result['scored']);
        $this->assertStringContainsString('not before', $result['reason']);
        $this->assertNull($assessment->fresh()->ac);
    }

    #[Test]
    public function a_validated_assessment_scores_and_stores_its_derivation(): void
    {
        $service = app(AssessmentService::class);
        $assessment = $this->issuedAssessment();

        $this->answerAll($assessment, ComplianceLevel::Compliant, AssuranceLevel::IndependentlyAssured);

        $this->advanceToValidated($service, $assessment);

        $result = $service->score($assessment->refresh(), AssessmentScorer::fromConfig());

        $this->assertTrue($result['scored']);
        $assessment->refresh();

        $this->assertSame(AssessmentStatus::Scored, $assessment->status);
        $this->assertSame('1.000', $assessment->ac);
        $this->assertSame('0.850', $assessment->ec);
        $this->assertNotEmpty($assessment->section_scores);

        // The per-answer confidence is written back, so the review screen shows
        // the number that produced the score rather than a fresh one.
        $this->assertSame('0.850', $assessment->responses()->first()->computed_conf);
    }

    #[Test]
    public function submission_is_refused_while_a_required_question_is_unanswered(): void
    {
        $service = app(AssessmentService::class);
        $assessment = $this->issuedAssessment();

        $service->send($assessment);
        $service->transition($assessment, AssessmentStatus::InProgress);

        $this->assertFalse($service->submit($assessment));
        $this->assertSame(AssessmentStatus::InProgress, $assessment->fresh()->status);
    }

    #[Test]
    public function a_clarification_needs_a_flagged_answer_and_reopens_only_those(): void
    {
        // FR-ASM-08: a clarification returns the assessment with ONLY the
        // flagged items open. Reopening everything would have the vendor
        // resubmit forty unchanged answers because two were unclear.
        $service = app(AssessmentService::class);
        $assessment = $this->issuedAssessment();
        $this->answerAll($assessment, ComplianceLevel::Compliant, AssuranceLevel::Documented);

        $service->send($assessment);
        $service->transition($assessment, AssessmentStatus::InProgress);
        $service->submit($assessment);
        $service->transition($assessment, AssessmentStatus::UnderReview);

        // Nothing flagged yet.
        $this->assertFalse($service->requestClarification($assessment));

        $first = $assessment->responses()->first();
        $first->update(['reviewer_status' => AssessmentResponse::REVIEW_CLARIFICATION]);
        $assessment->responses()->whereKeyNot($first->getKey())
            ->update(['reviewer_status' => AssessmentResponse::REVIEW_ACCEPTED]);

        $this->assertTrue($service->requestClarification($assessment->refresh()));
        $this->assertSame(AssessmentStatus::ClarificationRequested, $assessment->fresh()->status);

        // The accepted answers stay accepted.
        $this->assertSame(
            $assessment->responses()->count() - 1,
            $assessment->responses()->where('reviewer_status', AssessmentResponse::REVIEW_ACCEPTED)->count()
        );
    }

    #[Test]
    public function the_lifecycle_refuses_a_transition_the_status_does_not_allow(): void
    {
        $assessment = $this->issuedAssessment();

        // Straight from issued to validated skips the vendor and the reviewer.
        $this->assertFalse(
            app(AssessmentService::class)->transition($assessment, AssessmentStatus::Validated)
        );
    }

    /* ------------------------------------------------------------------ */
    /*  AC-12 — delta reassessment                                         */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function ac12_a_delta_carries_unchanged_answers_and_opens_only_the_rest(): void
    {
        // AC-12: a periodic reassessment of an engagement with no attribute
        // changes presents only questions whose evidence has expired or whose
        // carry-forward limit is reached; carried answers show a decayed
        // confidence and their original source.
        $service = app(AssessmentService::class);
        $first = $this->issuedAssessment();

        $this->answerAll($first, ComplianceLevel::Compliant, AssuranceLevel::Documented);
        $this->advanceToValidated($service, $first);

        $delta = $service->buildDelta($first->refresh(), $this->user->id);

        $this->assertSame('delta', $delta->assessment_type);
        $this->assertSame($first->id, $delta->parent_assessment_id);

        // Every answer carried, so nothing is open.
        $carried = $delta->responses()->whereNotNull('carried_forward_from_response_id')->count();
        $this->assertSame($delta->responses()->count(), $carried);

        $response = $delta->responses()->first();
        $this->assertSame(ComplianceLevel::Compliant, $response->compliance);
        $this->assertSame(1, $response->carry_forward_cycles);
        // The source is cited, so the vendor confirms-or-corrects rather than
        // meeting a pre-filled box with no provenance.
        $this->assertSame('prior_response', $response->auto_answer_source['kind']);
    }

    #[Test]
    public function a_carried_answer_scores_lower_than_a_freshly_evidenced_one(): void
    {
        // The carry-forward decay is what stops inheritance making answering
        // free: a documented answer carried one cycle scores 0.60 × 0.9.
        $service = app(AssessmentService::class);
        $first = $this->issuedAssessment();

        $this->answerAll($first, ComplianceLevel::Compliant, AssuranceLevel::Documented);
        $this->advanceToValidated($service, $first);
        $service->score($first->refresh(), AssessmentScorer::fromConfig());

        $delta = $service->buildDelta($first->refresh(), $this->user->id);

        $this->advanceToValidated($service, $delta);
        $result = $service->score($delta->refresh(), AssessmentScorer::fromConfig());

        $this->assertSame(0.54, round((float) $result['score']->evidenceConfidence, 4));
        $this->assertLessThan(
            (float) $first->fresh()->ec,
            (float) $delta->fresh()->ec,
            'A carried answer must score below a freshly evidenced one.'
        );
    }

    #[Test]
    public function an_answer_at_the_carry_forward_limit_is_asked_again(): void
    {
        $service = app(AssessmentService::class);
        $limit = (int) config('tprm.defaults.carry_forward_cycle_limit');

        $first = $this->issuedAssessment();
        $this->answerAll($first, ComplianceLevel::Compliant, AssuranceLevel::Documented);
        $this->advanceToValidated($service, $first);

        // Pretend it has already been carried to the limit.
        $first->responses()->update(['carry_forward_cycles' => $limit]);

        $delta = $service->buildDelta($first->refresh(), $this->user->id);

        $this->assertSame(
            0,
            $delta->responses()->whereNotNull('carried_forward_from_response_id')->count(),
            'An answer at the carry-forward limit must be asked again rather than carried indefinitely.'
        );
    }

    /* ------------------------------------------------------------------ */
    /*  FR-ASM-06 — inheritance                                            */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_new_assessment_pre_answers_from_a_prior_validated_cycle(): void
    {
        $service = app(AssessmentService::class);
        $engagement = $this->makeEngagement();
        $template = $this->publishedTemplate();

        $first = $service->issue($engagement, $template, null, $this->user->id);
        $this->answerAll($first, ComplianceLevel::Compliant, AssuranceLevel::Validated);
        $this->advanceToValidated($service, $first);

        $second = $service->issue($engagement, $template, null, $this->user->id, 'periodic');

        $response = $second->responses()->first();
        $this->assertTrue((bool) $response->is_auto_answered);
        $this->assertSame(ComplianceLevel::Compliant, $response->compliance);
        $this->assertStringContainsString('Carried from your previous assessment', $response->auto_answer_source['citation']);
    }

    #[Test]
    public function an_unvalidated_prior_cycle_is_not_inherited_from(): void
    {
        // Carrying from a cycle nobody reviewed would launder an unreviewed
        // claim into the next year's evidence base.
        $service = app(AssessmentService::class);
        $engagement = $this->makeEngagement();
        $template = $this->publishedTemplate();

        $first = $service->issue($engagement, $template, null, $this->user->id);
        $this->answerAll($first, ComplianceLevel::Compliant, AssuranceLevel::Validated);
        // Left at `scoped` — never reviewed.

        $second = $service->issue($engagement, $template, null, $this->user->id, 'periodic');

        $this->assertFalse((bool) $second->responses()->first()->is_auto_answered);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Sequential, not random. A random four-digit reference collides against
     * the unique index often enough to fail a full-suite run for a reason
     * unrelated to the test — see RegisterScreensTest, where it did.
     */
    private int $engagementSequence = 0;

    private function draftTemplate(): QuestionnaireTemplate
    {
        return QuestionnaireTemplate::create([
            'organization_id' => $this->organization->id,
            'code' => 'TEST-'.Str::upper(Str::random(5)),
            'name' => 'Test template',
            'version' => '1.0',
        ]);
    }

    private function publishedTemplate(): QuestionnaireTemplate
    {
        $template = $this->draftTemplate();
        $section = QuestionnaireSection::create([
            'template_id' => $template->id, 'code' => 'sec', 'title' => 'Section',
            'domain_tag' => 'security', 'sort_order' => 0,
        ]);

        foreach (['Q1', 'Q2'] as $code) {
            $question = Question::create([
                'section_id' => $section->id, 'code' => $code, 'text' => "Question {$code}?", 'risk_weight' => 1,
            ]);
            QuestionControlMap::create([
                'question_id' => $question->id, 'framework' => 'iso27002',
                'framework_version' => '2022', 'control_id' => '5.19',
            ]);
        }

        $template->update(['status' => QuestionnaireTemplate::STATUS_PUBLISHED, 'published_at' => now()]);

        return $template->fresh();
    }

    private function issuedAssessment(): Assessment
    {
        return app(AssessmentService::class)->issue(
            $this->makeEngagement(),
            $this->publishedTemplate(),
            now()->addDays(30),
            $this->user->id,
        );
    }

    /**
     * Walk the happy path: scoped → issued → in progress → submitted → under
     * review → validated.
     *
     * Written out because the lifecycle is guarded and the shortcut a test
     * wants to take — straight from scoped to validated — is exactly the one
     * the guard exists to refuse.
     */
    private function advanceToValidated(AssessmentService $service, Assessment $assessment): void
    {
        $service->send($assessment);
        $service->transition($assessment, AssessmentStatus::InProgress);
        $service->submit($assessment);
        $service->transition($assessment, AssessmentStatus::UnderReview);
        $service->validate($assessment, $this->user->id);
    }

    private function answerAll(Assessment $assessment, ComplianceLevel $compliance, AssuranceLevel $level): void
    {
        $assessment->responses()->update([
            'compliance' => $compliance->value,
            'assurance_level' => $level->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeEngagement(array $attributes = []): Engagement
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
            BusinessFunction::where('function_code', 'BF-HR-01')->value('id'),
            ['organization_id' => $this->organization->id]
        );

        return $engagement->refresh();
    }

    private function pack(string $code): QuestionnaireTemplate
    {
        return QuestionnaireTemplate::query()->withoutGlobalScopes()->where('code', $code)->firstOrFail();
    }
}

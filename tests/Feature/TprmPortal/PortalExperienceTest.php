<?php

namespace Tests\Feature\TprmPortal;

use App\Enums\Tprm\AssessmentStatus;
use App\Enums\Tprm\ComplianceLevel;
use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\FindingSeverity;
use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentMessage;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\QuestionnaireTemplate;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Assessment\AssessmentService;
use App\Services\Tprm\Findings\FindingService;
use App\Services\Tprm\Portal\PortalAssessmentService;
use App\Services\Tprm\Portal\PortalMessageService;
use App\Support\Auth\Totp;
use Database\Seeders\Tprm\TprmQuestionnairePackSeeder;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\OrganizationScope;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The vendor's experience — FR-PRT-02, 03, 08 and 09 — and the CROSS-VENDOR
 * half of AC-14 that these routes are the first to open up.
 *
 * THE ISOLATION SUITE PROVES A VENDOR CANNOT REACH ANOTHER TENANT. It cannot
 * prove a vendor cannot reach ANOTHER VENDOR OF THE SAME TENANT, because until
 * this slice there was nothing tenant-internal to reach. Route-model binding
 * resolves under the bound tenant and stops the first; only an explicit
 * ownership check stops the second, and these tests are where that is proven.
 */
class PortalExperienceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private PortalUser $cloudspan;

    private PortalUser $rival;

    private PortalUser $colleague;

    private Assessment $assessment;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        config()->set('tprm.ai.enabled', false);

        $this->bank = Organization::create([
            'name' => 'Lagos Union Bank', 'short_name' => 'LUB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        $this->reviewer = User::create([
            'name' => 'Reviewer', 'email' => 'reviewer@lub.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        TenantContext::actingAs($this->bank->id, function (): void {
            $this->seed(TprmReferenceSeeder::class);
            $this->seed(TprmQuestionnairePackSeeder::class);
        });

        // TWO VENDORS OF THE SAME BANK. That is the shape the cross-vendor
        // probes need and the shape the isolation suite could not create.
        $this->cloudspan = $this->portalUser('Cloudspan Nigeria Limited', 'ops@cloudspan.test');
        $this->rival = $this->portalUser('Zenith Print Services Limited', 'admin@zenithprint.test');
        $this->colleague = $this->colleagueOf($this->cloudspan, 'second@cloudspan.test');

        $this->assessment = $this->issueTo($this->cloudspan);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  AC-14, cross-vendor */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_vendor_cannot_open_another_vendors_assessment(): void
    {
        $this->signIn($this->rival);

        // Same tenant, so the tenant scope does not stop this and route-model
        // binding resolves the row happily. Only the ownership check does.
        //
        // 404, NOT 403: a vendor enumerating uuids must not be able to tell
        // "no such assessment" from "somebody else's assessment".
        $this->get(route('tprm-portal.assessments.show', $this->assessment->uuid))
            ->assertNotFound();
    }

    #[Test]
    public function a_vendor_cannot_write_to_another_vendors_answer(): void
    {
        $response = $this->firstResponse();

        $this->signIn($this->rival);

        $this->post(route('tprm-portal.assessments.answers.save', [$this->assessment->uuid, $response->getKey()]), [
            'value' => 'Injected by a different vendor.',
        ]);

        $this->assertNotSame('Injected by a different vendor.', $response->refresh()->value);
    }

    #[Test]
    public function a_vendor_cannot_open_another_vendors_finding(): void
    {
        $finding = $this->findingFor($this->cloudspan);

        $this->signIn($this->rival);

        // 404, not 403: a vendor probing references must not learn that a
        // finding with that id exists against somebody else.
        $this->get(route('tprm-portal.findings.show', $finding->uuid))->assertNotFound();
    }

    #[Test]
    public function a_vendors_lists_contain_only_its_own_rows(): void
    {
        $this->findingFor($this->cloudspan);

        $this->signIn($this->rival);

        $this->get(route('tprm-portal.assessments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('assessments', 0));

        $this->get(route('tprm-portal.findings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('findings', 0));
    }

    /* ------------------------------------------------------------------ */
    /*  Answering — FR-PRT-03 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_answer_is_saved_on_its_own_and_moves_the_assessment_to_in_progress(): void
    {
        $response = $this->firstResponse();

        $this->signIn($this->cloudspan);

        $this->post(route('tprm-portal.assessments.answers.save', [$this->assessment->uuid, $response->getKey()]), [
            'value' => 'Yes — least privilege, reviewed quarterly.',
            'compliance' => ComplianceLevel::Compliant->value,
        ])->assertRedirect();

        $this->assertSame('Yes — least privilege, reviewed quarterly.', $response->refresh()->value);

        // Save-and-resume: one field at a time, no submit needed to keep work.
        $this->assertSame(AssessmentStatus::InProgress, $this->assessment->refresh()->status);
    }

    #[Test]
    public function editing_a_pre_filled_answer_drops_its_citation(): void
    {
        $response = $this->firstResponse();

        $response->forceFill([
            'is_auto_answered' => true,
            'auto_answer_source' => ['source' => 'trust_profile', 'version' => 1, 'note' => 'From your profile.'],
            'value' => 'The profile wording.',
        ])->save();

        app(PortalAssessmentService::class)->saveAnswer(
            $this->assessment,
            $response,
            $this->cloudspan,
            'The vendor\'s own, different wording.',
            ComplianceLevel::Compliant,
        );

        // A citation pointing at a profile that never contained these words is
        // worse than no citation.
        $this->assertFalse($response->refresh()->is_auto_answered);
        $this->assertNull($response->auto_answer_source);
    }

    #[Test]
    public function keeping_a_pre_filled_answer_unchanged_keeps_its_citation(): void
    {
        $response = $this->firstResponse();

        $response->forceFill([
            'is_auto_answered' => true,
            'auto_answer_source' => ['source' => 'trust_profile', 'version' => 1, 'note' => 'From your profile.'],
            'value' => 'The profile wording.',
        ])->save();

        // Confirming is an act too: the vendor read it and kept it, and that is
        // still an answer from the profile.
        app(PortalAssessmentService::class)->saveAnswer(
            $this->assessment,
            $response,
            $this->cloudspan,
            'The profile wording.',
            ComplianceLevel::Compliant,
        );

        $this->assertTrue($response->refresh()->is_auto_answered);
        $this->assertSame('trust_profile', $response->auto_answer_source['source']);
    }

    #[Test]
    public function submission_is_refused_while_a_required_question_is_unanswered_and_names_them(): void
    {
        $result = app(PortalAssessmentService::class)->submit($this->assessment, $this->cloudspan);

        $this->assertFalse($result['submitted']);
        $this->assertNotEmpty($result['outstanding']);

        // "Please complete all required fields" across six sections is a
        // scavenger hunt.
        $this->assertStringContainsString('CBN-', $result['outstanding'][0]);
    }

    #[Test]
    public function a_fully_pre_filled_assessment_can_still_be_submitted(): void
    {
        // Nothing has been SAVED, so the assessment is still `issued` — the
        // exact shape a questionnaire takes when the trust profile answered it
        // all. The lifecycle has no Issued → Submitted edge, so submit has to
        // walk it, or the one case the profile exists to create is the one
        // case that cannot be submitted.
        $this->answerEverything();

        $this->assertSame(AssessmentStatus::Issued, $this->assessment->refresh()->status);

        $result = app(PortalAssessmentService::class)->submit($this->assessment, $this->cloudspan);

        $this->assertTrue($result['submitted'], (string) $result['reason']);
        $this->assertSame(AssessmentStatus::Submitted, $this->assessment->refresh()->status);
    }

    #[Test]
    public function a_submitted_assessment_cannot_be_edited_by_the_vendor(): void
    {
        $this->answerEverything();

        $result = app(PortalAssessmentService::class)->submit($this->assessment, $this->cloudspan);
        $this->assertTrue($result['submitted']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/with the reviewer/');

        app(PortalAssessmentService::class)->saveAnswer(
            $this->assessment->refresh(),
            $this->firstResponse(),
            $this->cloudspan,
            'Changed after submission.',
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Delegation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_section_can_be_handed_to_a_colleague(): void
    {
        $section = $this->assessment->template->sections->first();

        $delegation = app(PortalAssessmentService::class)->delegate(
            $this->assessment,
            $section,
            $this->cloudspan,
            $this->colleague,
            'You own the security answers.',
        );

        $this->assertSame($this->colleague->getKey(), $delegation->delegated_to);
        $this->assertTrue($delegation->isOpen());
    }

    #[Test]
    public function a_section_cannot_be_delegated_to_another_vendors_person(): void
    {
        $section = $this->assessment->template->sections->first();

        $this->expectException(InvalidArgumentException::class);

        app(PortalAssessmentService::class)->delegate(
            $this->assessment,
            $section,
            $this->cloudspan,
            $this->rival,
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Messaging — FR-PRT-09 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_thread_carries_both_sides_in_order(): void
    {
        $messages = app(PortalMessageService::class);

        $messages->postFromInternal($this->reviewer, 'Can you confirm the review frequency?', $this->assessment);
        $messages->postFromVendor($this->cloudspan, 'Quarterly, evidenced in the access review pack.', $this->assessment);

        $thread = $messages->thread($this->assessment);

        $this->assertCount(2, $thread);
        $this->assertFalse($thread[0]->isFromVendor());
        $this->assertTrue($thread[1]->isFromVendor());
    }

    #[Test]
    public function a_finding_thread_and_an_assessment_thread_are_separate(): void
    {
        $messages = app(PortalMessageService::class);
        $finding = $this->findingFor($this->cloudspan);

        $messages->postFromVendor($this->cloudspan, 'On the assessment.', $this->assessment);
        $messages->postFromVendor($this->cloudspan, 'On the finding.', null, $finding);

        $this->assertCount(1, $messages->thread($this->assessment));
        $this->assertCount(1, $messages->thread(null, $finding));
        $this->assertSame('finding', $messages->thread(null, $finding)->first()->subjectKind());
    }

    #[Test]
    public function a_message_must_hang_from_exactly_one_subject(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PortalMessageService::class)->thread(null, null);
    }

    #[Test]
    public function a_vendor_cannot_post_into_another_vendors_thread(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PortalMessageService::class)->postFromVendor($this->rival, 'Not mine.', $this->assessment);
    }

    #[Test]
    public function marking_read_only_touches_the_other_sides_messages(): void
    {
        $messages = app(PortalMessageService::class);

        $messages->postFromInternal($this->reviewer, 'A question.', $this->assessment);
        $messages->postFromVendor($this->cloudspan, 'An answer.', $this->assessment);

        $messages->markRead(AssessmentMessage::AUTHOR_VENDOR, $this->assessment);

        $thread = $messages->thread($this->assessment);

        // The vendor reading marks the REVIEWER's message read, not its own —
        // otherwise a side can zero its own unread count.
        $this->assertNotNull($thread[0]->refresh()->read_at);
        $this->assertNull($thread[1]->refresh()->read_at);
    }

    /* ------------------------------------------------------------------ */
    /*  Screens */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_dashboard_leads_with_what_is_owed(): void
    {
        $this->findingFor($this->cloudspan);
        $this->signIn($this->cloudspan);

        $this->get(route('tprm-portal.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('TprmPortal/Dashboard')
                ->has('openRequests', 1)
                ->has('openFindings', 1)
                // Raising a finding invalidates the score, which recomputes —
                // so by the time the dashboard renders there IS a score. The
                // vendor sees the same number the client does, inverted.
                ->where('trustScore.unavailable', null)
                ->has('trustScore.score'));
    }

    #[Test]
    public function a_vendor_with_no_score_yet_is_told_so_rather_than_shown_a_zero(): void
    {
        // No finding raised, so nothing has triggered a score run. A vendor
        // shown "0" before their first assessment is reviewed will read it as
        // a judgement, complain, and be right to.
        $this->signIn($this->cloudspan);

        $this->get(route('tprm-portal.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('trustScore.score', null)
                ->where('trustScore.unavailable', 'Your score appears once your first assessment has been reviewed.'));
    }

    #[Test]
    public function the_response_screen_renders_its_sections_and_thread(): void
    {
        app(PortalMessageService::class)->postFromInternal($this->reviewer, 'A question.', $this->assessment);

        $this->signIn($this->cloudspan);

        $this->get(route('tprm-portal.assessments.show', $this->assessment->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('TprmPortal/Assessments/Respond')
                ->where('assessment.editable', true)
                ->has('sections')
                ->has('thread', 1)
                ->has('colleagues', 1));
    }

    /* ------------------------------------------------------------------ */
    /*  Fixtures */
    /* ------------------------------------------------------------------ */

    private function firstResponse(): AssessmentResponse
    {
        return TenantContext::actingAs($this->bank->id, fn () => AssessmentResponse::query()
            ->where('assessment_id', $this->assessment->getKey())
            ->orderBy('id')
            ->firstOrFail());
    }

    private function answerEverything(): void
    {
        TenantContext::actingAs($this->bank->id, function (): void {
            AssessmentResponse::query()
                ->where('assessment_id', $this->assessment->getKey())
                ->get()
                ->each(fn (AssessmentResponse $r) => $r->forceFill([
                    'value' => 'Yes.',
                    'compliance' => ComplianceLevel::Compliant->value,
                ])->save());
        });
    }

    private function findingFor(PortalUser $user): \App\Models\Tprm\Finding
    {
        return TenantContext::actingAs($this->bank->id, function () use ($user) {
            $engagement = Engagement::query()->where('third_party_id', $user->third_party_id)->firstOrFail();

            return app(FindingService::class)->raise(
                $engagement,
                'assessment',
                FindingSeverity::High,
                'Access reviews are not evidenced',
                ['description' => 'No evidence of the quarterly access review was provided.'],
            );
        });
    }

    private function issueTo(PortalUser $user): Assessment
    {
        return TenantContext::actingAs($this->bank->id, function () use ($user): Assessment {
            $engagement = Engagement::query()->where('third_party_id', $user->third_party_id)->firstOrFail();

            $template = QuestionnaireTemplate::query()
                ->withoutGlobalScopes()
                ->where('code', 'CBN-CYBER-CORE')
                ->where('status', 'published')
                ->firstOrFail();

            $assessment = app(AssessmentService::class)->issue($engagement, $template);
            app(AssessmentService::class)->send($assessment);

            return $assessment->refresh();
        });
    }

    private function portalUser(string $vendorName, string $email): PortalUser
    {
        return TenantContext::actingAs($this->bank->id, function () use ($vendorName, $email): PortalUser {
            $vendor = ThirdParty::create([
                'organization_id' => $this->bank->id,
                'legal_name' => $vendorName,
                'slug' => Str::random(12),
                'entity_type' => 'company',
                'status' => 'active',
            ]);

            $engagement = Engagement::create([
                'organization_id' => $this->bank->id,
                'third_party_id' => $vendor->id,
                'reference' => 'ENG-'.Str::upper(Str::random(6)),
                'name' => $vendorName.' services',
                'service_description' => 'Fixture engagement.',
                'engagement_type' => 'ict_service',
                'relationship_owner_id' => $this->reviewer->id,
            ]);

            $engagement->forceFill([
                'status' => EngagementStatus::Assessment->value,
                'inherent_score' => 70,
                'inherent_tier' => RiskTier::High->value,
                'effective_tier' => RiskTier::High->value,
            ])->save();

            InherentAssessment::create([
                'organization_id' => $this->bank->id,
                'engagement_id' => $engagement->id,
                'version' => 1, 'ruleset_version' => '1.0.0',
                'raw_score' => 70, 'resulting_tier' => RiskTier::High->value,
                'assessed_at' => now(), 'is_current' => true,
                'answers' => [], 'factor_scores' => [], 'weights' => [], 'explanation' => [],
            ]);

            return $this->account($vendor, $email);
        });
    }

    private function colleagueOf(PortalUser $user, string $email): PortalUser
    {
        return TenantContext::actingAs($this->bank->id, function () use ($user, $email): PortalUser {
            $vendor = ThirdParty::query()
                ->withoutGlobalScope(OrganizationScope::class)
                ->findOrFail($user->third_party_id);

            return $this->account($vendor, $email);
        });
    }

    private function account(ThirdParty $vendor, string $email): PortalUser
    {
        $user = new PortalUser([
            'organization_id' => $this->bank->id,
            'third_party_id' => $vendor->id,
            'email' => $email,
            'name' => Str::before($email, '@'),
            'password' => 'Sup3r-Str0ng-P@ssphrase!',
        ]);

        $user->forceFill([
            'status' => PortalUser::STATUS_ACTIVE,
            'accepted_at' => now(),
            'mfa_method' => PortalUser::METHOD_TOTP,
            'mfa_secret' => Totp::generateSecret(),
            'mfa_enabled' => true,
        ])->save();

        return $user;
    }

    private function signIn(PortalUser $user): void
    {
        $this->post(route('tprm-portal.login.attempt', ['client' => $this->bank->uuid]), [
            'email' => $user->email,
            'password' => 'Sup3r-Str0ng-P@ssphrase!',
        ]);

        $this->post(route('tprm-portal.mfa.verify'), [
            'code' => Totp::at((string) $user->mfa_secret, time()),
        ]);
    }
}

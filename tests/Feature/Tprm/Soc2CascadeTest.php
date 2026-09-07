<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\AssessmentStatus;
use App\Enums\Tprm\AssuranceLevel;
use App\Enums\Tprm\DisclosureSource;
use App\Enums\Tprm\FindingSeverity;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Document;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\QuestionnaireTemplate;
use App\Models\Tprm\Soc2Cuec;
use App\Models\Tprm\Soc2Detail;
use App\Models\Tprm\Soc2Exception;
use App\Models\Tprm\Soc2SubserviceOrg;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Assessment\AssessmentService;
use App\Services\Tprm\Evidence\Soc2Cascade;
use Database\Seeders\Tprm\TprmQuestionnairePackSeeder;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * AC-04 — "three whole workflows fall out of one upload".
 *
 * One confirmed SOC 2 produces four proposal sets: pre-answers with citations,
 * a finding per Section 4 exception, an internal obligation per CUEC, and an
 * nth-party edge per carve-out. Every one of them is a PROPOSAL: this test
 * asserts, as its last and most important claim, that running the cascade
 * writes nothing to the register.
 */
class Soc2CascadeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private Engagement $engagement;

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

        $this->user = User::create([
            'name' => 'Reviewer', 'email' => 'reviewer@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $this->engagement = $this->makeEngagement();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  AC-04, the four sets                                               */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function one_soc2_produces_all_four_proposal_sets(): void
    {
        $this->issueCbnAssessment();
        $soc2 = $this->makeSoc2();

        Soc2Exception::create([
            'organization_id' => $this->organization->id, 'soc2_id' => $soc2->id,
            'control_reference' => 'CC7.2', 'description' => 'Two of forty alerts were not investigated within SLA.',
            'population' => '40 alerts', 'exceptions_noted' => '2 of 40',
        ]);
        Soc2Cuec::create([
            'organization_id' => $this->organization->id, 'soc2_id' => $soc2->id,
            'cuec_reference' => 'CUEC-3', 'description' => 'User entities are responsible for reviewing access reports quarterly.',
        ]);
        Soc2SubserviceOrg::create([
            'organization_id' => $this->organization->id, 'soc2_id' => $soc2->id,
            'name' => 'Amazon Web Services', 'services' => 'Infrastructure hosting',
            'method' => Soc2SubserviceOrg::METHOD_CARVE_OUT,
        ]);

        $proposals = app(Soc2Cascade::class)->propose($soc2->fresh());

        $this->assertNotEmpty($proposals->answers, 'A clean SOC 2 pre-answered nothing.');
        $this->assertCount(1, $proposals->findings);
        $this->assertCount(1, $proposals->obligations);
        $this->assertCount(1, $proposals->nthPartyEdges);
        $this->assertFalse($proposals->isEmpty());
    }

    #[Test]
    public function every_pre_answer_carries_a_citation_and_an_assurance_level(): void
    {
        $this->issueCbnAssessment();
        $proposals = app(Soc2Cascade::class)->propose($this->makeSoc2());

        foreach ($proposals->answers as $answer) {
            $this->assertNotEmpty($answer['tsc_criterion'], 'A pre-answer named no criterion.');
            // FR-EVD-05: without the citation the reviewer takes it on trust.
            $this->assertStringContainsString('SOC 2 Type II', $answer['citation']);
            $this->assertStringContainsString('Grant Thornton', $answer['citation']);
            $this->assertSame(
                AssuranceLevel::IndependentlyAssured->value,
                $answer['proposed_assurance_level']
            );
        }
    }

    #[Test]
    public function a_criterion_with_an_exception_against_it_is_not_pre_answered(): void
    {
        // The report says the control did not operate. Proposing
        // `independently_assured` from the same report would use the evidence
        // of a failure as evidence of compliance.
        $this->issueCbnAssessment();
        $soc2 = $this->makeSoc2();

        $before = collect(app(Soc2Cascade::class)->propose($soc2)->answers)
            ->pluck('question_code')->all();
        $this->assertContains('CBN-ACC-03', $before, 'The CC7-mapped question was not proposed to begin with.');

        Soc2Exception::create([
            'organization_id' => $this->organization->id, 'soc2_id' => $soc2->id,
            'control_reference' => 'CC7.2 — monitoring of security events',
            'description' => 'Alerts were not investigated within the defined period.',
        ]);

        $after = collect(app(Soc2Cascade::class)->propose($soc2->fresh())->answers)
            ->pluck('question_code')->all();

        $this->assertNotContains('CBN-ACC-03', $after);
        $this->assertNotContains('CBN-RES-02', $after, 'The other CC7 question was still proposed.');
        // The unaffected criteria are untouched — one exception does not
        // discard the whole report.
        $this->assertContains('CBN-ACC-01', $after);
    }

    #[Test]
    public function only_the_categories_the_report_covers_are_pre_answered(): void
    {
        // A Security-only report says nothing about availability, and the
        // vocabulary gap is where a naive implementation matches everything or
        // nothing: the report declares CATEGORIES, the questions carry CRITERIA.
        $this->issueCbnAssessment();
        $soc2 = $this->makeSoc2(['tsc_categories' => ['security']]);

        $codes = collect(app(Soc2Cascade::class)->propose($soc2)->answers)->pluck('question_code')->all();

        $this->assertContains('CBN-ACC-01', $codes, 'A common-criteria question was not proposed.');
        $this->assertNotContains('CBN-RES-01', $codes, 'An availability question was proposed by a security-only report.');
    }

    #[Test]
    public function a_report_covering_availability_reaches_the_availability_questions(): void
    {
        $this->issueCbnAssessment();
        $soc2 = $this->makeSoc2(['tsc_categories' => ['security', 'availability']]);

        $codes = collect(app(Soc2Cascade::class)->propose($soc2)->answers)->pluck('question_code')->all();

        $this->assertContains('CBN-RES-01', $codes);
    }

    /* ------------------------------------------------------------------ */
    /*  AC-05 and the Type I distinction                                   */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_bridge_letter_caps_the_proposed_assurance_at_documented(): void
    {
        // AC-05. A bridge letter is the vendor's assertion that nothing
        // changed; it is not an auditor's opinion that nothing did.
        $this->issueCbnAssessment();
        $bridge = $this->makeDocument('Bridge letter');
        $soc2 = $this->makeSoc2(['bridge_letter_document_id' => $bridge->id, 'bridge_covers_to' => now()->toDateString()]);

        $proposals = app(Soc2Cascade::class)->propose($soc2);

        $this->assertTrue($proposals->bridgeLetterCap);
        $this->assertSame(
            AssuranceLevel::Documented->value,
            $proposals->answers[0]['proposed_assurance_level']
        );
    }

    #[Test]
    public function a_type_i_report_cannot_propose_independent_assurance(): void
    {
        // A Type I is an opinion about the DESIGN of controls on one day. A
        // vendor must not be able to buy the cheaper report and score as
        // though it had bought the other.
        $this->issueCbnAssessment();
        $soc2 = $this->makeSoc2(['report_type' => Soc2Detail::TYPE_I]);

        $proposals = app(Soc2Cascade::class)->propose($soc2);

        $this->assertSame(
            AssuranceLevel::Documented->value,
            $proposals->answers[0]['proposed_assurance_level']
        );
        $this->assertStringContainsString('Type I', $proposals->answers[0]['citation']);
    }

    #[Test]
    public function a_qualified_opinion_cannot_propose_independent_assurance(): void
    {
        $this->issueCbnAssessment();
        $soc2 = $this->makeSoc2(['opinion_type' => 'qualified']);

        $proposals = app(Soc2Cascade::class)->propose($soc2);

        $this->assertSame(
            AssuranceLevel::Documented->value,
            $proposals->answers[0]['proposed_assurance_level']
        );
    }

    /* ------------------------------------------------------------------ */
    /*  The other three sets                                               */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_exception_without_a_stated_severity_is_proposed_as_medium(): void
    {
        // Never Critical by default: a single exception in a sample of forty
        // is not automatically a critical failure, and a tool that says it is
        // gets ignored.
        $soc2 = $this->makeSoc2();
        Soc2Exception::create([
            'organization_id' => $this->organization->id, 'soc2_id' => $soc2->id,
            'control_reference' => 'CC6.1', 'description' => 'One of twenty-five terminations was not revoked within SLA.',
            'population' => '25 terminations', 'exceptions_noted' => '1 of 25',
        ]);

        $finding = app(Soc2Cascade::class)->propose($soc2->fresh())->findings[0];

        $this->assertSame(FindingSeverity::Medium->value, $finding['proposed_severity']);
        $this->assertSame(['CC6.1'], $finding['control_refs']);
        $this->assertSame('25 terminations', $finding['population']);
        $this->assertStringContainsString('Section 4', $finding['citation']);
    }

    #[Test]
    public function an_auditor_stated_severity_is_carried_through(): void
    {
        $soc2 = $this->makeSoc2();
        Soc2Exception::create([
            'organization_id' => $this->organization->id, 'soc2_id' => $soc2->id,
            'control_reference' => 'CC6.1', 'description' => 'Administrative access was shared.',
            'severity_assessment' => 'high',
        ]);

        $this->assertSame(
            FindingSeverity::High->value,
            app(Soc2Cascade::class)->propose($soc2->fresh())->findings[0]['proposed_severity']
        );
    }

    #[Test]
    public function a_cuec_becomes_an_obligation_on_us_and_not_on_the_vendor(): void
    {
        // The whole point of the CUEC table: the report assumes the CUSTOMER
        // operates this control, and the customer is us.
        $soc2 = $this->makeSoc2();
        Soc2Cuec::create([
            'organization_id' => $this->organization->id, 'soc2_id' => $soc2->id,
            'cuec_reference' => 'CUEC-3',
            'description' => 'User entities are responsible for reviewing access reports quarterly.',
        ]);

        $obligation = app(Soc2Cascade::class)->propose($soc2->fresh())->obligations[0];

        $this->assertSame('entity', $obligation['obligor']);
        $this->assertStringContainsString('CUEC-3', $obligation['title']);
        $this->assertTrue($obligation['evidence_required']);
    }

    #[Test]
    public function only_a_carved_out_subservice_organisation_becomes_an_edge(): void
    {
        // An inclusive subservice organisation is covered by the report, so
        // there is no assurance gap to record.
        $soc2 = $this->makeSoc2();
        Soc2SubserviceOrg::create([
            'organization_id' => $this->organization->id, 'soc2_id' => $soc2->id,
            'name' => 'Amazon Web Services', 'method' => Soc2SubserviceOrg::METHOD_CARVE_OUT,
        ]);
        Soc2SubserviceOrg::create([
            'organization_id' => $this->organization->id, 'soc2_id' => $soc2->id,
            'name' => 'In-house payroll bureau', 'method' => Soc2SubserviceOrg::METHOD_INCLUSIVE,
        ]);

        $edges = app(Soc2Cascade::class)->propose($soc2->fresh())->nthPartyEdges;

        $this->assertCount(1, $edges);
        $this->assertSame('Amazon Web Services', $edges[0]['child_name_raw']);
        $this->assertSame(DisclosureSource::Soc2Carveout->value, $edges[0]['disclosure_source']);
        $this->assertSame('proposed', $edges[0]['confirmation_status']);
    }

    /* ------------------------------------------------------------------ */
    /*  The rule everything else rests on                                  */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_cascade_writes_nothing(): void
    {
        // "Everything is a proposal requiring a second confirmation." The
        // first confirmation says the extractor read the report correctly; the
        // second says to apply it to the register. Collapsing them is how a
        // misread report becomes twelve findings against a vendor.
        $this->issueCbnAssessment();
        $soc2 = $this->makeSoc2();
        Soc2Exception::create([
            'organization_id' => $this->organization->id, 'soc2_id' => $soc2->id,
            'control_reference' => 'CC6.1', 'description' => 'An exception.',
        ]);

        $before = [
            'responses' => AssessmentResponse::query()->where('compliance', '!=', 'unanswered')->count(),
            'exceptions' => Soc2Exception::query()->whereNotNull('linked_finding_id')->count(),
            'edges' => Soc2SubserviceOrg::query()->whereNotNull('proposed_nth_party_edge_id')->count(),
        ];

        $proposals = app(Soc2Cascade::class)->propose($soc2->fresh());
        $this->assertGreaterThan(0, $proposals->count());

        $this->assertSame($before['responses'], AssessmentResponse::query()->where('compliance', '!=', 'unanswered')->count());
        $this->assertSame($before['exceptions'], Soc2Exception::query()->whereNotNull('linked_finding_id')->count());
        $this->assertSame($before['edges'], Soc2SubserviceOrg::query()->whereNotNull('proposed_nth_party_edge_id')->count());
    }

    #[Test]
    public function an_answered_question_is_never_overwritten(): void
    {
        // If the vendor said non-compliant and the report says otherwise, that
        // disagreement is worth a human looking at, not resolving away.
        $assessment = $this->issueCbnAssessment();
        $assessment->responses()->update(['compliance' => 'non_compliant']);

        $this->assertSame([], app(Soc2Cascade::class)->propose($this->makeSoc2())->answers);
    }

    #[Test]
    public function a_submitted_assessment_is_not_pre_answered(): void
    {
        // Its answers are the record of what the vendor said at a point in
        // time; a cascade editing them afterwards rewrites history to agree
        // with a document that arrived later.
        $assessment = $this->issueCbnAssessment();
        $service = app(AssessmentService::class);
        $service->send($assessment);
        $service->transition($assessment, AssessmentStatus::InProgress);

        // Driven through `transition` rather than `submit`, because `submit`
        // refuses while answers are outstanding — and an assessment with every
        // question answered would prove nothing here, since an answered
        // question is excluded on its own account. What is under test is the
        // STATUS filter, so the status is what the test sets.
        $service->transition($assessment, AssessmentStatus::Submitted);
        $this->assertSame(AssessmentStatus::Submitted, $assessment->fresh()->status);

        $this->assertSame([], app(Soc2Cascade::class)->propose($this->makeSoc2())->answers);
    }

    #[Test]
    public function a_document_owned_by_a_third_party_pre_answers_nothing(): void
    {
        // A SOC 2 filed against the vendor rather than against an engagement
        // has no assessment to reach. It still produces its findings, CUECs
        // and edges — those are facts about the vendor, not about a service.
        $this->issueCbnAssessment();
        $document = $this->makeDocument('SOC 2', Document::OWNER_THIRD_PARTY, $this->engagement->third_party_id);
        $soc2 = $this->makeSoc2([], $document);
        Soc2Cuec::create([
            'organization_id' => $this->organization->id, 'soc2_id' => $soc2->id,
            'cuec_reference' => 'CUEC-1', 'description' => 'A user entity control.',
        ]);

        $proposals = app(Soc2Cascade::class)->propose($soc2->fresh());

        $this->assertSame([], $proposals->answers);
        $this->assertCount(1, $proposals->obligations);
    }

    /* ------------------------------------------------------------------ */

    private function issueCbnAssessment(): Assessment
    {
        $template = QuestionnaireTemplate::query()->withoutGlobalScopes()
            ->where('code', 'CBN-CYBER-CORE')->firstOrFail();

        return app(AssessmentService::class)->issue(
            $this->engagement,
            $template,
            now()->addDays(30),
            $this->user->id,
        );
    }

    /** @param array<string, mixed> $attributes */
    private function makeSoc2(array $attributes = [], ?Document $document = null): Soc2Detail
    {
        $document ??= $this->makeDocument('SOC 2 Type II report');

        return Soc2Detail::create($attributes + [
            'organization_id' => $this->organization->id,
            'document_id' => $document->id,
            'report_type' => Soc2Detail::TYPE_II,
            'period_start' => now()->subYear()->toDateString(),
            'period_end' => now()->subMonth()->toDateString(),
            'service_auditor' => 'Grant Thornton LLP',
            'tsc_categories' => ['security', 'availability', 'confidentiality'],
            'opinion_type' => 'unqualified',
            'subservice_method' => 'carve_out',
        ]);
    }

    private function makeDocument(string $title, ?string $ownerType = null, ?int $ownerId = null): Document
    {
        return Document::create([
            'organization_id' => $this->organization->id,
            'owner_type' => $ownerType ?? Document::OWNER_ENGAGEMENT,
            'owner_id' => $ownerId ?? $this->engagement->id,
            'title' => $title,
            'file_path' => 'tprm/'.Str::random(20).'.pdf',
            'mime' => 'application/pdf',
            'uploaded_by' => $this->user->id,
        ]);
    }

    private function makeEngagement(): Engagement
    {
        $vendor = ThirdParty::create([
            'legal_name' => 'Cloudspan Nigeria Limited', 'slug' => Str::random(10), 'entity_type' => 'company',
        ]);

        $engagement = Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => 'ENG-2026-0001',
            'name' => 'Core banking hosting',
            'engagement_type' => 'ict_service',
        ]);

        $engagement->businessFunctions()->attach(
            BusinessFunction::where('function_code', 'BF-HR-01')->value('id'),
            ['organization_id' => $this->organization->id]
        );

        return $engagement->refresh();
    }
}

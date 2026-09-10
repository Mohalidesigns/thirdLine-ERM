<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\AssuranceLevel;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentExtraction;
use App\Models\Tprm\DocumentType;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\QuestionnaireTemplate;
use App\Models\Tprm\Soc2Detail;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Assessment\AssessmentService;
use App\Services\Tprm\Evidence\EvidenceService;
use App\Services\Tprm\Evidence\ExpiryMonitor;
use App\Services\Tprm\Evidence\ScopeMatcher;
use App\Services\Tprm\Evidence\Soc2Cascade;
use App\Services\Tprm\Evidence\Soc2CascadeApplier;
use App\Services\Tprm\Extraction\ExtractionConfirmer;
use App\Services\Tprm\Extraction\ExtractionDispatcher;
use App\Services\Tprm\Extraction\LlmClient;
use Database\Seeders\Tprm\TprmQuestionnairePackSeeder;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The evidence library, the extraction pipeline, and AC-16.
 *
 * AC-16 IS THE ONE THAT MATTERS MOST HERE: "with every AI service disabled,
 * all workflows complete manually". It is tested not by asserting that a
 * disabled service returns nothing — that is trivially true — but by driving a
 * SOC 2 through the whole cascade with no model involved at any point, and
 * asserting the register ends up in exactly the state the AI path would have
 * produced.
 */
class EvidencePipelineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private Engagement $engagement;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        // Off by default, as the product ships. Individual tests turn it on.
        config()->set('tprm.ai.enabled', false);
        config()->set('tprm.ai.services.evidence_extraction', false);

        Storage::fake('local');

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
    /*  AC-16 — every workflow completes with no AI at all */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_whole_soc2_cascade_completes_with_ai_switched_off(): void
    {
        $this->assertFalse(app(LlmClient::class)->enabled(), 'The test started with AI enabled.');

        $assessment = $this->issueCbnAssessment();
        $document = $this->uploadSoc2();

        // A person typing what they read in the report. Identical shape to
        // what an extractor would have produced, through the same confirmer.
        $extraction = app(ExtractionConfirmer::class)->manual($document, 'soc2', [
            'report_type' => Soc2Detail::TYPE_II,
            'period_start' => now()->subYear()->toDateString(),
            'period_end' => now()->subMonth()->toDateString(),
            'service_auditor' => 'Grant Thornton LLP',
            'tsc_categories' => ['security', 'availability'],
            'opinion_type' => 'unqualified',
            'subservice_method' => 'carve_out',
            'exceptions' => [[
                'control_reference' => 'CC7.2',
                'description' => 'Two of forty alerts were not investigated within SLA.',
                'population' => '40 alerts', 'exceptions_noted' => '2 of 40',
            ]],
            'cuecs' => [[
                'cuec_reference' => 'CUEC-3',
                'description' => 'User entities review access reports quarterly.',
            ]],
            'subservice_orgs' => [[
                'name' => 'Amazon Web Services', 'services' => 'Hosting', 'method' => 'carve_out',
            ]],
        ], $this->user->id);

        // A manual entry records no model and no prompt version, and those
        // nulls are what say a person did this.
        $this->assertNull($extraction->model);
        $this->assertNull($extraction->prompt_version);
        $this->assertSame(DocumentExtraction::STATUS_CONFIRMED, $extraction->status);

        $soc2 = Soc2Detail::query()->where('document_id', $document->id)->firstOrFail();
        $this->assertSame(1, $soc2->exceptions()->count());
        $this->assertSame(1, $soc2->cuecs()->count());
        $this->assertSame(1, $soc2->subserviceOrgs()->count());

        // The cascade runs identically — it reads the structured record, and
        // cannot tell whether a model or a person filled it.
        $proposals = app(Soc2Cascade::class)->propose($soc2);
        $this->assertNotEmpty($proposals->answers);
        $this->assertCount(1, $proposals->findings);
        $this->assertCount(1, $proposals->obligations);
        $this->assertCount(1, $proposals->nthPartyEdges);

        // And applying them lands in the register.
        $applied = app(Soc2CascadeApplier::class)->apply($soc2, [
            'answers' => array_column($proposals->answers, 'response_id'),
            'cuecs' => [$soc2->cuecs()->value('id') => ['owner_id' => $this->user->id]],
        ], $this->user->id);

        $this->assertGreaterThan(0, $applied['answers_applied']);
        $this->assertSame(1, $applied['cuecs_assigned']);
        // Phase 4 completes the cascade's third leg: the CUEC is now a real
        // duty on the obligation register, owed by us, with an owner and a
        // date. Until it was, it was an assumption an auditor made on our
        // behalf that nobody here had agreed to.
        $this->assertSame(1, $applied['obligations_created']);
        $this->assertDatabaseHas('tp_obligations', [
            'engagement_id' => $this->engagement->id,
            'source' => 'assessment',
            'obligor' => 'entity',
            'owner_id' => $this->user->id,
        ]);
        // Phase 5: the findings leg lands too, opt-in per confirmation.
        $this->assertSame(1, $applied['findings_available']);
        $this->assertSame(0, $applied['findings_raised'], 'Findings were raised without being asked for.');
        // Still waiting on Phase 7, and named rather than dropped.
        $this->assertSame(1, $applied['edges_pending']);

        $answered = AssessmentResponse::query()
            ->where('assessment_id', $assessment->id)
            ->where('is_auto_answered', true)
            ->get();

        $this->assertGreaterThan(0, $answered->count());
        $this->assertSame(
            AssuranceLevel::IndependentlyAssured->value,
            $answered->first()->assurance_level?->value
        );
        $this->assertStringContainsString(
            'Grant Thornton',
            $answered->first()->auto_answer_source['citation']
        );
    }

    #[Test]
    public function dispatching_an_extraction_with_ai_off_reports_a_reason_and_writes_nothing(): void
    {
        $document = $this->uploadSoc2();

        $outcome = app(ExtractionDispatcher::class)->dispatch($document);

        $this->assertFalse($outcome->succeeded());
        $this->assertTrue($outcome->requiresManualEntry());
        // A calm sentence naming the manual path, not an error.
        $this->assertStringContainsString('by hand', (string) $outcome->message);
        $this->assertSame(0, DocumentExtraction::query()->count());
        $this->assertSame('unavailable', $document->fresh()->extraction_status);
    }

    #[Test]
    public function a_confirmed_soc2_pre_answers_a_questionnaire_scoped_afterwards(): void
    {
        // FR-ASM-06 through the inheritance resolver rather than the cascade:
        // the report was confirmed BEFORE the assessment existed, so there
        // were no responses to propose against.
        $document = $this->uploadSoc2();

        app(ExtractionConfirmer::class)->manual($document, 'soc2', [
            'report_type' => Soc2Detail::TYPE_II,
            'period_start' => now()->subYear()->toDateString(),
            'period_end' => now()->subMonth()->toDateString(),
            'service_auditor' => 'Grant Thornton LLP',
            'tsc_categories' => ['security'],
            'opinion_type' => 'unqualified',
            'exceptions' => [], 'cuecs' => [], 'subservice_orgs' => [],
        ], $this->user->id);

        $assessment = $this->issueCbnAssessment();

        $inherited = AssessmentResponse::query()
            ->where('assessment_id', $assessment->id)
            ->where('is_auto_answered', true)
            ->get();

        $this->assertGreaterThan(0, $inherited->count(), 'No question inherited from the confirmed report.');

        $source = $inherited->first()->auto_answer_source;
        $this->assertSame('document_extraction', $source['kind']);
        $this->assertNotEmpty($source['tsc_criterion']);
        $this->assertStringContainsString('SOC 2 Type II', $source['citation']);
    }

    #[Test]
    public function a_pending_extraction_pre_answers_nothing(): void
    {
        // The rule the whole AI posture rests on: nothing is applied until a
        // human confirms it.
        $document = $this->uploadSoc2();

        DocumentExtraction::create([
            'organization_id' => $this->organization->id,
            'document_id' => $document->id,
            'extractor' => 'soc2',
            'extracted' => ['report_type' => 'type_ii'],
            'status' => DocumentExtraction::STATUS_PENDING,
        ]);

        Soc2Detail::create([
            'organization_id' => $this->organization->id,
            'document_id' => $document->id,
            'report_type' => Soc2Detail::TYPE_II,
            'period_end' => now()->subMonth()->toDateString(),
            'tsc_categories' => ['security'],
        ]);

        $assessment = $this->issueCbnAssessment();

        $this->assertSame(0, AssessmentResponse::query()
            ->where('assessment_id', $assessment->id)
            ->where('is_auto_answered', true)
            ->count());
    }

    /* ------------------------------------------------------------------ */
    /*  The evidence library */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_uploaded_document_is_hashed_and_its_integrity_verifiable(): void
    {
        $document = $this->uploadSoc2();

        $this->assertNotNull($document->hash);
        $this->assertSame(64, strlen($document->hash));
        $this->assertTrue(app(EvidenceService::class)->verifyIntegrity($document));

        // The claim the hash exists to support: the file behind this record
        // has changed since it was recorded.
        Storage::disk('local')->put($document->file_path, 'something else entirely');
        $this->assertFalse(app(EvidenceService::class)->verifyIntegrity($document->fresh()));
    }

    #[Test]
    public function replacing_a_document_supersedes_it_without_deleting_it(): void
    {
        $original = $this->uploadSoc2();

        $replacement = app(EvidenceService::class)->replace(
            $original,
            UploadedFile::fake()->createWithContent('soc2-2026.pdf', 'the 2026 report'),
            ['valid_to' => now()->addYear()->toDateString()],
            $this->user->id,
        );

        $original->refresh();

        // A citation written last year points at the older document id, and a
        // citation that resolves to nothing is not a citation.
        $this->assertTrue($original->exists);
        $this->assertTrue($original->is_superseded);
        $this->assertSame($replacement->id, $original->superseded_by_id);
        $this->assertSame(2, $replacement->version);
        $this->assertFalse($original->isCurrent());
        $this->assertTrue($replacement->isCurrent());
    }

    #[Test]
    public function a_download_url_is_signed_and_expires_in_five_minutes(): void
    {
        $document = $this->uploadSoc2();

        $url = app(EvidenceService::class)->downloadUrl($document);

        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);

        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
        $expiresIn = (int) $query['expires'] - now()->timestamp;

        $this->assertGreaterThan(0, $expiresIn);
        $this->assertLessThanOrEqual(EvidenceService::SIGNED_URL_MINUTES * 60, $expiresIn);
    }

    #[Test]
    public function every_access_is_logged_to_the_append_only_trail(): void
    {
        $document = $this->uploadSoc2();

        $editsBefore = AuditLog::query()
            ->where('auditable_type', Document::class)
            ->whereIn('event', ['created', 'updated'])
            ->count();

        app(EvidenceService::class)->logAccess($document, $this->user->id);

        $entry = AuditLog::query()
            ->where('auditable_type', Document::class)
            ->where('event', 'document_downloaded')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, '"Who has read this vendor\'s report" had no answer.');
        $this->assertSame($this->user->id, $entry->actor_id);

        // The access did not become an edit in the document's own change
        // history: a reader scanning that history for what changed must not
        // find a download sitting among the edits.
        $this->assertSame($editsBefore, AuditLog::query()
            ->where('auditable_type', Document::class)
            ->whereIn('event', ['created', 'updated'])
            ->count());
    }

    #[Test]
    public function a_documents_expiry_defaults_from_its_type_and_never_from_a_guess(): void
    {
        $iso = DocumentType::query()->availableTo()->where('code', 'iso27001_cert')->firstOrFail();

        $withIssueDate = $this->upload('iso.pdf', $iso, ['issue_date' => '2026-01-15']);
        $withoutIssueDate = $this->upload('iso2.pdf', $iso, []);

        // 36 months from the issue date, per the type.
        $this->assertSame('2029-01-15', $withIssueDate->valid_to?->toDateString());
        // No issue date, so no expiry is invented — an invented one would age
        // out real evidence or keep dead evidence alive.
        $this->assertNull($withoutIssueDate->valid_to);
    }

    #[Test]
    public function the_expiry_monitor_fires_on_the_exact_window_and_not_between(): void
    {
        $type = DocumentType::query()->availableTo()->where('code', 'iso27001_cert')->firstOrFail();

        $at30 = $this->upload('a.pdf', $type, ['valid_to' => now()->addDays(30)->toDateString()]);
        $at45 = $this->upload('b.pdf', $type, ['valid_to' => now()->addDays(45)->toDateString()]);
        $this->upload('c.pdf', $type, ['valid_to' => now()->addDays(90)->toDateString()]);

        $due = app(ExpiryMonitor::class)->due()->pluck('document.id')->all();

        $this->assertContains($at30->id, $due);
        // Cumulative windows would mean a notice every day for ninety days,
        // which is how a channel gets muted.
        $this->assertNotContains($at45->id, $due);
        $this->assertCount(2, $due);
    }

    #[Test]
    public function a_superseded_document_never_notifies(): void
    {
        $type = DocumentType::query()->availableTo()->where('code', 'iso27001_cert')->firstOrFail();
        $old = $this->upload('old.pdf', $type, ['valid_to' => now()->addDays(30)->toDateString()]);
        $new = $this->upload('new.pdf', $type, ['valid_to' => now()->addYear()->toDateString()]);

        $old->supersede($new);

        $this->assertSame([], app(ExpiryMonitor::class)->due()->pluck('document.id')->all());
    }

    #[Test]
    public function the_expiry_sweep_command_runs_and_notifies(): void
    {
        $type = DocumentType::query()->availableTo()->where('code', 'iso27001_cert')->firstOrFail();
        $this->upload('a.pdf', $type, ['valid_to' => now()->addDays(7)->toDateString()]);

        $this->artisan('tprm:check-evidence-expiry')
            ->expectsOutputToContain('expiry notice(s)')
            ->assertExitCode(0);

        $this->assertDatabaseHas('notifications_log', [
            'organization_id' => $this->organization->id,
            'type' => 'tprm.evidence.expiring',
        ]);
    }

    #[Test]
    public function the_sweep_does_nothing_when_the_module_is_off(): void
    {
        config()->set('features.tprm', false);
        $type = DocumentType::query()->availableTo()->where('code', 'iso27001_cert')->firstOrFail();
        $this->upload('a.pdf', $type, ['valid_to' => now()->addDays(7)->toDateString()]);

        $this->artisan('tprm:check-evidence-expiry')->assertExitCode(0);

        $this->assertDatabaseMissing('notifications_log', ['type' => 'tprm.evidence.expiring']);
    }

    /* ------------------------------------------------------------------ */
    /*  FR-DDL-07 — the scope-mismatch check */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_certificate_whose_scope_excludes_the_service_is_flagged(): void
    {
        $document = $this->uploadSoc2();
        $document->forceFill([
            'scope_text' => 'Development and support of the Acme Payments Platform at the Lagos office.',
        ])->save();

        $result = app(ScopeMatcher::class)->check($document, $this->engagement);

        $this->assertTrue($result['checked']);
        $this->assertTrue($result['mismatch']);
        $this->assertSame(0.7, $result['modifier']);
        $this->assertStringContainsString('does not appear to name the service', $result['reason']);
    }

    #[Test]
    public function a_certificate_whose_scope_names_the_service_is_not_flagged(): void
    {
        $document = $this->uploadSoc2();
        $document->forceFill([
            'scope_text' => 'Core banking hosting and associated infrastructure operated from Lagos and Dublin.',
        ])->save();

        $result = app(ScopeMatcher::class)->check($document, $this->engagement);

        $this->assertFalse($result['mismatch']);
        $this->assertSame(1.0, $result['modifier']);
    }

    #[Test]
    public function an_uncaptured_scope_is_not_a_mismatch_but_is_not_a_pass_either(): void
    {
        // Neither flagged nor cleared. "We have not read the scope" and "the
        // scope covers this" are different claims and the second one is the
        // dangerous default.
        $result = app(ScopeMatcher::class)->check($this->uploadSoc2(), $this->engagement);

        $this->assertFalse($result['checked']);
        $this->assertFalse($result['mismatch']);
        $this->assertStringContainsString('records no scope statement', $result['reason']);
    }

    #[Test]
    public function a_broad_scope_covering_many_services_including_ours_passes(): void
    {
        // The asymmetry that makes the measure right: a certificate covering
        // forty systems including ours must not be penalised for being broad.
        $document = $this->uploadSoc2();
        $document->forceFill([
            'scope_text' => 'Payment processing, card issuing, treasury operations, core banking hosting, '
                .'reconciliation, statement production and customer onboarding across all Nigerian sites.',
        ])->save();

        $this->assertFalse(app(ScopeMatcher::class)->check($document, $this->engagement)['mismatch']);
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

    private function uploadSoc2(): Document
    {
        $type = DocumentType::query()->availableTo()->where('code', 'soc2_type2')->firstOrFail();

        return $this->upload('soc2-2025.pdf', $type, ['title' => 'SOC 2 Type II report']);
    }

    /** @param array<string, mixed> $attributes */
    private function upload(string $name, ?DocumentType $type, array $attributes): Document
    {
        return app(EvidenceService::class)->store(
            UploadedFile::fake()->createWithContent($name, 'report body for '.$name),
            Document::OWNER_ENGAGEMENT,
            $this->engagement->id,
            $type,
            $attributes,
            $this->user->id,
        );
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
            'service_description' => 'Hosting and operation of the core banking platform.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->user->id,
        ]);

        $engagement->businessFunctions()->attach(
            BusinessFunction::where('function_code', 'BF-HR-01')->value('id'),
            ['organization_id' => $this->organization->id]
        );

        return $engagement->refresh();
    }
}

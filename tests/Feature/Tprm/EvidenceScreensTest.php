<?php

namespace Tests\Feature\Tprm;

use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentType;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\QuestionnaireTemplate;
use App\Models\Tprm\Soc2Detail;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Assessment\AssessmentService;
use App\Services\Tprm\Evidence\EvidenceService;
use App\Services\Tprm\Extraction\ExtractionConfirmer;
use Database\Seeders\Tprm\TprmQuestionnairePackSeeder;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Phase 3 screens: the evidence library and the document workspace.
 *
 * Prop-level, as the suite's limits require (standard §10) — these prove the
 * server hands the page what it needs, not that the page draws it. The two
 * that matter most are the capability banner (an installation that cannot read
 * PDFs has to say so) and the proposals panel (the second confirmation has to
 * arrive as a list somebody can choose from, not a single Accept button).
 */
class EvidenceScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $analyst;

    private Engagement $engagement;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
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

        $this->analyst = $this->userWith([
            'tprm.view', 'tprm.assessment.view', 'tprm.assessment.issue',
            'tprm.evidence.view', 'tprm.evidence.upload', 'tprm.evidence.confirm',
        ], 'analyst@khb.test', 'tprm-analyst');

        $this->engagement = $this->makeEngagement();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  The library                                                        */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_library_renders_with_its_expiry_heat_map(): void
    {
        $type = $this->type('iso27001_cert');
        $this->upload('expired.pdf', $type, ['valid_to' => now()->subDay()->toDateString()]);
        $this->upload('soon.pdf', $type, ['valid_to' => now()->addDays(20)->toDateString()]);
        $this->upload('forever.pdf', $type, []);

        $this->actingAs($this->analyst)
            ->get(route('tprm.documents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/Documents/Index')
                ->where('summary.total', 3)
                ->where('summary.expired', 1)
                ->where('summary.expiring_30', 1)
                ->where('summary.no_expiry', 1)
                ->has('grid.rows.data', 3)
                ->where('can.upload', true)
            );
    }

    #[Test]
    public function the_library_states_what_this_installation_cannot_do(): void
    {
        // An installation with AI off must say so once, at the top, rather
        // than let somebody upload forty documents and wait for extraction
        // panels that will never populate.
        $this->actingAs($this->analyst)
            ->get(route('tprm.documents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('capabilities.ai_enabled', false)
                ->where('capabilities.manual_entry_always_available', true)
                ->has('capabilities.pdf_readable')
            );
    }

    #[Test]
    public function the_document_type_catalogue_reaches_the_upload_form_with_its_assurance_ceiling(): void
    {
        // The text that stops somebody filing an NDA as independent assurance.
        $this->actingAs($this->analyst)
            ->get(route('tprm.documents.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $types = collect($page->toArray()['props']['documentTypes']);

                $soc2 = $types->firstWhere('code', 'soc2_type2');
                $this->assertSame('independently_assured', $soc2['assurance_ceiling']);

                $soc2TypeI = $types->firstWhere('code', 'soc2_type1');
                // A Type I opines on design on one day. It must not offer the
                // same ceiling as a Type II.
                $this->assertSame('documented', $soc2TypeI['assurance_ceiling']);
            });
    }

    /* ------------------------------------------------------------------ */
    /*  The workspace                                                      */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_workspace_renders_the_document_and_its_signed_download(): void
    {
        $document = $this->uploadSoc2();

        $this->actingAs($this->analyst)
            ->get(route('tprm.documents.show', $document))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/Documents/Show')
                ->where('document.title', 'SOC 2 Type II report')
                ->where('document.owner_label', 'Engagement')
                ->has('document.hash')
                ->where('document.virus_scan_status', 'pending')
                ->has('document.download_url')
                ->where('extractions', [])
                ->where('soc2', null)
                ->where('proposals', null)
            );
    }

    #[Test]
    public function a_confirmed_soc2_reaches_the_workspace_with_its_proposals(): void
    {
        $this->issueCbnAssessment();
        $document = $this->uploadSoc2();
        $this->confirmSoc2($document);

        $this->actingAs($this->analyst)
            ->get(route('tprm.documents.show', $document))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('soc2.report_type', 'type_ii')
                ->where('soc2.service_auditor', 'Grant Thornton LLP')
                ->has('soc2.covered_criteria')
                ->has('soc2.exceptions', 1)
                ->has('soc2.cuecs', 1)
                ->has('soc2.subservice_orgs', 1)
                // The second confirmation arrives as a list to choose from,
                // not a single Accept button.
                ->has('proposals.answers')
                ->has('proposals.findings', 1)
                ->has('proposals.nth_party_edges', 1)
                ->where('proposals.bridge_letter_cap', false)
            );
    }

    #[Test]
    public function the_workspace_surfaces_a_scope_mismatch_with_its_score_effect(): void
    {
        $document = $this->uploadSoc2();
        $document->forceFill([
            'scope_text' => 'Development and support of the Acme Payments Platform at the Lagos office.',
        ])->save();

        $this->actingAs($this->analyst)
            ->get(route('tprm.documents.show', $document))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('scopeCheck.mismatch', true)
                ->where('scopeCheck.modifier', 0.7)
            );
    }

    /* ------------------------------------------------------------------ */
    /*  The two confirmations, over HTTP                                   */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function entering_details_by_hand_records_them_and_applying_them_is_a_separate_request(): void
    {
        $this->issueCbnAssessment();
        $document = $this->uploadSoc2();

        $this->actingAs($this->analyst)
            ->post(route('tprm.documents.manual', $document), [
                'extractor' => 'soc2',
                'fields' => [
                    'report_type' => 'type_ii',
                    'period_start' => now()->subYear()->toDateString(),
                    'period_end' => now()->subMonth()->toDateString(),
                    'service_auditor' => 'Grant Thornton LLP',
                    'tsc_categories' => ['security'],
                    'opinion_type' => 'unqualified',
                    'exceptions' => [], 'cuecs' => [], 'subservice_orgs' => [],
                ],
            ])
            ->assertRedirect();

        $soc2 = Soc2Detail::query()->where('document_id', $document->id)->firstOrFail();

        // Recording what the report says applied nothing.
        $this->assertSame(0, $this->autoAnsweredCount());

        $proposals = app(\App\Services\Tprm\Evidence\Soc2Cascade::class)->propose($soc2);

        $this->actingAs($this->analyst)
            ->post(route('tprm.documents.cascade', $document), [
                'answers' => array_column($proposals->answers, 'response_id'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertGreaterThan(0, $this->autoAnsweredCount());
    }

    private function autoAnsweredCount(): int
    {
        return AssessmentResponse::query()
            ->whereIn('assessment_id', Assessment::query()
                ->where('engagement_id', $this->engagement->id)->select('id'))
            ->where('is_auto_answered', true)
            ->count();
    }

    #[Test]
    public function a_download_needs_a_valid_signature(): void
    {
        $document = $this->uploadSoc2();

        // The signature IS the authorisation, and an unsigned request is
        // refused whoever makes it.
        $this->actingAs($this->analyst)
            ->get(route('tprm.documents.download', $document))
            ->assertForbidden();

        $this->actingAs($this->analyst)
            ->get(app(EvidenceService::class)->downloadUrl($document))
            ->assertOk();
    }

    #[Test]
    public function an_expired_signature_is_refused(): void
    {
        $document = $this->uploadSoc2();

        $url = URL::temporarySignedRoute(
            'tprm.documents.download',
            now()->subMinute(),
            ['document' => $document->uuid],
        );

        $this->actingAs($this->analyst)->get($url)->assertForbidden();
    }

    #[Test]
    public function a_user_without_the_confirm_permission_cannot_apply_a_cascade(): void
    {
        // Uploading a SOC 2 is filing. Applying what it proposes writes
        // pre-answers, and that is a different act by a different person.
        $viewer = $this->userWith(['tprm.evidence.view', 'tprm.evidence.upload'], 'viewer@khb.test', 'tprm-viewer');

        $this->issueCbnAssessment();
        $document = $this->uploadSoc2();
        $this->confirmSoc2($document);

        $this->actingAs($viewer)
            ->post(route('tprm.documents.cascade', $document), ['answers' => []])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /*  FR-DDL-07 — the certificate register                               */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_register_reports_what_is_missing_and_not_only_what_is_held(): void
    {
        // The whole point: an absence never appears in a list of presences.
        $this->upload('iso.pdf', $this->type('iso27001_cert'), [
            'title' => 'ISO/IEC 27001 certificate',
            'issuer' => 'BSI Group',
            'scope_text' => 'Core banking hosting and reconciliation from Lagos and Dublin.',
            'valid_to' => now()->addYear()->toDateString(),
        ]);

        $this->actingAs($this->analyst)
            ->get(route('tprm.third-parties.show', $this->engagement->thirdParty))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $register = $page->toArray()['props']['certificates'];
                $byCode = collect($register['certificates'])->keyBy('code');

                $this->assertSame('held', $byCode['iso27001_cert']['status']);
                $this->assertSame('BSI Group', $byCode['iso27001_cert']['document']['issuer']);

                // Expected of any third party, and not on file.
                $this->assertSame('missing', $byCode['soc2_type2']['status']);
                $this->assertTrue($byCode['soc2_type2']['expected']);

                // Not expected here, so its absence is reported without being
                // dressed up as a gap.
                $this->assertSame('not_held', $byCode['iso9001_cert']['status']);
                $this->assertFalse($byCode['iso9001_cert']['expected']);

                $this->assertGreaterThan(0, $register['missing_expected']);
            });
    }

    #[Test]
    public function iso20000_is_expected_of_an_ict_engagement_and_cites_the_blueprint(): void
    {
        // The one expectation here that comes from a Nigerian supervisory
        // document rather than general practice, and the one a bank examined
        // against that Blueprint would be asked about.
        $this->actingAs($this->analyst)
            ->get(route('tprm.third-parties.show', $this->engagement->thirdParty))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $entry = collect($page->toArray()['props']['certificates']['certificates'])
                    ->firstWhere('code', 'iso20000_cert');

                $this->assertTrue($entry['expected']);
                $this->assertStringContainsString('Blueprint', $entry['expectation_basis']);
            });
    }

    #[Test]
    public function a_lapsed_certificate_is_reported_as_lapsed_rather_than_absent(): void
    {
        // Different messages to the same reader: "we never had one" and "the
        // one we had ran out" lead to different conversations with the vendor.
        $this->upload('old-iso.pdf', $this->type('iso27001_cert'), [
            'title' => 'ISO/IEC 27001 certificate (2022)',
            'valid_to' => now()->subMonth()->toDateString(),
        ]);

        $this->actingAs($this->analyst)
            ->get(route('tprm.third-parties.show', $this->engagement->thirdParty))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $register = $page->toArray()['props']['certificates'];
                $entry = collect($register['certificates'])->firstWhere('code', 'iso27001_cert');

                $this->assertSame('expired', $entry['status']);
                $this->assertNull($entry['document']);
                $this->assertCount(1, $entry['superseded_or_expired']);
                $this->assertSame(1, $register['expired']);
            });
    }

    #[Test]
    public function the_register_names_the_engagements_a_certificate_does_not_cover(): void
    {
        // A certificate can cover one service we buy and not another, which is
        // exactly the failure FR-DDL-07 exists to catch — so the check runs
        // per engagement rather than once per certificate.
        $this->upload('narrow-iso.pdf', $this->type('iso27001_cert'), [
            'title' => 'ISO/IEC 27001 certificate',
            'scope_text' => 'Development and support of the Acme Payments Platform at the Lagos office.',
            'valid_to' => now()->addYear()->toDateString(),
        ]);

        $this->actingAs($this->analyst)
            ->get(route('tprm.third-parties.show', $this->engagement->thirdParty))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $entry = collect($page->toArray()['props']['certificates']['certificates'])
                    ->firstWhere('code', 'iso27001_cert');

                $this->assertCount(1, $entry['scope_gaps']);
                $this->assertSame('ENG-2026-0001', $entry['scope_gaps'][0]['engagement']);
                $this->assertSame(0.7, $entry['scope_gaps'][0]['modifier']);
            });
    }

    /* ------------------------------------------------------------------ */

    private function confirmSoc2(Document $document): void
    {
        app(ExtractionConfirmer::class)->manual($document, 'soc2', [
            'report_type' => 'type_ii',
            'period_start' => now()->subYear()->toDateString(),
            'period_end' => now()->subMonth()->toDateString(),
            'service_auditor' => 'Grant Thornton LLP',
            'tsc_categories' => ['security', 'availability'],
            'opinion_type' => 'unqualified',
            'subservice_method' => 'carve_out',
            'exceptions' => [[
                'control_reference' => 'CC8.1',
                'description' => 'One change was deployed without documented approval.',
                'population' => '25 changes', 'exceptions_noted' => '1 of 25',
            ]],
            'cuecs' => [[
                'cuec_reference' => 'CUEC-3',
                'description' => 'User entities review access reports quarterly.',
            ]],
            'subservice_orgs' => [[
                'name' => 'Amazon Web Services', 'services' => 'Hosting', 'method' => 'carve_out',
            ]],
        ], $this->analyst->id);
    }

    private function issueCbnAssessment(): void
    {
        app(AssessmentService::class)->issue(
            $this->engagement,
            QuestionnaireTemplate::query()->withoutGlobalScopes()->where('code', 'CBN-CYBER-CORE')->firstOrFail(),
            now()->addDays(30),
            $this->analyst->id,
        );
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::query()->availableTo()->where('code', $code)->firstOrFail();
    }

    private function uploadSoc2(): Document
    {
        return $this->upload('soc2.pdf', $this->type('soc2_type2'), ['title' => 'SOC 2 Type II report']);
    }

    /** @param array<string, mixed> $attributes */
    private function upload(string $name, DocumentType $type, array $attributes): Document
    {
        return app(EvidenceService::class)->store(
            UploadedFile::fake()->createWithContent($name, 'report body for '.$name),
            Document::OWNER_ENGAGEMENT,
            $this->engagement->id,
            $type,
            $attributes,
            $this->analyst->id,
        );
    }

    /** @param list<string> $permissions */
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
            'relationship_owner_id' => $this->analyst->id,
        ]);

        $engagement->businessFunctions()->attach(
            BusinessFunction::where('function_code', 'BF-HR-01')->value('id'),
            ['organization_id' => $this->organization->id]
        );

        return $engagement->refresh();
    }
}

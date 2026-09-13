<?php

namespace Tests\Feature\TprmPortal;

use App\Enums\Tprm\ClausePresence;
use App\Enums\Tprm\DisclosureSource;
use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\ClauseLibraryEntry;
use App\Models\Tprm\Contract;
use App\Models\Tprm\ContractClause;
use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentType;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\Incident;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\NthPartyEdge;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Portal\PortalEvidenceService;
use App\Services\Tprm\Portal\PortalIncidentService;
use App\Services\Tprm\Portal\SubprocessorDeclarationService;
use App\Support\Auth\Totp;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\OrganizationScope;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * What a vendor MAINTAINS rather than answers — FR-PRT-05, 06 and 07.
 *
 * THE INTERESTING ASSERTIONS ARE THE ONES ABOUT WHAT WE DO NOT DO: no consent
 * right invented where the contract is silent, no green virus tick for a scan
 * nobody ran, no regulatory deadline computed by a phase that does not own the
 * clock engine, and no editable timestamp standing as the evidence for when a
 * statutory clock began.
 */
class VendorRecordTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private PortalUser $vendor;

    private User $owner;

    private Engagement $engagement;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        Storage::fake('local');

        $this->bank = Organization::create([
            'name' => 'Lagos Union Bank', 'short_name' => 'LUB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        $this->owner = User::create([
            'name' => 'Relationship Owner', 'email' => 'owner@lub.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        TenantContext::actingAs($this->bank->id, fn () => $this->seed(TprmReferenceSeeder::class));

        [$this->vendor, $this->engagement] = $this->makeVendor();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Evidence — FR-PRT-05 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_vendor_uploads_its_own_evidence_and_it_is_marked_as_theirs(): void
    {
        $document = TenantContext::actingAs($this->bank->id, fn () => app(PortalEvidenceService::class)->upload(
            $this->vendor,
            UploadedFile::fake()->create('iso27001.pdf', 120, 'application/pdf'),
            null,
            ['title' => 'ISO 27001 certificate', 'valid_to' => now()->addYear()->toDateString()],
        ));

        $this->assertSame('portal', $document->uploaded_via);
        $this->assertSame(Document::OWNER_THIRD_PARTY, $document->owner_type);
        $this->assertSame($this->vendor->third_party_id, (int) $document->owner_id);

        // Never a green tick for a scan nobody ran — the product ships without
        // a virus scanner and says so.
        $this->assertSame('pending', $document->virus_scan_status);
    }

    #[Test]
    public function an_expiring_type_demands_the_date_the_reminder_is_set_from(): void
    {
        $type = TenantContext::actingAs($this->bank->id, fn () => DocumentType::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('has_expiry', true)
            ->firstOrFail());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/remind you before it does/');

        TenantContext::actingAs($this->bank->id, fn () => app(PortalEvidenceService::class)->upload(
            $this->vendor,
            UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
            $type,
            ['title' => 'A certificate with no expiry given'],
        ));
    }

    #[Test]
    public function replacing_a_document_supersedes_rather_than_overwrites(): void
    {
        TenantContext::actingAs($this->bank->id, function (): void {
            $service = app(PortalEvidenceService::class);

            $original = $service->upload(
                $this->vendor,
                UploadedFile::fake()->create('soc2-2025.pdf', 100, 'application/pdf'),
                null,
                ['title' => 'SOC 2 Type II', 'valid_to' => now()->subDay()->toDateString()],
            );

            $replacement = $service->replace(
                $this->vendor,
                $original,
                UploadedFile::fake()->create('soc2-2026.pdf', 100, 'application/pdf'),
                ['valid_to' => now()->addYear()->toDateString()],
            );

            // The old certificate is the evidence the vendor WAS certified
            // during the period a past assessment relied on. Deleting it
            // rewrites the answer to a question somebody already asked.
            $this->assertTrue($original->refresh()->is_superseded);
            $this->assertSame($replacement->getKey(), $original->superseded_by_id);
            $this->assertSame('portal', $replacement->uploaded_via);
        });
    }

    #[Test]
    public function a_vendor_cannot_replace_another_vendors_document(): void
    {
        [$other] = $this->makeVendor('Zenith Print Services Limited', 'admin@zenithprint.test');

        $theirs = TenantContext::actingAs($this->bank->id, fn () => app(PortalEvidenceService::class)->upload(
            $other,
            UploadedFile::fake()->create('theirs.pdf', 100, 'application/pdf'),
            null,
            ['title' => 'Their certificate'],
        ));

        $this->expectException(InvalidArgumentException::class);

        TenantContext::actingAs($this->bank->id, fn () => app(PortalEvidenceService::class)->replace(
            $this->vendor,
            $theirs,
            UploadedFile::fake()->create('mine.pdf', 100, 'application/pdf'),
            [],
        ));
    }

    #[Test]
    public function the_shipped_document_type_catalogue_is_offered_to_the_vendor(): void
    {
        // The recurring trap: the catalogue carries organization_id = null and
        // the tenancy scope hides it, so the vendor is offered an empty list
        // and concludes the portal is broken.
        $types = TenantContext::actingAs(
            $this->bank->id,
            fn () => app(PortalEvidenceService::class)->uploadableTypes($this->bank->id),
        );

        $this->assertGreaterThan(0, $types->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Sub-processors — FR-PRT-06 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_declaration_is_gated_where_the_contract_requires_consent(): void
    {
        $this->giveContractA(ClausePresence::Present);

        $result = TenantContext::actingAs($this->bank->id, fn () => app(SubprocessorDeclarationService::class)
            ->declare($this->vendor, 'Rack Centre Limited', ['service_description' => 'Colocation']));

        $this->assertTrue($result['consent_required']);
        $this->assertStringContainsString('wait for confirmation', $result['message']);

        // Recorded as PROPOSED, so it is visible for review and does not enter
        // the confirmed graph the concentration analysis walks.
        $this->assertSame(NthPartyEdge::STATUS_PROPOSED, $result['edge']->confirmation_status);
        $this->assertSame(DisclosureSource::VendorDeclared, $result['edge']->disclosure_source);
    }

    #[Test]
    public function no_consent_right_is_invented_where_the_contract_is_silent(): void
    {
        $this->giveContractA(ClausePresence::Absent);

        $result = TenantContext::actingAs($this->bank->id, fn () => app(SubprocessorDeclarationService::class)
            ->declare($this->vendor, 'Rack Centre Limited'));

        $this->assertFalse($result['consent_required']);

        // The absence is stated plainly rather than dressed up. Telling a
        // relationship owner they can block something they cannot is worse
        // than telling them nothing.
        $gate = app(SubprocessorDeclarationService::class)->consentGate($this->vendor->thirdParty);
        $this->assertStringContainsString('no contractual right to object', $gate['basis']);
    }

    #[Test]
    public function a_declaration_raises_a_review_task_for_the_relationship_owner(): void
    {
        $this->giveContractA(ClausePresence::Present);

        TenantContext::actingAs($this->bank->id, function (): void {
            app(SubprocessorDeclarationService::class)->declare($this->vendor, 'Rack Centre Limited');

            $finding = Finding::query()
                ->where('engagement_id', $this->engagement->id)
                ->latest('id')
                ->firstOrFail();

            $this->assertStringContainsString('Rack Centre Limited', $finding->title);
            $this->assertSame('high', $finding->severity->value);
            $this->assertStringContainsString('consent clause', $finding->description);
        });
    }

    #[Test]
    public function a_declaration_that_would_loop_is_refused_in_the_vendors_own_words(): void
    {
        // The vendor naming a company that already depends on IT.
        $downstream = ThirdParty::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->findOrFail($this->vendor->third_party_id);

        $upstream = TenantContext::actingAs($this->bank->id, fn () => ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => 'Upstream Holdings Limited',
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]));

        TenantContext::actingAs($this->bank->id, fn () => app(\App\Services\Tprm\Graph\NthPartyService::class)
            ->record($upstream, $downstream->legal_name, $downstream, DisclosureSource::VendorDeclared));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/loop in the supply chain/');

        TenantContext::actingAs($this->bank->id, fn () => app(SubprocessorDeclarationService::class)
            ->declare($this->vendor, 'Upstream Holdings Limited'));
    }

    /* ------------------------------------------------------------------ */
    /*  Incidents — FR-PRT-07 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function reporting_an_incident_stamps_a_receipt_and_flags_reportability(): void
    {
        $incident = TenantContext::actingAs($this->bank->id, fn () => app(PortalIncidentService::class)->report(
            $this->vendor,
            [
                'title' => 'Unauthorised access to a support mailbox',
                'type' => 'data_breach',
                'description' => 'A support mailbox was accessed by an unknown party.',
                'detected_at' => now()->subHours(3),
                'personal_data_involved' => true,
                'customer_impact' => false,
            ],
        ));

        $this->assertNotNull($incident->reported_to_us_at);
        $this->assertSame(Incident::SOURCE_PORTAL, $incident->reported_by);
        $this->assertStringContainsString($incident->reference, $incident->receipt());

        // FLAGS, not deadlines. Phase 9 owns the clock engine, and a deadline
        // computed here from a half-built rule would be believed.
        $this->assertTrue($incident->ndpc_reportable);
        $this->assertNull($incident->ndpc_deadline_at);
        $this->assertNull($incident->cbn_deadline_at);
    }

    #[Test]
    public function the_clock_start_timestamp_is_not_mass_assignable(): void
    {
        $incident = TenantContext::actingAs($this->bank->id, fn () => app(PortalIncidentService::class)->report(
            $this->vendor,
            [
                'title' => 'An outage',
                'type' => 'outage',
                'description' => 'Service was unavailable.',
                'detected_at' => now()->subHour(),
            ],
        ));

        $received = $incident->reported_to_us_at;

        // NDPA §40(1) makes the processor's notification the recorded clock
        // start. If a form can post that timestamp, the bank's whole account of
        // when its own 72 hours began is a number somebody could have changed.
        $incident->fill(['reported_to_us_at' => now()->addDays(3)]);
        $incident->save();

        $this->assertEquals($received, $incident->refresh()->reported_to_us_at);
    }

    #[Test]
    public function the_receipt_is_written_to_the_append_only_log_as_well_as_the_row(): void
    {
        $incident = TenantContext::actingAs($this->bank->id, fn () => app(PortalIncidentService::class)->report(
            $this->vendor,
            ['title' => 'An incident', 'type' => 'security', 'description' => 'Detail.', 'detected_at' => now()],
        ));

        $entry = AuditLog::query()
            ->where('event', 'incident_reported_by_vendor')
            ->where('auditable_id', $incident->getKey())
            ->firstOrFail();

        // The row is editable by our own staff; the hash-chained log is not.
        $this->assertSame($this->vendor->email, $entry->after['reported_by_email']);
        $this->assertNotNull($entry->after['reported_to_us_at']);
    }

    /* ------------------------------------------------------------------ */
    /*  Screens */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_three_screens_render_for_a_signed_in_vendor(): void
    {
        $this->signIn();

        $this->get(route('tprm-portal.documents.index'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('TprmPortal/Documents')->has('types'));

        $this->get(route('tprm-portal.subprocessors.index'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('TprmPortal/Subprocessors')->has('consent.basis'));

        $this->get(route('tprm-portal.incidents.index'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('TprmPortal/Incidents'));
    }

    #[Test]
    public function the_upload_endpoint_refuses_a_file_type_outside_the_allowlist(): void
    {
        $this->signIn();

        $this->post(route('tprm-portal.documents.upload'), [
            'file' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
            'title' => 'Not a certificate',
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, Document::query()->where('owner_id', $this->vendor->third_party_id)->count());
    }

    /* ------------------------------------------------------------------ */

    private function giveContractA(ClausePresence $presence): void
    {
        TenantContext::actingAs($this->bank->id, function () use ($presence): void {
            $clause = ClauseLibraryEntry::query()
                ->withoutGlobalScope(OrganizationScope::class)
                ->where('code', SubprocessorDeclarationService::CONSENT_CLAUSE_CODE)
                ->firstOrFail();

            $contract = Contract::create([
                'organization_id' => $this->bank->id,
                'engagement_id' => $this->engagement->id,
                'reference' => 'CTR-'.Str::upper(Str::random(5)),
                'title' => 'Master services agreement',
                'contract_type' => 'msa',
                'status' => Contract::STATUS_EXECUTED,
                'effective_date' => now()->subYear()->toDateString(),
                'expiry_date' => now()->addYear()->toDateString(),
            ]);

            ContractClause::create([
                'organization_id' => $this->bank->id,
                'contract_id' => $contract->id,
                'clause_library_id' => $clause->getKey(),
                'presence' => $presence->value,
            ]);
        });
    }

    /** @return array{0: PortalUser, 1: Engagement} */
    private function makeVendor(
        string $name = 'Cloudspan Nigeria Limited',
        string $email = 'ops@cloudspan.test',
    ): array {
        return TenantContext::actingAs($this->bank->id, function () use ($name, $email): array {
            $party = ThirdParty::create([
                'organization_id' => $this->bank->id,
                'legal_name' => $name,
                'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
            ]);

            $engagement = Engagement::create([
                'organization_id' => $this->bank->id,
                'third_party_id' => $party->id,
                'reference' => 'ENG-'.Str::upper(Str::random(6)),
                'name' => $name.' services',
                'service_description' => 'Fixture.',
                'engagement_type' => 'ict_service',
                'relationship_owner_id' => $this->owner->id,
            ]);

            $engagement->forceFill([
                'status' => EngagementStatus::Active->value,
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

            $user = new PortalUser([
                'organization_id' => $this->bank->id,
                'third_party_id' => $party->id,
                'email' => $email,
                'name' => 'Vendor Operator',
                'password' => 'Sup3r-Str0ng-P@ssphrase!',
            ]);

            $user->forceFill([
                'status' => PortalUser::STATUS_ACTIVE,
                'accepted_at' => now(),
                'mfa_method' => PortalUser::METHOD_TOTP,
                'mfa_secret' => Totp::generateSecret(),
                'mfa_enabled' => true,
            ])->save();

            return [$user, $engagement->refresh()];
        });
    }

    private function signIn(): void
    {
        $this->post(route('tprm-portal.login.attempt', ['client' => $this->bank->uuid]), [
            'email' => $this->vendor->email,
            'password' => 'Sup3r-Str0ng-P@ssphrase!',
        ]);

        $this->post(route('tprm-portal.mfa.verify'), [
            'code' => Totp::at((string) $this->vendor->mfa_secret, time()),
        ]);
    }
}

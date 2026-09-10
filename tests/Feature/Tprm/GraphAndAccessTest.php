<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\AccessGrantStatus;
use App\Enums\Tprm\AccessLevel;
use App\Enums\Tprm\ConnectionStatus;
use App\Enums\Tprm\ConnectionType;
use App\Enums\Tprm\DisclosureSource;
use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\RiskTier;
use App\Exceptions\Tprm\OpenAccessException;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\AccessGrant;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\ConcentrationAnalysis;
use App\Models\Tprm\Connection;
use App\Models\Tprm\Document;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\NthPartyEdge;
use App\Models\Tprm\ThirdParty;
use App\Models\Tprm\Waiver;
use App\Models\User;
use App\Services\Tprm\Access\AccessService;
use App\Services\Tprm\Access\TerminationGuard;
use App\Services\Tprm\Graph\ConcentrationAnalyzer;
use App\Services\Tprm\Graph\ConcentrationService;
use App\Services\Tprm\Graph\NthPartyGraph;
use App\Services\Tprm\Graph\NthPartyService;
use App\Services\Tprm\Graph\SubprocessorDiscoverer;
use App\Services\Tprm\IntakeService;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Phase 7's acceptance criteria.
 *
 *   AC-09: with five critical functions depending on one provider group
 *   against a threshold of three, the analysis flags the breach, the HHI
 *   reflects it, and the provider tops the single-points-of-failure table with
 *   its substitutability rating.
 *
 *   AC-10: terminating an engagement with an active access grant is blocked;
 *   the reconciliation report lists the exception; revoking with evidence
 *   clears it.
 *
 *   A four-deep cycle attempt is rejected.
 */
class GraphAndAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private ThirdParty $vendor;

    private Engagement $engagement;

    private int $engagementSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        config()->set('tprm.ai.enabled', false);

        $this->organization = Organization::create([
            'name' => 'Lagos Union Bank', 'short_name' => 'LUB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->manager = $this->userWith([
            'tprm.view', 'tprm.graph.view', 'tprm.graph.manage',
            'tprm.access.view', 'tprm.access.manage',
            'tprm.finding.view', 'tprm.finding.manage',
        ], 'tprm@lub.test', 'tprm-manager');

        $this->vendor = $this->makeVendor('Cloudspan Nigeria Limited');
        $this->engagement = $this->makeEngagement($this->vendor, 'Core banking hosting');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  AC-09 — concentration */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function five_critical_functions_on_one_provider_group_breach_a_threshold_of_three(): void
    {
        config()->set('tprm.scoring.concentration.max_critical_functions_per_group', 3);

        $this->concentrateFiveCriticalFunctionsOn($this->vendor);

        $analysis = app(ConcentrationService::class)->run($this->organization->id, 'provider_group');

        $breaches = collect($analysis->threshold_breaches);
        $criticalBreach = $breaches->firstWhere('threshold', 'max_critical_functions_per_group');

        $this->assertNotNull($criticalBreach, 'The critical-function threshold should have been breached.');
        $this->assertSame(5, $criticalBreach['value']);
        $this->assertSame(3, $criticalBreach['limit']);
        $this->assertStringContainsString('Cloudspan', $criticalBreach['message']);
    }

    #[Test]
    public function the_index_reflects_the_concentration_and_names_the_band(): void
    {
        $this->concentrateFiveCriticalFunctionsOn($this->vendor);

        $analyzer = app(ConcentrationAnalyzer::class);
        $result = $analyzer->analyse($this->organization->id, 'provider_group');

        // Everything on one provider: the index is at its ceiling, which is
        // the arithmetic saying what the portfolio looks like.
        $this->assertSame(10000.0, $result['hhi']);
        $this->assertSame('concentrated', $result['band']);

        // And the HHI itself is one of the breaches, not merely a display.
        $this->assertNotNull(
            collect($result['threshold_breaches'])->firstWhere('threshold', 'hhi'),
            'A ceiling index should breach the concentrated band.',
        );
    }

    #[Test]
    public function the_provider_tops_the_single_points_of_failure_table_with_its_substitutability(): void
    {
        $this->concentrateFiveCriticalFunctionsOn($this->vendor);

        // A second, less concentrated provider, so "top" means something.
        $other = $this->makeVendor('Zenith Print Services Limited');
        $this->makeEngagement($other, 'Statement printing', criticalFunctions: 1);

        $result = app(ConcentrationAnalyzer::class)->analyse($this->organization->id, 'provider_group');

        $spof = $result['spof'];

        $this->assertNotEmpty($spof);
        $this->assertSame('Cloudspan Nigeria Limited', $spof[0]['label']);
        $this->assertSame(5, $spof[0]['critical_functions']);
        $this->assertSame('none', $spof[0]['substitutability']);
        $this->assertSame(18, $spof[0]['time_to_replace_months']);
        $this->assertStringContainsString('Cloudspan', $spof[0]['note']);
    }

    #[Test]
    public function a_snapshot_is_immutable_so_last_quarters_figure_survives(): void
    {
        $this->concentrateFiveCriticalFunctionsOn($this->vendor);

        $analysis = app(ConcentrationService::class)->run($this->organization->id, 'provider_group');

        $this->expectException(\RuntimeException::class);

        $analysis->update(['hhi' => 1]);
    }

    #[Test]
    public function the_breach_alert_fires_once_and_not_every_run(): void
    {
        config()->set('tprm.scoring.concentration.max_critical_functions_per_group', 3);
        $this->concentrateFiveCriticalFunctionsOn($this->vendor);

        \Illuminate\Support\Facades\Event::fake([
            \App\Events\Tprm\ConcentrationThresholdBreached::class,
        ]);

        $service = app(ConcentrationService::class);

        $service->run($this->organization->id, 'provider_group');
        $service->run($this->organization->id, 'provider_group');

        // Twice run, once alerted: a portfolio that breaches on Monday
        // breaches every day, and a daily alert stops being read.
        \Illuminate\Support\Facades\Event::assertDispatchedTimes(
            \App\Events\Tprm\ConcentrationThresholdBreached::class,
            1,
        );
    }

    #[Test]
    public function analysing_writes_nothing_so_a_screen_render_does_not_pollute_the_history(): void
    {
        $this->concentrateFiveCriticalFunctionsOn($this->vendor);

        app(ConcentrationAnalyzer::class)->analyse($this->organization->id, 'provider_group');
        app(ConcentrationAnalyzer::class)->analyse($this->organization->id, 'provider_group');

        $this->assertSame(0, ConcentrationAnalysis::query()->count());
    }

    #[Test]
    public function the_demo_portfolio_shows_both_of_its_concentration_clusters(): void
    {
        $this->seed(\Database\Seeders\Tprm\TprmDemoSeeder::class);
        TenantContext::set($this->organization->id);

        $result = app(ConcentrationAnalyzer::class)->analyse($this->organization->id, 'provider_group');

        $clusters = collect($result['clusters']);

        // Cluster one: contracted straight to one switch. Any bank can see
        // this in a spreadsheet.
        $interswitch = $clusters->firstWhere('label', 'Interswitch Limited');
        $this->assertNotNull($interswitch);
        $this->assertSame(3, $interswitch['engagements']);
        $this->assertSame(3, $interswitch['critical_functions']);
        $this->assertFalse($interswitch['indirect']);

        // Cluster two: three vendors chosen independently, one cloud
        // underneath. No engagement mentions AWS — the exposure exists only in
        // the sub-processor graph, and this is the one no spreadsheet has.
        $aws = $clusters->firstWhere('label', 'Amazon Web Services');
        $this->assertNotNull($aws, 'The indirect cloud cluster should have been found through the graph.');
        $this->assertSame(3, $aws['engagements']);
        $this->assertSame(3, $aws['critical_functions']);
        $this->assertTrue($aws['indirect'], 'Nobody contracted with AWS directly.');

        // Both appear in the single-points-of-failure table.
        $spofLabels = collect($result['spof'])->pluck('label');
        $this->assertTrue($spofLabels->contains('Interswitch Limited'));
        $this->assertTrue($spofLabels->contains('Amazon Web Services'));
    }

    #[Test]
    public function the_demo_portfolios_index_is_computed_from_its_shape(): void
    {
        $this->seed(\Database\Seeders\Tprm\TprmDemoSeeder::class);
        TenantContext::set($this->organization->id);

        $analyzer = app(ConcentrationAnalyzer::class);
        $result = $analyzer->analyse($this->organization->id, 'provider_group');

        $clusters = collect($result['clusters']);
        $total = $clusters->sum('critical_functions');

        // The HHI is the sum of squared shares over critical-function
        // dependency — asserted against the arithmetic rather than a magic
        // number, so a change to the portfolio moves both sides together.
        $expected = round($clusters->sum(
            fn (array $cluster): float => (($cluster['critical_functions'] / $total) * 100) ** 2,
        ), 2);

        $this->assertSame($expected, $result['hhi']);
        $this->assertSame($analyzer->band($expected), $result['band']);
    }

    /* ------------------------------------------------------------------ */
    /*  The graph */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_four_deep_cycle_is_rejected(): void
    {
        $service = app(NthPartyService::class);

        $b = $this->makeVendor('Beta Hosting Limited');
        $c = $this->makeVendor('Gamma Networks Limited');
        $d = $this->makeVendor('Delta Fibre Limited');

        // A → B → C → D, four levels deep.
        $service->record($this->vendor, $b->legal_name, $b, DisclosureSource::VendorDeclared);
        $service->record($b, $c->legal_name, $c, DisclosureSource::VendorDeclared);
        $service->record($c, $d->legal_name, $d, DisclosureSource::VendorDeclared);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/loop/i');

        // D → A closes the loop.
        $service->record($d, $this->vendor->legal_name, $this->vendor, DisclosureSource::VendorDeclared);
    }

    #[Test]
    public function a_self_edge_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(NthPartyService::class)->record(
            $this->vendor,
            $this->vendor->legal_name,
            $this->vendor,
            DisclosureSource::VendorDeclared,
        );
    }

    #[Test]
    public function an_unmatched_sub_processor_is_still_a_node(): void
    {
        app(NthPartyService::class)->record(
            $this->vendor,
            'a large public cloud provider',
            null,
            DisclosureSource::Soc2Carveout,
        );

        $graph = app(NthPartyGraph::class)->descendants([$this->vendor->id], 3);

        $names = collect($graph['nodes'])->pluck('name')->all();

        // The most common disclosure is a name in a document. A graph that
        // dropped it would understate the chain by exactly the exposure the
        // table exists to hold.
        $this->assertContains('a large public cloud provider', $names);
        $this->assertFalse(collect($graph['nodes'])->firstWhere('name', 'a large public cloud provider')['matched']);
    }

    #[Test]
    public function discovery_proposes_known_providers_and_confirms_nothing(): void
    {
        $result = app(SubprocessorDiscoverer::class)->discover(
            $this->vendor,
            "Annex 2 — Sub-processors\n"
            ."Amazon Web Services EMEA SARL — infrastructure hosting, Ireland\n"
            ."Rack Centre Limited — colocation, Lagos\n"
            ."Some Unlisted Vendor Ltd — courier services\n",
        );

        $this->assertSame(['Amazon Web Services', 'Rack Centre'], $result['matched']);
        $this->assertCount(2, $result['proposed']);

        // Nothing is in the graph until a person says so.
        foreach ($result['proposed'] as $edge) {
            $this->assertSame(NthPartyEdge::STATUS_PROPOSED, $edge->confirmation_status);
        }

        // The reviewer's view shows them — nobody can confirm what they cannot
        // see — but the concentration walk does not count them.
        $visible = app(NthPartyGraph::class)->descendants([$this->vendor->id], 2);
        $counted = app(NthPartyGraph::class)->descendants([$this->vendor->id], 2, confirmedOnly: true);

        $this->assertCount(3, $visible['nodes'], 'The root and both proposals are visible.');
        $this->assertCount(1, $counted['nodes'], 'Only the root is counted until somebody confirms.');

        // And the lines we could not read are counted, so a short proposal
        // list is not mistaken for a short supply chain.
        $this->assertSame(2, $result['unmatched_lines']);
    }

    #[Test]
    public function discovery_does_not_propose_the_vendor_as_its_own_sub_processor(): void
    {
        $aws = $this->makeVendor('Amazon Web Services');

        $result = app(SubprocessorDiscoverer::class)->discover(
            $aws,
            "Amazon Web Services sub-processor list\nRack Centre Limited — colocation\n",
        );

        $this->assertSame(['Rack Centre'], $result['matched']);
    }

    #[Test]
    public function confirming_a_soc2_turns_its_carve_outs_into_proposed_edges(): void
    {
        $document = Document::create([
            'organization_id' => $this->organization->id,
            'owner_type' => Document::OWNER_THIRD_PARTY,
            'owner_id' => $this->vendor->id,
            'title' => 'SOC 2 Type II — Cloudspan',
            'file_path' => 'tprm/evidence/soc2.pdf',
            'mime' => 'application/pdf',
            'size' => 4096,
            'hash' => hash('sha256', Str::random(32)),
            'uploaded_by' => $this->manager->id,
        ]);

        app(\App\Services\Tprm\Extraction\ExtractionConfirmer::class)->manual($document, 'soc2', [
            'report_type' => \App\Models\Tprm\Soc2Detail::TYPE_II,
            'period_start' => now()->subYear()->toDateString(),
            'period_end' => now()->subMonth()->toDateString(),
            'service_auditor' => 'Grant Thornton LLP',
            'tsc_categories' => ['security'],
            'opinion_type' => 'unqualified',
            'subservice_orgs' => [
                ['name' => 'Amazon Web Services', 'services' => 'Hosting', 'method' => 'carve_out'],
                // Inclusive: the auditor examined it, so there is no gap and
                // no edge to record.
                ['name' => 'Rack Centre Limited', 'services' => 'Colocation', 'method' => 'inclusive'],
            ],
        ], $this->manager->id);

        $edges = NthPartyEdge::query()->where('parent_third_party_id', $this->vendor->id)->get();

        $this->assertCount(1, $edges);
        $this->assertSame('Amazon Web Services', $edges->first()->child_name_raw);
        $this->assertSame(DisclosureSource::Soc2Carveout, $edges->first()->disclosure_source);
        $this->assertSame(NthPartyEdge::STATUS_PROPOSED, $edges->first()->confirmation_status);
    }

    #[Test]
    public function an_entity_in_evidence_but_absent_from_the_declaration_is_a_finding(): void
    {
        $service = app(NthPartyService::class);

        // Read out of a SOC 2 carve-out — evidence, not a declaration.
        $service->record(
            $this->vendor,
            'Amazon Web Services',
            null,
            DisclosureSource::Soc2Carveout,
        );

        // And one the vendor did declare, which must NOT produce a finding.
        $service->record(
            $this->vendor,
            'Rack Centre Limited',
            null,
            DisclosureSource::VendorDeclared,
        );

        $findings = $service->raiseUndeclaredFindings($this->vendor, $this->manager->id);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('Amazon Web Services', $findings->first()->title);
        $this->assertStringContainsString('NDPA', (string) $findings->first()->regulatory_citation);
    }

    #[Test]
    public function discovery_from_a_document_with_no_readable_text_says_so(): void
    {
        $document = Document::create([
            'organization_id' => $this->organization->id,
            'owner_type' => Document::OWNER_THIRD_PARTY,
            'owner_id' => $this->vendor->id,
            'title' => 'DPA annex',
            'file_path' => 'tprm/evidence/missing.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'hash' => hash('sha256', Str::random(32)),
            'uploaded_by' => $this->manager->id,
        ]);

        $result = app(SubprocessorDiscoverer::class)->discoverFromDocument($document, $this->manager->id);

        // "Nothing was scanned" and "no sub-processors" have to look
        // different, because the product ships without a bundled PDF reader
        // and the second reads as a clean supply chain.
        $this->assertNotNull($result['unavailable']);
        $this->assertStringContainsString('not a finding', $result['unavailable']);
        $this->assertSame([], $result['proposed']);
    }

    /* ------------------------------------------------------------------ */
    /*  AC-10 — access */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function terminating_with_a_live_grant_is_blocked_and_the_message_names_the_grant(): void
    {
        $this->liveGrant('Yusuf Bello', 'Core Banking', AccessLevel::Admin);

        $this->walkToTransitioning();

        try {
            app(IntakeService::class)->transition($this->engagement, EngagementStatus::Terminated, $this->manager->id);
            $this->fail('Termination should have been blocked by the live access grant.');
        } catch (OpenAccessException $exception) {
            $this->assertStringContainsString('Yusuf Bello', $exception->getMessage());
            $this->assertStringContainsString('Core Banking', $exception->getMessage());

            // Phase 9 reports both gates together, so the offboarding items
            // appear alongside the access blocker. The access one leads,
            // because it is the one with a live credential behind it.
            $this->assertSame('access_grant', $exception->details()[0]['kind']);
        }

        $this->assertSame(EngagementStatus::Transitioning, $this->engagement->fresh()->status);
    }

    #[Test]
    public function terminating_with_an_open_connection_is_blocked_too(): void
    {
        $this->openConnection('Core banking VPN', ConnectionType::Vpn);

        $verdict = app(TerminationGuard::class)->check($this->engagement);

        $this->assertFalse($verdict->allowed);
        $this->assertSame('connection', $verdict->blockers[0]['kind']);
        $this->assertStringContainsString('Core banking VPN', (string) $verdict->reason);
        // The action tells somebody what evidence to bring.
        $this->assertStringContainsString('concentrator', $verdict->blockers[0]['action']);
    }

    #[Test]
    public function revoking_with_evidence_clears_the_block(): void
    {
        $grant = $this->liveGrant('Yusuf Bello', 'Core Banking', AccessLevel::Admin);

        $this->assertFalse(app(TerminationGuard::class)->check($this->engagement)->allowed);

        app(AccessService::class)->revokeGrant($grant, $this->evidenceDocument()->id, $this->manager->id);

        $this->assertTrue(app(TerminationGuard::class)->check($this->engagement)->allowed);

        /*
         * Phase 9 added a SECOND gate. Revoking clears the ACCESS block, which
         * is what this test is about; the offboarding checklist (FR-EXT-03)
         * then has to be settled too, and it is generated when the engagement
         * enters transition. Settling it here is fixture work, not the subject
         * — `OffboardingTest` is where the second gate is actually tested.
         */
        $this->walkToTransitioning();
        $this->settleOffboarding();

        $this->assertTrue(
            app(IntakeService::class)->transition($this->engagement, EngagementStatus::Terminated, $this->manager->id),
        );
        $this->assertSame(EngagementStatus::Terminated, $this->engagement->fresh()->status);
    }

    #[Test]
    public function a_revocation_records_the_evidence_and_who_did_it(): void
    {
        $grant = $this->liveGrant('Yusuf Bello', 'Core Banking', AccessLevel::Privileged);
        $document = $this->evidenceDocument();

        app(AccessService::class)->revokeGrant($grant, $document->id, $this->manager->id);

        $grant->refresh();

        $this->assertSame(AccessGrantStatus::Revoked, $grant->status);
        $this->assertSame($document->id, $grant->revocation_evidence_document_id);
        $this->assertSame($this->manager->id, $grant->revoked_by);
        $this->assertNotNull($grant->revoked_at);
    }

    #[Test]
    public function closing_a_live_connection_without_evidence_is_refused(): void
    {
        $connection = $this->openConnection('Settlement SFTP', ConnectionType::Sftp);

        try {
            app(AccessService::class)->close($connection, null, null, $this->manager->id);
            $this->fail('A live connection should not close without evidence.');
        } catch (InvalidArgumentException $exception) {
            // The message says what evidence, not merely that some is needed.
            $this->assertStringContainsString('authorized_keys', $exception->getMessage());
        }

        $this->assertSame(ConnectionStatus::Active, $connection->fresh()->status);
    }

    #[Test]
    public function a_connection_that_was_never_activated_closes_on_a_reason(): void
    {
        $connection = app(AccessService::class)->recordConnection($this->engagement, [
            'type' => ConnectionType::Api->value,
            'name' => 'Withdrawn integration',
            'direction' => 'outbound',
        ], $this->manager->id);

        // Nothing was ever opened, so demanding a document would teach people
        // to attach an empty file.
        app(AccessService::class)->close($connection, null, 'The project was cancelled before build.', $this->manager->id);

        $this->assertSame(ConnectionStatus::Closed, $connection->fresh()->status);
        $this->assertNull($connection->fresh()->closure_evidence_document_id);
    }

    #[Test]
    public function an_approved_exception_releases_one_grant_and_says_so(): void
    {
        $grant = $this->liveGrant('Yusuf Bello', 'Core Banking', AccessLevel::Read);

        Waiver::create([
            'organization_id' => $this->organization->id,
            'waivable_type' => Waiver::TYPE_ACCESS_EXCEPTION,
            'waivable_id' => $grant->id,
            'engagement_id' => $this->engagement->id,
            'rationale' => 'Read-only reporting login retained for the 90-day records handover.',
            'requested_by' => $this->manager->id,
            'requested_at' => now(),
            'approver_id' => $this->manager->id,
            'approved_at' => now(),
            'expires_at' => now()->addDays(90)->toDateString(),
            'status' => Waiver::STATUS_APPROVED,
        ]);

        $verdict = app(TerminationGuard::class)->check($this->engagement);

        $this->assertTrue($verdict->allowed);
        // Released, not invisible: the offboarding pack shows what was accepted.
        $this->assertCount(1, $verdict->excepted);
        $this->assertSame($grant->id, $verdict->excepted[0]['id']);
    }

    #[Test]
    public function a_connection_exception_does_not_release_a_grant_with_the_same_id(): void
    {
        $grant = $this->liveGrant('Yusuf Bello', 'Core Banking', AccessLevel::Admin);

        // The waiver names a CONNECTION with this id. Before the waiver type
        // was split, the guard probed both tables and would have released the
        // grant instead.
        Waiver::create([
            'organization_id' => $this->organization->id,
            'waivable_type' => Waiver::TYPE_CONNECTION_EXCEPTION,
            'waivable_id' => $grant->id,
            'engagement_id' => $this->engagement->id,
            'rationale' => 'Circuit disconnection scheduled with the carrier for next month.',
            'requested_by' => $this->manager->id,
            'requested_at' => now(),
            'approver_id' => $this->manager->id,
            'approved_at' => now(),
            'status' => Waiver::STATUS_APPROVED,
        ]);

        $verdict = app(TerminationGuard::class)->check($this->engagement);

        $this->assertFalse($verdict->allowed);
        $this->assertSame('access_grant', $verdict->blockers[0]['kind']);
    }

    /* ------------------------------------------------------------------ */
    /*  FR-ACC-03 and FR-ACC-05 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_expired_but_unrevoked_grant_becomes_a_critical_finding(): void
    {
        $grant = $this->liveGrant('Amina Sule', 'Payment Gateway', AccessLevel::Write);
        $grant->forceFill(['valid_to' => now()->subDays(10)->toDateString()])->save();

        $result = app(AccessService::class)->expireDueGrants(null, $this->manager->id);

        $this->assertSame(1, $result['expired']);
        $this->assertSame(1, $result['findings']);

        $this->assertSame(AccessGrantStatus::Expired, $grant->fresh()->status);

        $finding = Finding::query()->where('engagement_id', $this->engagement->id)->latest('id')->firstOrFail();

        $this->assertSame('critical', $finding->severity->value);
        $this->assertStringContainsString('Amina Sule', $finding->title);
        $this->assertStringContainsString('Appendix III', (string) $finding->regulatory_citation);
    }

    #[Test]
    public function an_expired_grant_still_blocks_termination(): void
    {
        $grant = $this->liveGrant('Amina Sule', 'Payment Gateway', AccessLevel::Read);
        $grant->forceFill([
            'valid_to' => now()->subDays(10)->toDateString(),
            'status' => AccessGrantStatus::Expired->value,
        ])->save();

        // Expiry is a date passing; revocation is somebody doing something.
        // Only the second is evidence the credential stopped working.
        $this->assertFalse(app(TerminationGuard::class)->check($this->engagement)->allowed);
    }

    #[Test]
    public function the_sweep_does_not_raise_a_second_finding_for_the_same_grant(): void
    {
        $grant = $this->liveGrant('Amina Sule', 'Payment Gateway', AccessLevel::Write);
        $grant->forceFill(['valid_to' => now()->subDays(10)->toDateString()])->save();

        app(AccessService::class)->expireDueGrants(null, $this->manager->id);
        $second = app(AccessService::class)->expireDueGrants(null, $this->manager->id);

        $this->assertSame(0, $second['findings']);
        $this->assertSame(1, Finding::query()->where('source', 'monitoring')->count());
    }

    #[Test]
    public function the_reconciliation_report_separates_its_four_populations(): void
    {
        // Ended relationship, access still live.
        $ended = $this->makeEngagement($this->makeVendor('Dormant Data Limited'), 'Archive retrieval');
        $ended->forceFill(['status' => EngagementStatus::Terminated->value])->save();
        $this->liveGrant('Kunle Adeyemi', 'Archive', AccessLevel::Read, $ended);

        // Overdue.
        $overdue = $this->liveGrant('Amina Sule', 'Payment Gateway', AccessLevel::Write);
        $overdue->forceFill(['valid_to' => now()->subDays(5)->toDateString()])->save();

        // No end date at all.
        $openEnded = $this->liveGrant('Chidi Okafor', 'Reporting', AccessLevel::Read);
        $openEnded->forceFill(['valid_to' => null])->save();

        $report = app(AccessService::class)->reconciliation();

        $this->assertCount(1, $report['discontinued']);
        $this->assertSame('Kunle Adeyemi', $report['discontinued'][0]['grantee_name']);

        $this->assertCount(1, $report['overdue']);
        $this->assertSame('Amina Sule', $report['overdue'][0]['grantee_name']);

        $this->assertCount(1, $report['open_ended']);
        $this->assertSame('Chidi Okafor', $report['open_ended'][0]['grantee_name']);
    }

    #[Test]
    public function the_report_lists_privileged_access_first(): void
    {
        $read = $this->liveGrant('Chidi Okafor', 'Reporting', AccessLevel::Read);
        $read->forceFill(['valid_to' => now()->subDays(5)->toDateString()])->save();

        $admin = $this->liveGrant('Yusuf Bello', 'Core Banking', AccessLevel::Admin);
        $admin->forceFill(['valid_to' => now()->subDays(5)->toDateString()])->save();

        $report = app(AccessService::class)->reconciliation();
        $sorted = app(AccessService::class)->sortByExposure($report['overdue']);

        $this->assertSame('Yusuf Bello', $sorted[0]['grantee_name']);
    }

    /* ------------------------------------------------------------------ */
    /*  Screens */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_concentration_screen_renders(): void
    {
        $this->concentrateFiveCriticalFunctionsOn($this->vendor);
        app(ConcentrationService::class)->run($this->organization->id, 'provider_group');

        $this->actingAs($this->manager)
            ->get(route('tprm.concentration.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tprm/Concentration/Index')
                ->where('analysis.hhi', 10000)
                ->where('analysis.band', 'concentrated'));
    }

    #[Test]
    public function the_reconciliation_screen_renders(): void
    {
        $grant = $this->liveGrant('Amina Sule', 'Payment Gateway', AccessLevel::Write);
        $grant->forceFill(['valid_to' => now()->subDays(5)->toDateString()])->save();

        $this->actingAs($this->manager)
            ->get(route('tprm.access.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tprm/Access/Index')
                ->has('reconciliation.overdue', 1));
    }

    #[Test]
    public function the_engagement_access_screen_carries_the_termination_verdict(): void
    {
        $this->liveGrant('Yusuf Bello', 'Core Banking', AccessLevel::Admin);

        $this->actingAs($this->manager)
            ->get(route('tprm.access.show', $this->engagement->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tprm/Access/Engagement')
                ->where('terminationVerdict.allowed', false)
                ->has('terminationVerdict.blockers', 1));
    }

    #[Test]
    public function the_chain_drill_down_resolves_and_returns_the_table(): void
    {
        app(NthPartyService::class)->record(
            $this->vendor,
            'Rack Centre Limited',
            null,
            DisclosureSource::VendorDeclared,
        );

        $this->actingAs($this->manager)
            ->getJson(route('tprm.concentration.chain', $this->vendor))
            ->assertOk()
            ->assertJsonStructure(['chain', 'undeclared']);
    }

    #[Test]
    public function another_tenants_document_cannot_be_used_as_revocation_evidence(): void
    {
        $grant = $this->liveGrant('Yusuf Bello', 'Core Banking', AccessLevel::Admin);

        $foreign = Organization::create([
            'name' => 'Abuja Trust Bank', 'short_name' => 'ATB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        $foreignDocument = TenantContext::actingAs($foreign->id, fn () => Document::create([
            'organization_id' => $foreign->id,
            'owner_type' => Document::OWNER_THIRD_PARTY,
            'owner_id' => $this->vendor->id,
            'title' => 'Another bank\'s access review',
            'file_path' => 'tprm/evidence/foreign.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'hash' => hash('sha256', Str::random(32)),
            'uploaded_by' => $this->manager->id,
        ]));

        $this->actingAs($this->manager)
            ->post(route('tprm.access.grants.revoke', $grant->id), [
                'revocation_evidence_document_id' => $foreignDocument->id,
            ])
            ->assertSessionHasErrors('revocation_evidence_document_id');

        // And the grant is untouched: a revocation evidenced by a document
        // this tenant cannot see is not a revocation.
        $this->assertTrue($grant->fresh()->isLive());
    }

    #[Test]
    public function the_screens_refuse_a_user_without_the_permission(): void
    {
        $outsider = $this->userWith(['tprm.view'], 'outsider@lub.test', 'tprm-outsider');

        $this->actingAs($outsider)->get(route('tprm.concentration.index'))->assertForbidden();
        $this->actingAs($outsider)->get(route('tprm.access.index'))->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /*  Fixtures */
    /* ------------------------------------------------------------------ */

    /**
     * Five critical business functions, all depending on one provider.
     */
    private function concentrateFiveCriticalFunctionsOn(ThirdParty $vendor): void
    {
        $this->engagement->forceFill([
            'substitutability' => 'none',
            'time_to_replace_months' => 18,
            'annual_spend_minor' => 480_000_000,
        ])->save();

        $functions = collect(range(1, 5))->map(fn (int $index) => BusinessFunction::create([
            'organization_id' => $this->organization->id,
            'function_code' => 'BF-'.$index,
            'name' => 'Critical function '.$index,
            'criticality' => 'critical',
            'is_active' => true,
        ]));

        $this->attachFunctions($this->engagement, $functions);
        $this->engagement->refresh();
    }

    /**
     * The pivot carries `organization_id` and it is NOT NULL, so a bare
     * `sync()` of ids fails. `IntakeService` passes it explicitly; so does
     * this.
     *
     * @param  \Illuminate\Support\Collection<int, BusinessFunction>  $functions
     */
    private function attachFunctions(Engagement $engagement, $functions): void
    {
        foreach ($functions as $function) {
            $engagement->businessFunctions()->attach($function->id, [
                'organization_id' => $engagement->organization_id,
                'dependency_level' => 'primary',
                'reliance_level' => 'high',
            ]);
        }
    }

    /**
     * Settle the offboarding checklist so a Phase 7 test can reach
     * `terminated` without also being a Phase 9 test.
     */
    private function settleOffboarding(): void
    {
        $checklist = \App\Models\Tprm\OffboardingChecklist::query()
            ->where('engagement_id', $this->engagement->getKey())
            ->first();

        if ($checklist === null) {
            return;
        }

        $service = app(\App\Services\Tprm\Exit\OffboardingService::class);

        foreach ($checklist->items as $item) {
            if ($item->isReconciled() || $item->isSettled()) {
                continue;
            }

            $service->complete($item, null, $this->manager->id);
        }
    }

    /**
     * Active does not go straight to terminated: the lifecycle runs through
     * exit planning and transition first, which is the sequence the module
     * exists to insist on. AC-10 is about the LAST step of that walk.
     */
    private function walkToTransitioning(): void
    {
        $intake = app(IntakeService::class);

        $intake->transition($this->engagement, EngagementStatus::ExitPlanning, $this->manager->id);
        $intake->transition($this->engagement->refresh(), EngagementStatus::Transitioning, $this->manager->id);

        $this->engagement->refresh();
    }

    private function makeVendor(string $name): ThirdParty
    {
        return ThirdParty::create([
            'legal_name' => $name,
            'slug' => Str::random(12),
            'entity_type' => 'company',
            'status' => 'active',
        ]);
    }

    private function makeEngagement(ThirdParty $vendor, string $name, int $criticalFunctions = 0): Engagement
    {
        $this->engagementSequence++;

        $engagement = Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => sprintf('ENG-2026-%04d', $this->engagementSequence),
            'name' => $name,
            'service_description' => 'Recorded for the Phase 7 acceptance fixtures.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->manager->id,
        ]);

        $engagement->forceFill([
            'status' => EngagementStatus::Active->value,
            'inherent_score' => 70,
            'inherent_tier' => RiskTier::High->value,
            'effective_tier' => RiskTier::High->value,
        ])->save();

        InherentAssessment::create([
            'organization_id' => $this->organization->id,
            'engagement_id' => $engagement->id,
            'version' => 1, 'ruleset_version' => '1.0.0',
            'raw_score' => 70, 'resulting_tier' => RiskTier::High->value,
            'assessed_at' => now(), 'is_current' => true,
            'answers' => [], 'factor_scores' => [], 'weights' => [], 'explanation' => [],
        ]);

        if ($criticalFunctions > 0) {
            $functions = collect(range(1, $criticalFunctions))->map(fn (int $index) => BusinessFunction::create([
                'organization_id' => $this->organization->id,
                'function_code' => Str::random(8),
                'name' => $name.' function '.$index,
                'criticality' => 'critical',
                'is_active' => true,
            ]));

            $this->attachFunctions($engagement, $functions);
        }

        return $engagement->refresh();
    }

    private function liveGrant(
        string $name,
        string $system,
        AccessLevel $level,
        ?Engagement $engagement = null,
    ): AccessGrant {
        $engagement ??= $this->engagement;

        $grant = app(AccessService::class)->grantAccess($engagement, [
            'grantee_name' => $name,
            'grantee_email' => Str::slug($name).'@vendor.test',
            'system_name' => $system,
            'access_level' => $level->value,
            'justification' => 'Support of the hosted platform under the master services agreement.',
            'valid_from' => now()->subMonth()->toDateString(),
            'valid_to' => now()->addMonths(3)->toDateString(),
            'monitoring_method' => 'Session recording, reviewed weekly.',
        ], $this->manager->id);

        return app(AccessService::class)->approveGrant($grant, $this->manager->id);
    }

    private function openConnection(string $name, ConnectionType $type): Connection
    {
        $connection = app(AccessService::class)->recordConnection($this->engagement, [
            'type' => $type->value,
            'name' => $name,
            'endpoint' => '10.20.30.40',
            'direction' => 'bidirectional',
            'encryption' => 'IPsec, AES-256',
            'authentication_method' => 'Certificate',
        ], $this->manager->id);

        app(AccessService::class)->approveConnection($connection, $this->manager->id);

        return app(AccessService::class)->activateConnection($connection->refresh());
    }

    private function evidenceDocument(): Document
    {
        return Document::create([
            'organization_id' => $this->organization->id,
            'owner_type' => Document::OWNER_ENGAGEMENT,
            'owner_id' => $this->engagement->id,
            'title' => 'Access review extract — accounts disabled',
            'file_path' => 'tprm/evidence/access-review.pdf',
            'mime' => 'application/pdf',
            'size' => 2048,
            'hash' => hash('sha256', Str::random(32)),
            'uploaded_by' => $this->manager->id,
        ]);
    }

    private function userWith(array $permissions, string $email, string $roleName): User
    {
        $user = User::create([
            'name' => Str::of($roleName)->afterLast('-')->title()->toString(),
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
}

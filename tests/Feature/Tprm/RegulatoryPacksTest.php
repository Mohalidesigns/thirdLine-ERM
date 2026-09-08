<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\DocumentExtractor;
use App\Enums\Tprm\EngagementStatus;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Contract;
use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentExtraction;
use App\Models\Tprm\DocumentType;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Incident;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Extraction\Extractors\DpaExtractor;
use App\Services\Tprm\Reporting\NdpaCarPackBuilder;
use App\Services\Tprm\Reporting\PciPackBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * FR-RPT-03 and FR-RPT-04 — the NDPA Compliance Audit Return evidence pack and
 * the PCI DSS 12.8 pack.
 *
 * BOTH PACKS ARE JUDGED ON WHAT THEY REFUSE TO CLAIM. A processor with no DPA
 * on file must appear with twenty "Not assessed" elements rather than twenty
 * "absent" ones, because nobody has read a document — and a service provider
 * whose Attestation of Compliance is fourteen months old must fail the
 * twelve-month test rather than appear as evidence held. Each of those, got
 * wrong, produces a pack that reports a clean programme and hides the work.
 */
class RegulatoryPacksTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->bank = Organization::create([
            'name' => 'Ibadan Federal Bank', 'short_name' => 'IFB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->bank->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->bank->id);

        $this->user = User::create([
            'name' => 'Data Protection Officer', 'email' => 'dpo@ifb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-packs', 'web');
        foreach (['tprm.view', 'tprm.report.view', 'tprm.report.export'] as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->user->assignRole($role);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ================================================================== */
    /*  NDPA CAR pack */
    /* ================================================================== */

    #[Test]
    public function the_filing_countdown_does_not_flip_to_next_year_on_the_deadline_itself(): void
    {
        $builder = app(NdpaCarPackBuilder::class);

        // The one day it must not be reassuring: somebody who has not filed
        // and is about to be late must not be told they have 364 days.
        $onTheDay = $builder->filingCountdown(CarbonImmutable::create(2027, 3, 31, 9));

        $this->assertSame('2027-03-31', $onTheDay['deadline']);
        $this->assertSame(0, $onTheDay['days_remaining']);
        $this->assertTrue($onTheDay['overdue_today']);
        $this->assertSame(2026, $onTheDay['filing_year']);

        $dayAfter = $builder->filingCountdown(CarbonImmutable::create(2027, 4, 1, 9));

        $this->assertSame('2028-03-31', $dayAfter['deadline']);
        $this->assertSame(2027, $dayAfter['filing_year']);
    }

    #[Test]
    public function a_processor_with_no_dpa_reading_shows_not_assessed_rather_than_absent(): void
    {
        $this->processor('ENG-NODPA', 'Payments processor');

        $section = $this->ndpaSection('CAR-2');

        $row = $section['rows'][0];

        $this->assertContains('No confirmed DPA reading', $row);
        // Twenty elements, none of them called absent: nobody has read a
        // document, and that is a gap in the evidence file rather than a gap
        // in the agreement.
        $this->assertSame(20, count(array_filter($row, fn ($v) => $v === 'Not assessed')));
        $this->assertSame('partial', $section['coverage']);
    }

    #[Test]
    public function a_confirmed_dpa_reading_reports_each_of_the_twenty_elements(): void
    {
        $engagement = $this->processor('ENG-DPA', 'Cloud host');

        $this->confirmedDpaReading($engagement, ['a' => 'present', 'b' => 'partial']);

        $section = $this->ndpaSection('CAR-2');
        $row = $section['rows'][0];

        $this->assertSame('Yes', $row[2]);
        $this->assertSame(1, $row[3]);   // present
        $this->assertSame(1, $row[4]);   // partial
        $this->assertSame(18, $row[5]);  // absent
        $this->assertSame('Present', $row[6]);
        $this->assertSame('Partial', $row[7]);
        $this->assertSame('Absent', $row[8]);
    }

    #[Test]
    public function an_unconfirmed_dpa_reading_is_not_reported_as_the_institutions_position(): void
    {
        $engagement = $this->processor('ENG-PENDING', 'Cloud host');

        $this->confirmedDpaReading($engagement, ['a' => 'present'], confirmed: false);

        // A machine reading nobody has checked is a proposal about a legal
        // document, not a statement in a regulatory return.
        $this->assertContains('No confirmed DPA reading', $this->ndpaSection('CAR-2')['rows'][0]);
    }

    #[Test]
    public function a_cross_border_transfer_with_no_basis_is_reported_as_the_finding(): void
    {
        $this->processor('ENG-XB', 'Offshore analytics', [
            'cross_border' => true,
            'transfer_basis' => 'none',
            'data_location_at_rest' => 'IE',
        ]);

        $section = $this->ndpaSection('CAR-3');

        $this->assertSame('partial', $section['coverage']);
        $this->assertStringContainsString('no recorded lawful basis', (string) $section['note']);
        $this->assertContains('NONE RECORDED — the absence of a basis is the finding', $section['rows'][0]);
    }

    #[Test]
    public function financial_services_fires_the_dpia_trigger_for_every_processor(): void
    {
        $this->processor('ENG-DPIA', 'Payroll bureau');

        $row = $this->ndpaSection('CAR-4')['rows'][0];

        // GAID Art. 28(3) names financial services expressly. For a bank that
        // fires on every processor, which is the rule and not a defect in the
        // derivation.
        $this->assertStringContainsString('Financial services', $row[4]);
        // And the four triggers this product does not capture are named as
        // not recorded rather than treated as absent.
        $this->assertStringContainsString('Profiling', $row[5]);
        $this->assertStringContainsString('Systematic monitoring', $row[5]);
    }

    #[Test]
    public function a_breach_notified_after_its_deadline_is_reported_as_late(): void
    {
        $vendor = ThirdParty::create([
            'organization_id' => $this->bank->id, 'legal_name' => 'Breached Vendor',
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]);

        Incident::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $vendor->id,
            'reference' => 'INC-LATE',
            'type' => 'data_breach',
            'title' => 'Customer records exposed',
        ])->forceFill([
            'personal_data_involved' => true,
            'reported_to_us_at' => now()->subDays(10),
            'ndpc_reportable' => true,
            'ndpc_deadline_at' => now()->subDays(7),
            'ndpc_reported_at' => now()->subDays(2),
        ])->save();

        $section = $this->ndpaSection('CAR-5');

        $this->assertContains('Notified late', $section['rows'][0]);
        $this->assertStringContainsString('notified late or not at all', (string) $section['note']);
    }

    #[Test]
    public function an_undetermined_clock_reports_why_it_has_no_deadline(): void
    {
        $vendor = ThirdParty::create([
            'organization_id' => $this->bank->id, 'legal_name' => 'Unclear Vendor',
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]);

        Incident::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $vendor->id,
            'reference' => 'INC-UNDET',
            'type' => 'data_breach',
            'title' => 'Scope not yet established',
        ])->forceFill([
            'personal_data_involved' => true,
            'reported_to_us_at' => now()->subDay(),
            'ndpc_reportable' => true,
        ])->save();

        // Phase 9's rule: an undetermined assessment sets no deadline, and a
        // countdown from a guess gets planned around.
        $this->assertContains(
            'No deadline set — assessment undetermined',
            $this->ndpaSection('CAR-5')['rows'][0]
        );
    }

    #[Test]
    public function the_ndpa_pack_exports_a_workbook_a_pdf_and_one_section_of_csv(): void
    {
        $this->processor('ENG-EXP', 'Cloud host');

        $xlsx = $this->actingAs($this->user)
            ->get(route('tprm.reports.ndpa-car.export', ['format' => 'xlsx']))
            ->assertOk()->getContent();
        $this->assertStringStartsWith('PK', $xlsx);

        $pdf = $this->actingAs($this->user)
            ->get(route('tprm.reports.ndpa-car.export', ['format' => 'pdf']))
            ->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);

        $this->actingAs($this->user)
            ->get(route('tprm.reports.ndpa-car.export', ['format' => 'csv']))
            ->assertStatus(422);

        $csv = $this->actingAs($this->user)
            ->get(route('tprm.reports.ndpa-car.export', ['format' => 'csv', 'section' => 'CAR-3']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('NDPA §41(2)', $csv);
    }

    /* ================================================================== */
    /*  PCI 12.8 pack */
    /* ================================================================== */

    #[Test]
    public function an_attestation_older_than_twelve_months_fails_the_currency_test(): void
    {
        $stale = $this->tpsp('ENG-STALE', 'Card processor');
        $current = $this->tpsp('ENG-CURRENT', 'Token vault');

        $this->attestation($stale, now()->subMonths(14));
        $this->attestation($current, now()->subMonths(3));

        $section = $this->pciSection('12.8.4');

        $rows = collect($section['rows'])->keyBy(1);

        $this->assertStringContainsString('Fails — 14 months old', $rows['ENG-STALE'][6]);
        $this->assertSame('Passes', $rows['ENG-CURRENT'][6]);
        $this->assertSame('partial', $section['coverage']);
    }

    #[Test]
    public function an_undated_attestation_fails_the_currency_test_rather_than_passing_it(): void
    {
        $engagement = $this->tpsp('ENG-UNDATED', 'Card processor');

        $this->attestation($engagement, null);

        // A document whose age cannot be established cannot evidence annual
        // monitoring, and the comfortable reading is that it counts.
        $this->assertStringContainsString(
            'Fails — attestation is undated',
            $this->pciSection('12.8.4')['rows'][0][6]
        );
    }

    #[Test]
    public function a_provider_with_no_attestation_is_listed_and_counted(): void
    {
        $this->tpsp('ENG-NOAOC', 'Card processor');

        $section = $this->pciSection('12.8.4');

        $this->assertContains('None held', $section['rows'][0]);
        $this->assertSame(1, app(PciPackBuilder::class)->summary(
            Engagement::query()->where('pci_in_scope', true)->get()
        )['aoc_missing']);
    }

    #[Test]
    public function the_pci_summary_counts_what_nobody_scoped(): void
    {
        $this->tpsp('ENG-IN', 'Card processor');
        // Processes personal data, PCI decision recorded as out of scope.
        $this->processor('ENG-OUT', 'Payroll bureau');

        $summary = app(PciPackBuilder::class)->summary(
            Engagement::query()->where('pci_in_scope', true)->get()
        );

        $this->assertSame(1, $summary['tpsps']);
        // "We have one TPSP" is only true if somebody looked at the rest.
        $this->assertSame(1, $summary['unscoped_personal_data_engagements']);
    }

    #[Test]
    public function an_unanalysed_agreement_clause_is_not_reported_as_absent(): void
    {
        $this->tpsp('ENG-CLAUSE', 'Card processor');

        $section = $this->pciSection('12.8.2');

        // The absence of the check is not evidence about the term.
        $this->assertContains('Not analysed', $section['rows'][0]);
        $this->assertSame('partial', $section['coverage']);
    }

    #[Test]
    public function the_pci_pack_screen_leads_with_the_failures(): void
    {
        $this->tpsp('ENG-SCREEN', 'Card processor');

        $this->actingAs($this->user)
            ->get(route('tprm.reports.pci-pack'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tprm/Reports/PciPack')
                ->has('sections', 5)
                ->where('sections.0.code', '12.8.1')
                ->has('summary.agreements_missing')
                ->has('summary.aoc_missing')
                ->has('summary.unscoped_personal_data_engagements')
                ->where('can.export', true)
            );
    }

    #[Test]
    public function both_packs_are_readable_without_the_permission_to_export_them(): void
    {
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@ifb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-pack-viewer', 'web');
        $role->givePermissionTo(Permission::findOrCreate('tprm.report.view', 'web'));
        $viewer->assignRole($role);

        $this->actingAs($viewer)->get(route('tprm.reports.ndpa-car'))->assertOk();
        $this->actingAs($viewer)->get(route('tprm.reports.pci-pack'))->assertOk();

        $this->actingAs($viewer)
            ->get(route('tprm.reports.ndpa-car.export', ['format' => 'xlsx']))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get(route('tprm.reports.pci-pack.export', ['format' => 'xlsx']))
            ->assertForbidden();
    }

    /* ================================================================== */

    /**
     * @return array<string, mixed>
     */
    private function ndpaSection(string $code): array
    {
        $section = collect(app(NdpaCarPackBuilder::class)->sections())->firstWhere('code', $code);

        $this->assertNotNull($section, "No section {$code} in the CAR pack.");

        return $section;
    }

    /**
     * @return array<string, mixed>
     */
    private function pciSection(string $code): array
    {
        $section = collect(app(PciPackBuilder::class)->sections())->firstWhere('code', $code);

        $this->assertNotNull($section, "No section {$code} in the PCI pack.");

        return $section;
    }

    private function processor(string $reference, string $name, array $attributes = []): Engagement
    {
        return $this->engagement($reference, $name, ['processes_personal_data' => true] + $attributes);
    }

    private function tpsp(string $reference, string $name, array $attributes = []): Engagement
    {
        return $this->engagement($reference, $name, [
            'pci_in_scope' => true,
            'processes_personal_data' => true,
        ] + $attributes);
    }

    private function engagement(string $reference, string $name, array $attributes = []): Engagement
    {
        $vendor = ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => $name.' Limited',
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]);

        $engagement = Engagement::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $vendor->id,
            'reference' => $reference,
            'name' => $name,
            'engagement_type' => 'ict_service',
        ]);

        $engagement->forceFill(['status' => EngagementStatus::Active->value] + $attributes)->save();

        Contract::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->id,
            'contract_type' => 'msa',
            'reference' => 'CTR-'.$reference,
            'title' => $name.' agreement',
        ])->forceFill(['status' => 'executed'])->save();

        return $engagement->refresh();
    }

    /**
     * @param  array<string, string>  $verdicts
     */
    private function confirmedDpaReading(Engagement $engagement, array $verdicts, bool $confirmed = true): void
    {
        $document = $this->document($engagement, 'dpa', null);

        $elements = [];
        foreach (array_keys(DpaExtractor::ELEMENTS) as $letter) {
            $elements[$letter] = ['verdict' => $verdicts[$letter] ?? 'absent'];
        }

        DocumentExtraction::create([
            'organization_id' => $this->bank->id,
            'document_id' => $document->getKey(),
            'extractor' => DocumentExtractor::Dpa->value,
            'extracted' => ['elements' => $elements],
        ])->forceFill([
            'status' => $confirmed
                ? DocumentExtraction::STATUS_CONFIRMED
                : DocumentExtraction::STATUS_PENDING,
            'confirmed_at' => $confirmed ? now() : null,
        ])->save();
    }

    private function attestation(Engagement $engagement, ?\DateTimeInterface $issuedAt): Document
    {
        return $this->document($engagement, 'pci_aoc', $issuedAt);
    }

    private function document(Engagement $engagement, string $typeCode, ?\DateTimeInterface $issuedAt): Document
    {
        $type = DocumentType::withoutGlobalScope(\ThirdLine\Platform\Tenancy\OrganizationScope::class)
            ->where('code', $typeCode)
            ->first()
            ?? DocumentType::create([
                'organization_id' => $this->bank->id,
                'code' => $typeCode, 'name' => strtoupper($typeCode),
                'has_expiry' => true, 'is_assurance_evidence' => true, 'is_active' => true,
            ]);

        return Document::create([
            'organization_id' => $this->bank->id,
            'owner_type' => Document::OWNER_ENGAGEMENT,
            'owner_id' => $engagement->getKey(),
            'document_type_id' => $type->getKey(),
            'title' => strtoupper($typeCode).' for '.$engagement->reference,
            'file_path' => 'tprm/fixture.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'issue_date' => $issuedAt?->format('Y-m-d'),
        ]);
    }
}

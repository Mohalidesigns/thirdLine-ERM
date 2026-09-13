<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\AccessGrantStatus;
use App\Enums\Tprm\ConnectionStatus;
use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\RiskTier;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\AccessGrant;
use App\Models\Tprm\Connection;
use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentType;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Reporting\CbnRegisterBuilder;
use App\Services\Tprm\Reporting\RegisterFilters;
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
 * AC-13 — the register export.
 *
 * "The register export contains every active ICT third party and cloud service
 * provider with connection documentation status, is stamped with an as-at
 * timestamp, preparer and reviewer, and reconciles row-for-row to the filtered
 * UI view."
 *
 * THE RECONCILIATION IS THE TEST THAT MATTERS AND THE ONE THAT CANNOT BE DONE
 * BY EYE. An export a few rows wider than the screen looks complete, is what
 * gets filed, and nothing in the product would ever contradict it. So the
 * screen's props and the export's bytes are compared against each other under
 * the same filters, including a filtered view — an unfiltered comparison would
 * pass even if the export ignored filters entirely.
 */
class CbnRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $user;

    private BusinessUnit $treasury;

    private BusinessUnit $operations;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->bank = Organization::create([
            'name' => 'Abuja Mercantile Bank', 'short_name' => 'AMB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->bank->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->bank->id);

        $this->treasury = BusinessUnit::create([
            'organization_id' => $this->bank->id, 'name' => 'Treasury', 'code' => 'TRE', 'is_active' => true,
        ]);
        $this->operations = BusinessUnit::create([
            'organization_id' => $this->bank->id, 'name' => 'Operations', 'code' => 'OPS', 'is_active' => true,
        ]);

        $this->user = User::create([
            'name' => 'Chidinma Okafor', 'email' => 'chidinma@amb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-reporter', 'web');
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

    /* ------------------------------------------------------------------ */
    /*  AC-13 — the population */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_population_catches_cloud_bought_as_ordinary_supply(): void
    {
        $this->ictEngagement('ENG-ICT', 'Core banking hosting');

        // Not an ICT arrangement by type — and exactly the omission the
        // register exists to prevent.
        $cloud = $this->engagement('ENG-CLOUD', 'Document storage', [
            'engagement_type' => 'supply',
            'cloud_model' => 'saas',
        ]);

        $this->engagement('ENG-PAPER', 'Stationery', ['engagement_type' => 'supply']);

        $references = app(CbnRegisterBuilder::class)
            ->rows(RegisterFilters::none())
            ->pluck('reference')
            ->all();

        $this->assertContains('ENG-ICT', $references);
        $this->assertContains($cloud->reference, $references);
        $this->assertNotContains('ENG-PAPER', $references);
    }

    #[Test]
    public function terminated_arrangements_are_out_unless_asked_for(): void
    {
        $this->ictEngagement('ENG-LIVE', 'Payments switch');
        $dead = $this->ictEngagement('ENG-DEAD', 'Retired reporting tool');
        $dead->forceFill(['status' => EngagementStatus::Terminated->value])->save();

        $builder = app(CbnRegisterBuilder::class);

        $this->assertSame(['ENG-LIVE'], $builder->rows(RegisterFilters::none())->pluck('reference')->all());

        $withInactive = $builder->rows(RegisterFilters::fromRequest(
            request()->merge(['include_inactive' => 1])
        ))->pluck('reference')->all();

        $this->assertEqualsCanonicalizing(['ENG-LIVE', 'ENG-DEAD'], $withInactive);
    }

    /* ------------------------------------------------------------------ */
    /*  The derived statuses */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_approved_connection_missing_its_details_is_not_documented(): void
    {
        $engagement = $this->ictEngagement('ENG-CONN', 'Core banking hosting');

        // Approved, and nobody recorded what it connects to or how.
        Connection::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->id,
            'type' => 'api', 'name' => 'Settlement API',
        ])->forceFill([
            'status' => ConnectionStatus::Active->value,
            'approved_at' => now()->subMonth(),
        ])->save();

        $row = app(CbnRegisterBuilder::class)->rows(RegisterFilters::none())->firstWhere('reference', 'ENG-CONN');

        $this->assertSame('Undocumented', $row['connection_documentation']);
        $this->assertSame(1, $row['connections_undocumented']);
    }

    #[Test]
    public function no_connections_recorded_is_a_different_answer_from_documented(): void
    {
        $this->ictEngagement('ENG-NONE', 'Advisory');

        $row = app(CbnRegisterBuilder::class)->rows(RegisterFilters::none())->firstWhere('reference', 'ENG-NONE');

        // "Fully documented" over an empty set is the comfortable lie this
        // column exists to refuse.
        $this->assertSame('No connections recorded', $row['connection_documentation']);
    }

    #[Test]
    public function an_expired_grant_that_was_never_revoked_is_counted(): void
    {
        $engagement = $this->ictEngagement('ENG-ACC', 'Managed SOC');

        // `status` is guarded on the model — approval and revocation are
        // service actions with their own evidence guards — so the fixture
        // forces it rather than passing it to create() and silently getting
        // the default.
        AccessGrant::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->id,
            'grantee_name' => 'Vendor engineer', 'system_name' => 'SIEM',
            'access_level' => 'privileged',
            'valid_to' => now()->subMonths(2)->toDateString(),
        ])->forceFill(['status' => AccessGrantStatus::Expired->value])->save();

        AccessGrant::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->id,
            'grantee_name' => 'Former engineer', 'system_name' => 'SIEM',
            'access_level' => 'read',
            'valid_to' => now()->subMonths(3)->toDateString(),
        ])->forceFill([
            'status' => AccessGrantStatus::Revoked->value,
            'revoked_at' => now()->subMonths(3),
        ])->save();

        $row = app(CbnRegisterBuilder::class)->rows(RegisterFilters::none())->firstWhere('reference', 'ENG-ACC');

        $this->assertSame(1, $row['access_grants_live']);
        $this->assertSame(1, $row['access_grants_overdue']);
    }

    #[Test]
    public function evidence_held_and_lapsed_is_not_the_same_as_evidence_never_supplied(): void
    {
        $lapsed = $this->ictEngagement('ENG-LAPSED', 'Hosting');
        $this->ictEngagement('ENG-BARE', 'Consulting');

        $this->assuranceDocument($lapsed, now()->subMonth());

        $rows = app(CbnRegisterBuilder::class)->rows(RegisterFilters::none())->keyBy('reference');

        $this->assertSame('Expired', $rows['ENG-LAPSED']['evidence_currency']);
        $this->assertSame('None held', $rows['ENG-BARE']['evidence_currency']);
    }

    #[Test]
    public function evidence_held_against_the_provider_covers_its_engagements(): void
    {
        $engagement = $this->ictEngagement('ENG-SOC', 'Hosting');

        // A SOC 2 is issued about a company, not about a purchase order.
        $this->assuranceDocument($engagement, now()->addMonths(6), Document::OWNER_THIRD_PARTY);

        $row = app(CbnRegisterBuilder::class)->rows(RegisterFilters::none())->firstWhere('reference', 'ENG-SOC');

        $this->assertSame('Current', $row['evidence_currency']);
    }

    /* ------------------------------------------------------------------ */
    /*  AC-13 — reconciliation and the stamp */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_export_reconciles_row_for_row_to_the_filtered_view(): void
    {
        $this->ictEngagement('ENG-T1', 'Core banking', [
            'business_unit_id' => $this->treasury->id,
            'effective_tier' => RiskTier::Critical->value,
        ]);
        $this->ictEngagement('ENG-T2', 'Market data', [
            'business_unit_id' => $this->treasury->id,
            'effective_tier' => RiskTier::High->value,
        ]);
        $this->ictEngagement('ENG-O1', 'Print and mail', [
            'business_unit_id' => $this->operations->id,
            'effective_tier' => RiskTier::Moderate->value,
        ]);

        $query = ['business_unit' => $this->treasury->id];

        $screen = $this->actingAs($this->user)
            ->get(route('tprm.reports.cbn-register', $query))
            ->assertOk();

        $onScreen = collect(
            $screen->viewData('page')['props']['rows']
        )->pluck('reference')->all();

        // The filter did something — otherwise the comparison below would pass
        // over an export that ignored filters altogether.
        $this->assertSame(['ENG-T1', 'ENG-T2'], $onScreen);

        $csv = $this->actingAs($this->user)
            ->get(route('tprm.reports.cbn-register.export', $query + ['format' => 'csv']))
            ->assertOk()
            ->getContent();

        $exported = $this->csvReferences($csv);

        $this->assertSame($onScreen, $exported, 'The export must hold exactly the rows the screen shows, in order.');
    }

    #[Test]
    public function the_export_is_stamped_with_the_as_at_time_the_preparer_the_reviewer_and_the_filters(): void
    {
        $this->ictEngagement('ENG-STAMP', 'Hosting', ['business_unit_id' => $this->treasury->id]);

        $reviewer = User::create([
            'name' => 'Adaeze Bello', 'email' => 'adaeze@amb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $csv = $this->actingAs($this->user)
            ->get(route('tprm.reports.cbn-register.export', [
                'format' => 'csv',
                'business_unit' => $this->treasury->id,
                'reviewer_id' => $reviewer->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Position as at', $csv);
        $this->assertStringContainsString('Chidinma Okafor', $csv);
        $this->assertStringContainsString('Adaeze Bello', $csv);
        $this->assertStringContainsString('CBN Risk-Based Cybersecurity Framework', $csv);
        $this->assertStringContainsString('Treasury', $csv);
    }

    #[Test]
    public function an_unreviewed_export_says_so_rather_than_leaving_the_row_off(): void
    {
        $this->ictEngagement('ENG-UNREVIEWED', 'Hosting');

        $csv = $this->actingAs($this->user)
            ->get(route('tprm.reports.cbn-register.export', ['format' => 'csv']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Not reviewed', $csv);
    }

    #[Test]
    public function a_reviewer_from_another_tenant_cannot_be_named(): void
    {
        $this->ictEngagement('ENG-XT', 'Hosting');

        $other = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        $outsider = TenantContext::bypass(fn () => User::create([
            'name' => 'Outsider Reviewer', 'email' => 'outsider@ob.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $other->id, 'is_active' => true,
        ]), 'Seeding a second tenant for an isolation assertion.');

        $csv = $this->actingAs($this->user)
            ->get(route('tprm.reports.cbn-register.export', [
                'format' => 'csv',
                'reviewer_id' => $outsider->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Outsider Reviewer', $csv);
        $this->assertStringContainsString('Not reviewed', $csv);
    }

    #[Test]
    public function the_xlsx_and_pdf_both_produce_a_real_document(): void
    {
        $this->ictEngagement('ENG-DOC', 'Hosting');

        $xlsx = $this->actingAs($this->user)
            ->get(route('tprm.reports.cbn-register.export', ['format' => 'xlsx']))
            ->assertOk()
            ->getContent();

        // The zip magic number: an xlsx is a zip container, and a CSV
        // mislabelled as one would pass a header-only assertion.
        $this->assertStringStartsWith('PK', $xlsx);

        $pdf = $this->actingAs($this->user)
            ->get(route('tprm.reports.cbn-register.export', ['format' => 'pdf']))
            ->assertOk()
            ->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    #[Test]
    public function an_unsupported_format_is_refused_rather_than_silently_downgraded(): void
    {
        $this->ictEngagement('ENG-FMT', 'Hosting');

        $this->actingAs($this->user)
            ->get(route('tprm.reports.cbn-register.export', ['format' => 'pptx']))
            ->assertStatus(500);
    }

    /* ------------------------------------------------------------------ */
    /*  The gate */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function viewing_and_exporting_are_separate_permissions(): void
    {
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@amb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-viewer-only', 'web');
        $role->givePermissionTo(Permission::findOrCreate('tprm.report.view', 'web'));
        $viewer->assignRole($role);

        $this->actingAs($viewer)->get(route('tprm.reports.cbn-register'))->assertOk();
        $this->actingAs($viewer)
            ->get(route('tprm.reports.cbn-register.export', ['format' => 'csv']))
            ->assertForbidden();
    }

    #[Test]
    public function the_screen_hands_the_page_the_props_it_reads(): void
    {
        $this->ictEngagement('ENG-PROPS', 'Hosting');

        $this->actingAs($this->user)
            ->get(route('tprm.reports.cbn-register'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/Reports/CbnRegister')
                ->has('columns')
                ->has('rows', 1)
                ->has('summary.total')
                ->has('provenance.as_at_label')
                ->has('provenance.filters')
                ->where('can.export', true)
            );
    }

    /* ------------------------------------------------------------------ */

    /** @return list<string> */
    private function csvReferences(string $csv): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $csv)), fn ($line) => $line !== ''));

        // Everything before the header row is the provenance stamp.
        $headerIndex = null;
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, 'Provider,')) {
                $headerIndex = $index;
                break;
            }
        }

        $this->assertNotNull($headerIndex, 'The export has no header row.');

        $references = [];
        foreach (array_slice($lines, $headerIndex + 1) as $line) {
            $row = str_getcsv($line);
            // Column 6 is the engagement reference; see CbnRegisterBuilder::columns().
            $references[] = $row[5];
        }

        return $references;
    }

    private function ictEngagement(string $reference, string $name, array $attributes = []): Engagement
    {
        return $this->engagement($reference, $name, $attributes + ['engagement_type' => 'ict_service']);
    }

    private function engagement(string $reference, string $name, array $attributes = []): Engagement
    {
        $vendor = ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => $name.' provider',
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]);

        $engagement = Engagement::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $vendor->id,
            'reference' => $reference,
            'name' => $name,
            'engagement_type' => $attributes['engagement_type'] ?? 'ict_service',
        ]);

        $engagement->forceFill([
            'status' => EngagementStatus::Active->value,
        ] + collect($attributes)->except('engagement_type')->all())->save();

        return $engagement->refresh();
    }

    private function assuranceDocument(Engagement $engagement, \DateTimeInterface $validTo, string $ownerType = Document::OWNER_ENGAGEMENT): Document
    {
        $type = DocumentType::query()->where('is_assurance_evidence', true)->first()
            ?? DocumentType::create([
                'organization_id' => $this->bank->id,
                'code' => 'soc2t2', 'name' => 'SOC 2 Type II',
                'has_expiry' => true, 'is_assurance_evidence' => true, 'is_active' => true,
            ]);

        return Document::create([
            'organization_id' => $this->bank->id,
            'owner_type' => $ownerType,
            'owner_id' => $ownerType === Document::OWNER_ENGAGEMENT
                ? $engagement->getKey()
                : $engagement->third_party_id,
            'document_type_id' => $type->getKey(),
            'title' => 'SOC 2 Type II',
            'file_path' => 'tprm/fixture.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'valid_from' => now()->subYear()->toDateString(),
            'valid_to' => $validTo->format('Y-m-d'),
            'uploaded_by' => $this->user->id,
        ]);
    }
}

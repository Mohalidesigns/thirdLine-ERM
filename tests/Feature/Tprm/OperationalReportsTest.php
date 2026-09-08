<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\AccessGrantStatus;
use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\ObligationStatus;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\AccessGrant;
use App\Models\Tprm\Contract;
use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentType;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Obligation;
use App\Models\Tprm\ScreeningCheck;
use App\Models\Tprm\ScreeningMatch;
use App\Models\Tprm\Sla;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Reporting\Operational\OperationalReportRegistry;
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
 * FR-RPT-07 — the eight standard operational reports.
 *
 * THE PERMISSION MODEL IS THE FIRST THING TESTED. This module gates screening
 * decisions, access grants and contract terms separately, and a reports hub
 * that showed everything to anybody holding `tprm.report.view` would be the
 * easiest way around all three. Both halves are asserted: the hub filters, and
 * a guessed URL is refused.
 *
 * The rest of the assertions are about rows that exist because something is
 * ABSENT — an engagement never assessed, an SLA never measured, an obligation
 * marked satisfied with no evidence, a contract whose notice window quietly
 * passed. Each of those is invisible to a report built only from the records
 * that do exist, and each is the row somebody runs the report to find.
 */
class OperationalReportsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $full;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->bank = Organization::create([
            'name' => 'Jos Provincial Bank', 'short_name' => 'JPB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->bank->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->bank->id);

        $this->full = $this->user('Reporter', 'reporter@jpb.test', [
            'tprm.view', 'tprm.report.view', 'tprm.report.export',
            'tprm.assessment.view', 'tprm.contract.view', 'tprm.access.view',
            'tprm.screening.view', 'tprm.evidence.view',
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ================================================================== */
    /*  The registry and its gates */
    /* ================================================================== */

    #[Test]
    public function all_eight_reports_are_registered(): void
    {
        $this->assertSame([
            'assessment-status',
            'due-diligence-pipeline',
            'contract-expiry-calendar',
            'obligation-register',
            'sla-performance',
            'access-reconciliation',
            'screening-log',
            'evidence-expiry-forecast',
        ], app(OperationalReportRegistry::class)->keys());
    }

    #[Test]
    public function the_hub_hides_reports_whose_data_the_reader_cannot_see(): void
    {
        $limited = $this->user('Relationship Owner', 'owner@jpb.test', [
            'tprm.view', 'tprm.report.view', 'tprm.contract.view',
        ]);

        $this->actingAs($limited)
            ->get(route('tprm.reports.operational'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tprm/Reports/Operational')
                // Due diligence (tprm.view), contract expiry and obligations
                // (tprm.contract.view). Not screening, access, evidence or
                // assessments.
                ->has('reports', 4)
                ->has('withheld', 4)
            );
    }

    #[Test]
    public function a_guessed_url_does_not_get_round_the_reports_own_permission(): void
    {
        $limited = $this->user('No Screening', 'noscreen@jpb.test', [
            'tprm.view', 'tprm.report.view', 'tprm.report.export',
        ]);

        // A hub that filtered but left the routes open would be a filter in
        // name only.
        $this->actingAs($limited)
            ->get(route('tprm.reports.operational.show', 'screening-log'))
            ->assertForbidden();

        $this->actingAs($limited)
            ->get(route('tprm.reports.operational.export', ['report' => 'screening-log', 'format' => 'csv']))
            ->assertForbidden();
    }

    #[Test]
    public function reading_a_report_does_not_let_you_export_it(): void
    {
        $reader = $this->user('Reader', 'reader@jpb.test', [
            'tprm.view', 'tprm.report.view', 'tprm.contract.view',
        ]);

        $this->actingAs($reader)
            ->get(route('tprm.reports.operational.show', 'obligation-register'))
            ->assertOk();

        $this->actingAs($reader)
            ->get(route('tprm.reports.operational.export', ['report' => 'obligation-register', 'format' => 'csv']))
            ->assertForbidden();
    }

    /* ================================================================== */
    /*  The rows that exist because something is absent */
    /* ================================================================== */

    #[Test]
    public function an_engagement_that_was_never_assessed_is_a_row(): void
    {
        $this->engagement('ENG-NEVER');

        $rows = app(OperationalReportRegistry::class)->find('assessment-status')->rows();

        $this->assertCount(1, $rows);
        $this->assertContains('Never assessed', $rows[0]);
        // Nothing is due, so nothing is late — a different problem, and the
        // report says which.
        $this->assertContains('No cadence set', $rows[0]);
    }

    #[Test]
    public function a_contract_whose_notice_window_passed_says_it_has_auto_renewed(): void
    {
        $engagement = $this->engagement('ENG-RENEW');

        Contract::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->id,
            'contract_type' => 'msa',
            'reference' => 'CTR-RENEW',
            'title' => 'Hosting agreement',
            'expiry_date' => now()->addDays(40)->toDateString(),
            // Contract::RENEWAL_TYPES is ['none','auto','manual','evergreen'].
            'renewal_type' => 'auto',
            'notice_period_days_entity' => 90,
        ])->forceFill(['status' => 'executed'])->save();

        $rows = app(OperationalReportRegistry::class)->find('contract-expiry-calendar')->rows();

        // "Expires in 40 days" over a contract that silently renewed last
        // month is the most expensive kind of true statement.
        $this->assertContains('MISSED — this contract has auto-renewed', $rows[0]);
    }

    #[Test]
    public function an_obligation_marked_satisfied_with_no_evidence_is_flagged(): void
    {
        $engagement = $this->engagement('ENG-OBL');

        Obligation::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->id,
            'source' => 'clause',
            'title' => 'Annual SOC 2 report',
            'obligor' => 'vendor',
            'evidence_required' => true,
        ])->forceFill(['status' => ObligationStatus::Satisfied->value])->save();

        $rows = app(OperationalReportRegistry::class)->find('obligation-register')->rows();

        $this->assertContains('MARKED SATISFIED WITH NO EVIDENCE ATTACHED', $rows[0]);
    }

    #[Test]
    public function an_sla_with_no_measurements_reports_no_verdict_rather_than_met(): void
    {
        $engagement = $this->engagement('ENG-SLA');

        Sla::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->id,
            'metric_code' => 'UPTIME',
            'metric_name' => 'Platform availability',
            'unit' => '%',
            'target_operator' => 'gte',
            'target_value' => 99.9,
            'measurement_window' => 'monthly',
            'is_active' => true,
        ]);

        $rows = app(OperationalReportRegistry::class)->find('sla-performance')->rows();

        // A vendor that stops submitting its numbers shows as no breaches,
        // which reads identically to a vendor meeting every target.
        $this->assertContains('No measurements in the window', $rows[0]);
        $this->assertContains('Never measured', $rows[0]);
    }

    #[Test]
    public function an_undecided_screening_match_sorts_above_a_clean_run(): void
    {
        $vendor = $this->vendor('Flagged Vendor');
        $clean = $this->vendor('Clean Vendor');

        ScreeningCheck::create([
            'organization_id' => $this->bank->id,
            'subject_type' => ScreeningCheck::SUBJECT_THIRD_PARTY,
            'subject_id' => $clean->id,
            'provider' => 'internal',
            'list_types' => ['sanctions'],
            'run_at' => now()->subDay(),
            'status' => 'clear',
        ]);

        $flagged = ScreeningCheck::create([
            'organization_id' => $this->bank->id,
            'subject_type' => ScreeningCheck::SUBJECT_THIRD_PARTY,
            'subject_id' => $vendor->id,
            'provider' => 'internal',
            'list_types' => ['sanctions'],
            'run_at' => now()->subDays(3),
            'status' => 'matched',
        ]);

        ScreeningMatch::create([
            'organization_id' => $this->bank->id,
            'check_id' => $flagged->id,
            'list_name' => 'UNSCR Consolidated',
            'matched_name' => 'Flagged Vendor Ltd',
            'match_score' => 0.91,
        ]);

        $rows = app(OperationalReportRegistry::class)->find('screening-log')->rows();

        // An examiner reads the undecided ones first, and so should whoever
        // runs this before an examiner does — even though it ran earlier.
        $this->assertSame('Flagged Vendor', $rows[0][1]);
        $this->assertContains('UNDECIDED', $rows[0]);
        $this->assertContains('Nobody', $rows[0]);
    }

    #[Test]
    public function already_expired_evidence_is_marked_differently_from_expiring(): void
    {
        $engagement = $this->engagement('ENG-DOC');

        $this->document($engagement, now()->subMonth());
        $this->document($engagement, now()->addDays(20));

        $rows = app(OperationalReportRegistry::class)->find('evidence-expiry-forecast')->rows();

        $states = array_column($rows, 0);

        // Already expired is not "expiring soon": the control is unevidenced
        // today and the assurance coefficient has already moved.
        $this->assertContains('EXPIRED', $states);
        $this->assertContains('Expiring', $states);
        $this->assertSame('EXPIRED', $rows[0][0]);
    }

    #[Test]
    public function access_reconciliation_reads_the_services_own_populations(): void
    {
        $engagement = $this->engagement('ENG-ACC');

        AccessGrant::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->id,
            'grantee_name' => 'Vendor engineer',
            'system_name' => 'Core banking',
            'access_level' => 'privileged',
        ])->forceFill(['status' => AccessGrantStatus::Active->value])->save();

        $rows = app(OperationalReportRegistry::class)->find('access-reconciliation')->rows();

        // No end date recorded — one of the four populations FR-ACC-03 names.
        $this->assertNotEmpty($rows);
        $this->assertContains('No end date recorded', $rows[0]);
        $this->assertContains('Yes', $rows[0]); // privileged
    }

    /* ================================================================== */
    /*  Exports */
    /* ================================================================== */

    #[Test]
    public function every_report_exports_in_all_three_formats(): void
    {
        $this->engagement('ENG-EXPORT');

        foreach (app(OperationalReportRegistry::class)->keys() as $key) {
            $xlsx = $this->actingAs($this->full)
                ->get(route('tprm.reports.operational.export', ['report' => $key, 'format' => 'xlsx']))
                ->assertOk()->getContent();
            $this->assertStringStartsWith('PK', $xlsx, "xlsx failed for {$key}");

            $csv = $this->actingAs($this->full)
                ->get(route('tprm.reports.operational.export', ['report' => $key, 'format' => 'csv']))
                ->assertOk()->getContent();
            // FR-RPT-09: the provenance stamp is on every format, not only the
            // ones somebody is likely to file.
            $this->assertStringContainsString('Prepared by', $csv, "csv stamp missing for {$key}");

            $pdf = $this->actingAs($this->full)
                ->get(route('tprm.reports.operational.export', ['report' => $key, 'format' => 'pdf']))
                ->assertOk()->getContent();
            $this->assertStringStartsWith('%PDF', $pdf, "pdf failed for {$key}");
        }
    }

    #[Test]
    public function the_report_screen_caps_its_preview_and_says_so(): void
    {
        $this->engagement('ENG-PROPS');

        $this->actingAs($this->full)
            ->get(route('tprm.reports.operational.show', 'assessment-status'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tprm/Reports/OperationalReport')
                ->has('report.headers')
                ->has('report.rows')
                ->where('report.row_count', 1)
                ->where('report.preview_limit', 200)
                ->has('provenance.filters')
            );
    }

    /* ================================================================== */

    /** @param  list<string>  $permissions */
    private function user(string $name, string $email, array $permissions): User
    {
        $user = User::create([
            'name' => $name, 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('role-'.Str::slug($email), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole($role);

        return $user;
    }

    private function vendor(string $name): ThirdParty
    {
        return ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => $name,
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]);
    }

    private function engagement(string $reference): Engagement
    {
        $engagement = Engagement::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $this->vendor($reference.' provider')->id,
            'reference' => $reference,
            'name' => 'Service for '.$reference,
            'engagement_type' => 'ict_service',
        ]);

        $engagement->forceFill(['status' => EngagementStatus::Active->value])->save();

        return $engagement->refresh();
    }

    private function document(Engagement $engagement, \DateTimeInterface $validTo): Document
    {
        $type = DocumentType::withoutGlobalScope(\ThirdLine\Platform\Tenancy\OrganizationScope::class)
            ->where('is_assurance_evidence', true)
            ->first()
            ?? DocumentType::create([
                'organization_id' => $this->bank->id,
                'code' => 'soc2t2', 'name' => 'SOC 2 Type II',
                'has_expiry' => true, 'is_assurance_evidence' => true, 'is_active' => true,
            ]);

        return Document::create([
            'organization_id' => $this->bank->id,
            'owner_type' => Document::OWNER_ENGAGEMENT,
            'owner_id' => $engagement->getKey(),
            'document_type_id' => $type->getKey(),
            'title' => 'Evidence valid to '.$validTo->format('Y-m-d'),
            'file_path' => 'tprm/fixture.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'valid_to' => $validTo->format('Y-m-d'),
            'uploaded_by' => $this->full->id,
        ]);
    }
}

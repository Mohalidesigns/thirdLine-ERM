<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\DependencyType;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\Dependency;
use App\Models\Bcms\Process;
use App\Models\KeyRiskIndicator;
use App\Models\Organization;
use App\Models\Tprm\BcpTest;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Bcms\ResilienceKriPublisher;
use App\Services\Bcms\Suppliers\SupplierResilienceService;
use App\Services\Tprm\Continuity\BcpTestRecorder;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * BCMS Phase 11 — supplier resilience, phase-11-spec §4. A thin read-through
 * over TPRM: no new vendor table, and the one write goes through TPRM's own
 * service and authority check.
 */
class Phase11SupplierTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);
        config()->set('features.tprm', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($this->organization->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    private function criticalVendorDependency(string $reference, bool $criticalProcess = true): array
    {
        $vendor = ThirdParty::create([
            'organization_id' => $this->organization->id,
            'legal_name' => $reference.' Ltd', 'slug' => Str::random(12),
            'entity_type' => 'company', 'status' => 'active',
        ]);

        $engagement = Engagement::create([
            'organization_id' => $this->organization->id,
            'third_party_id' => $vendor->getKey(),
            'reference' => $reference, 'name' => 'Service from '.$reference,
            'engagement_type' => 'ict_service', 'supports_critical_function' => true,
        ]);

        $process = Process::factory()->create(['is_critical_service' => $criticalProcess]);
        $assessment = BiaAssessment::factory()->create(['process_id' => $process->getKey()]);

        Dependency::query()->create([
            'organization_id' => $this->organization->id,
            'assessment_id' => $assessment->getKey(),
            'dependable_type' => DependencyType::Vendors->value,
            'dependable_id' => $vendor->getKey(),
        ]);

        return ['vendor' => $vendor, 'engagement' => $engagement, 'process' => $process];
    }

    #[Test]
    public function a_vendor_with_no_bcp_test_appears_on_the_chase_list(): void
    {
        $this->criticalVendorDependency('ENG-NOTEST');

        $chase = app(SupplierResilienceService::class)->chaseList();

        $this->assertCount(1, $chase);
        $this->assertSame('No BCP test on file.', $chase[0]['reason']);
    }

    #[Test]
    public function an_expired_attestation_appears_on_the_chase_list_and_a_current_one_does_not(): void
    {
        $stale = $this->criticalVendorDependency('ENG-STALE');
        BcpTest::query()->create([
            'organization_id' => $this->organization->id,
            'engagement_id' => $stale['engagement']->getKey(),
            'test_date' => now()->subYear(), 'test_type' => 'failover',
            'next_due_at' => now()->subMonth(),
        ]);

        $current = $this->criticalVendorDependency('ENG-CURRENT');
        BcpTest::query()->create([
            'organization_id' => $this->organization->id,
            'engagement_id' => $current['engagement']->getKey(),
            'test_date' => now()->subMonth(), 'test_type' => 'failover',
            'next_due_at' => now()->addMonths(6),
        ]);

        $chase = collect(app(SupplierResilienceService::class)->chaseList())->pluck('vendor_id');

        $this->assertContains($stale['vendor']->getKey(), $chase);
        $this->assertNotContains($current['vendor']->getKey(), $chase);
    }

    /**
     * A8: `next_due_at` is optional on `tp_bcp_tests` — a test recorded
     * with no due date is not "overdue" by `isOverdue()`'s own definition
     * (it has not passed a date that was never set) and, left unruled,
     * would count as current for ever. Ruled: evidence with no expiry
     * cannot be confirmed current, so it is treated the same as overdue —
     * on the chase list, and excluded from the attestation rate's
     * numerator — with its own reason text so it reads differently from a
     * genuinely lapsed test.
     */
    #[Test]
    public function evidence_with_no_next_due_date_is_chased_and_not_counted_as_current(): void
    {
        $vendor = $this->criticalVendorDependency('ENG-NO-DUE-DATE');
        BcpTest::query()->create([
            'organization_id' => $this->organization->id,
            'engagement_id' => $vendor['engagement']->getKey(),
            'test_date' => now()->subMonth(), 'test_type' => 'failover',
            'next_due_at' => null,
        ]);

        $chase = collect(app(SupplierResilienceService::class)->chaseList())
            ->firstWhere('vendor_id', $vendor['vendor']->getKey());
        $this->assertNotNull($chase, 'A BCP test with no next-due date must appear on the chase list.');
        $this->assertSame('Continuity evidence has no next-due date on file and cannot be confirmed current.', $chase['reason']);

        $this->assertSame(
            0.0,
            app(SupplierResilienceService::class)->attestationRate(),
            'The one vendor with evidence, but no due date on it, must not count as current.'
        );
    }

    #[Test]
    public function a_vendor_with_more_than_one_qualifying_engagement_is_evidence_ambiguous(): void
    {
        $first = $this->criticalVendorDependency('ENG-A');

        // A second, separately critical engagement for the SAME vendor.
        Engagement::create([
            'organization_id' => $this->organization->id,
            'third_party_id' => $first['vendor']->getKey(),
            'reference' => 'ENG-A-2', 'name' => 'A second service',
            'engagement_type' => 'ict_service', 'supports_critical_function' => true,
        ]);

        $view = app(SupplierResilienceService::class)->continuityView();
        $row = collect($view)->firstWhere('vendor_id', $first['vendor']->getKey());

        $this->assertTrue($row['evidence_ambiguous']);
        $this->assertSame(2, $row['qualifying_engagement_count']);
        $this->assertCount(2, $row['engagements'], 'An evidence-ambiguous vendor lists every qualifying engagement rather than picking one.');
    }

    /**
     * QA minor item: the concentration view names counts and processes only
     * — no utilisation percentage (TPRM's own named go-live gap, no
     * shareholders'-funds figure behind the concentration bands). A vendor
     * dependent on by two different Tier-1 processes is the concentration
     * case itself, so its row is the one to check the key set on.
     */
    #[Test]
    public function the_concentration_view_carries_counts_and_processes_but_no_utilisation_percentage(): void
    {
        $first = $this->criticalVendorDependency('ENG-CONC-1');

        $secondProcess = Process::factory()->create(['is_critical_service' => true]);
        $secondAssessment = BiaAssessment::factory()->create(['process_id' => $secondProcess->getKey()]);
        Dependency::query()->create([
            'organization_id' => $this->organization->id,
            'assessment_id' => $secondAssessment->getKey(),
            'dependable_type' => DependencyType::Vendors->value,
            'dependable_id' => $first['vendor']->getKey(),
        ]);

        $concentration = app(SupplierResilienceService::class)->concentration();
        $row = collect($concentration['vendors'])->firstWhere('vendor_id', $first['vendor']->getKey());

        $this->assertNotNull($row, 'A vendor dependent on by two Tier-1 processes must appear on the concentration view.');
        $this->assertSame(2, $row['process_count']);
        $this->assertSame(['vendor_id', 'vendor_label', 'process_count', 'processes'], array_keys($row));
        $this->assertArrayNotHasKey('utilisation', $row);
        $this->assertArrayNotHasKey('utilisation_percentage', $row);
    }

    #[Test]
    public function the_attestation_rate_is_null_over_no_critical_vendors_and_a_real_percentage_otherwise(): void
    {
        $this->assertNull(app(SupplierResilienceService::class)->attestationRate());

        $vendor = $this->criticalVendorDependency('ENG-ONE');
        BcpTest::query()->create([
            'organization_id' => $this->organization->id,
            'engagement_id' => $vendor['engagement']->getKey(),
            'test_date' => now()->subMonth(), 'test_type' => 'failover',
            'next_due_at' => now()->addMonths(6),
        ]);

        $this->assertSame(100.0, app(SupplierResilienceService::class)->attestationRate());
    }

    #[Test]
    public function recording_an_attestation_requires_tprm_edit_in_addition_to_bcms_report_view(): void
    {
        $vendor = $this->criticalVendorDependency('ENG-AUTH');

        $bcmsOnly = $this->user('BCMS reader', 'bcms@khb.test', ['bcms.report.view']);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        app(BcpTestRecorder::class)->record($vendor['engagement'], [
            'test_date' => now(), 'test_type' => 'failover',
        ], $bcmsOnly);
    }

    #[Test]
    public function a_user_with_tprm_edit_can_record_an_attestation_through_the_recorder(): void
    {
        $vendor = $this->criticalVendorDependency('ENG-OK');
        $tprmUser = $this->user('TPRM analyst', 'tprm@khb.test', ['tprm.edit']);

        $test = app(BcpTestRecorder::class)->record($vendor['engagement'], [
            'test_date' => now(), 'test_type' => 'failover', 'outcome' => 'passed',
        ], $tprmUser);

        $this->assertSame($vendor['engagement']->getKey(), $test->engagement_id);
        $this->assertDatabaseHas('tp_bcp_tests', ['id' => $test->getKey(), 'engagement_id' => $vendor['engagement']->getKey()]);
    }

    /**
     * A12: `recordVendorAttestation()` used to call the full `adopt()` when
     * `BCMS-VENDOR-ATTEST` was missing — silently creating all seventeen
     * resilience KRIs in a bank's risk register as a side effect of
     * recording one vendor's attestation. It must adopt only its own.
     */
    #[Test]
    public function a12_recording_an_attestation_does_not_silently_adopt_all_seventeen_kris(): void
    {
        $this->assertSame(0, KeyRiskIndicator::query()->where('kri_code', 'like', 'BCMS-%')->count());

        app(ResilienceKriPublisher::class)->recordVendorAttestation(87.5, 'test');

        $this->assertSame(
            1,
            KeyRiskIndicator::query()->where('kri_code', 'like', 'BCMS-%')->count(),
            'Only BCMS-VENDOR-ATTEST is adopted — the other sixteen definitions are bcms:kri:adopt\'s job alone.'
        );
        $kri = KeyRiskIndicator::query()->where('kri_code', 'BCMS-VENDOR-ATTEST')->firstOrFail();
        $this->assertSame(87.5, (float) $kri->current_value);
    }

    #[Test]
    public function the_vendor_attest_kri_measures_only_here_and_moves_when_recorded(): void
    {
        app(ResilienceKriPublisher::class)->adopt();

        $vendor = $this->criticalVendorDependency('ENG-KRI');
        BcpTest::query()->create([
            'organization_id' => $this->organization->id,
            'engagement_id' => $vendor['engagement']->getKey(),
            'test_date' => now(), 'test_type' => 'failover', 'next_due_at' => now()->addMonths(6),
        ]);

        app(ResilienceKriPublisher::class)->recordVendorAttestation(
            app(SupplierResilienceService::class)->attestationRate(),
            'test run',
        );

        $kri = KeyRiskIndicator::query()->where('kri_code', 'BCMS-VENDOR-ATTEST')->firstOrFail();
        $this->assertSame(100.0, (float) $kri->current_value);
    }

    /**
     * B7: BCMS-VENDOR-ATTEST used to be measured only on `storeAttestation()`
     * and the demo seeder — a vendor's evidence simply ageing past its
     * `next_due_at` never moved the KRI on its own. The daily
     * `bcms:vendor-attestation-recompute` sweep is what makes it move.
     */
    #[Test]
    public function b7_the_daily_sweep_moves_the_vendor_attest_kri_when_evidence_ages_past_its_due_date(): void
    {
        app(ResilienceKriPublisher::class)->adopt();

        $vendor = $this->criticalVendorDependency('ENG-AGES-OUT');
        $test = BcpTest::query()->create([
            'organization_id' => $this->organization->id,
            'engagement_id' => $vendor['engagement']->getKey(),
            'test_date' => now()->subMonths(11), 'test_type' => 'failover',
            'next_due_at' => now()->addDay(),
        ]);

        // Today, the evidence is still current — 100%.
        $this->artisan('bcms:vendor-attestation-recompute')->assertSuccessful();
        $kri = KeyRiskIndicator::query()->where('kri_code', 'BCMS-VENDOR-ATTEST')->firstOrFail();
        $this->assertSame(100.0, (float) $kri->current_value);

        // Travel past next_due_at — nobody recorded a new attestation, the
        // evidence simply aged out.
        $this->travelTo(now()->addDays(3));

        $this->artisan('bcms:vendor-attestation-recompute')->assertSuccessful();
        $kri->refresh();
        $this->assertSame(0.0, (float) $kri->current_value, 'The sweep must recompute the rate even though nobody touched an attestation.');

        $this->travelBack();
    }

    /**
     * B13: `evidence_document_id` was an unconstrained integer — another
     * tenant's `tp_documents` row was accepted outright, and a nonexistent
     * id 500'd instead of failing validation cleanly.
     */
    #[Test]
    public function b13_evidence_document_id_must_belong_to_a_document_in_the_actors_own_tenant(): void
    {
        $vendor = $this->criticalVendorDependency('ENG-EVID');
        $actor = $this->user('TPRM analyst', 'tprm-evid@khb.test', ['bcms.report.view', 'tprm.edit']);

        $otherOrg = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($otherOrg->id);
        $otherDocument = \App\Models\Tprm\Document::create([
            'organization_id' => $otherOrg->id, 'owner_type' => 'third_party', 'owner_id' => 1,
            'title' => "Another tenant's document", 'file_path' => 'x/y.pdf',
        ]);
        TenantContext::set($this->organization->id);

        // A document id belonging to a different tenant is refused.
        $this->actingAs($actor)->post(route('bcms.vendors.attestation.store', $vendor['vendor']), [
            'engagement_id' => $vendor['engagement']->getKey(),
            'test_date' => now()->toDateString(), 'test_type' => 'failover',
            'evidence_document_id' => $otherDocument->getKey(),
        ])->assertSessionHasErrors('evidence_document_id');

        // A nonexistent id is refused cleanly (validation error), not a 500.
        $this->actingAs($actor)->post(route('bcms.vendors.attestation.store', $vendor['vendor']), [
            'engagement_id' => $vendor['engagement']->getKey(),
            'test_date' => now()->toDateString(), 'test_type' => 'failover',
            'evidence_document_id' => 999999,
        ])->assertSessionHasErrors('evidence_document_id');
    }

    /**
     * A6: vendor exercise participation matches `tp_contacts.email` to
     * `bcms_contacts.email` case-insensitively on BOTH sides. MariaDB's
     * default collation already matches `whereIn` case-insensitively; the
     * defect was a case-sensitive PHP array-key lookup on top of it, which
     * rendered `vendor_third_party_id = 0` for a real, named person.
     */
    #[Test]
    public function a6_vendor_participation_matches_email_case_insensitively(): void
    {
        $vendor = ThirdParty::create([
            'organization_id' => $this->organization->id,
            'legal_name' => 'CaseTest Ltd', 'slug' => Str::random(12),
            'entity_type' => 'company', 'status' => 'active',
        ]);
        \App\Models\Tprm\Contact::create([
            'organization_id' => $this->organization->id, 'third_party_id' => $vendor->getKey(),
            'name' => 'Musa Ibrahim', 'email' => 'Musa@Vendor.com', 'role_type' => 'relationship',
        ]);

        $bcmsContact = \App\Models\Bcms\Contact::create([
            'organization_id' => $this->organization->id,
            'full_name' => 'Musa Ibrahim', 'email' => 'MUSA@vendor.COM',
            'consent_status' => 'granted', 'verification_status' => 'verified',
        ]);
        $occurrence = \App\Models\Bcms\ExerciseOccurrence::factory()->create();
        \App\Models\Bcms\ExerciseParticipant::factory()->create([
            'occurrence_id' => $occurrence->getKey(), 'contact_id' => $bcmsContact->getKey(),
            'invitation_status' => 'accepted', 'attendance_status' => 'present',
        ]);

        $viewer = $this->user('Report Viewer', 'report-a6@khb.test', ['bcms.report.view']);
        $response = $this->actingAs($viewer)->get(route('bcms.vendors.continuity'))->assertOk();
        $participation = $response->viewData('page')['props']['participation'];

        $row = collect($participation)->firstWhere('contact_name', 'Musa Ibrahim');
        $this->assertNotNull($row);
        $this->assertSame($vendor->getKey(), $row['vendor_third_party_id'], 'A case-differing email must still resolve to the real vendor, not 0.');
    }

    /* ================================================================== */

    /** @param  list<string>  $permissions */
    private function user(string $name, string $email, array $permissions): User
    {
        $user = User::create([
            'name' => $name, 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('role-'.Str::slug($email), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole($role);

        return $user;
    }
}

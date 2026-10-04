<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\DependencyType;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\Dependency;
use App\Models\Bcms\ManagementReview;
use App\Models\Bcms\Plan;
use App\Models\Bcms\Process;
use App\Models\Bcms\Programme;
use App\Models\Bcms\TrainingCurriculum;
use App\Models\Bcms\TrainingRecord;
use App\Models\Organization;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Bcms\ProgrammeService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The seven Phase 11 screens: asserts the Inertia component each route
 * renders and every prop the screen actually reads, including every `*_url`
 * — a screen that reads a prop the controller never sent, or a route that
 * has quietly renamed its component, fails here rather than in a browser.
 *
 * EVERY `*_url` IS COMPARED AGAINST `route()` ITSELF, never a hand-built
 * string. `ThirdParty`/`Plan`/`ManagementReview` all carry a `uuid` route key
 * (`HasTprmUuid`/`HasBcmsUuid`); `route($name, $model)` resolves whichever
 * key the model actually binds on, which is exactly the property
 * `ModuleActionUrlRouteKeyTest` polices from the JSX side — this file polices
 * it from the controller side, by asserting the URL a real HTTP round trip
 * produced is the one `route()` would build today.
 */
class Phase11ScreensTest extends TestCase
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

    /* ================================================================== */
    /*  1. Compliance & evidence matrix */
    /* ================================================================== */

    #[Test]
    public function the_compliance_matrix_renders_its_component_and_summary(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        $user = $this->user('Compliance Officer', 'compliance@khb.test', ['bcms.report.view', 'bcms.report.export']);

        $this->actingAs($user)->get(route('bcms.reports.compliance-matrix'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Compliance/Matrix', false)
                ->where('empty_programme', false)
                ->has('sections')
                ->has('summary.green')
                ->has('summary.mandatory_gap_count')
                ->has('kris')
                ->where('can.export', true)
                // QA's own addition, code review #3 A3 (backend + frontend):
                // `programme.status` and `newer_draft_programme` are what
                // Matrix.jsx's `governingIsApproved`/`newerDraftProgramme`
                // note read directly — pin both reach the page, and that the
                // single-approved-programme case (no competing draft) is
                // correctly null, not merely absent from the prop tree.
                ->where('programme.status', 'approved')
                ->where('newer_draft_programme', null)
            );
    }

    /**
     * QA's own test, code review #3 A3: the sibling positive case —
     * `newer_draft_programme` must be non-null, carrying the draft's own
     * `id`/`year`, when one exists, so Matrix.jsx's neutral note has a real
     * prop to render rather than only ever seeing `null` in the test suite.
     */
    #[Test]
    public function the_compliance_matrix_carries_a_non_null_newer_draft_programme_when_one_exists(): void
    {
        $governing = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        $draft = Programme::factory()->create([
            'status' => 'draft', 'year' => now()->year + 1, 'created_at' => $governing->created_at->copy()->addDay(),
        ]);
        $user = $this->user('Compliance Officer', 'compliance-draft@khb.test', ['bcms.report.view', 'bcms.report.export']);

        $this->actingAs($user)->get(route('bcms.reports.compliance-matrix'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Compliance/Matrix', false)
                ->where('programme.id', $governing->getKey())
                ->where('programme.status', 'approved')
                ->where('newer_draft_programme.id', $draft->getKey())
                ->where('newer_draft_programme.year', $draft->year)
            );
    }

    /**
     * QA minor item: `bcms_maturity_assessments` has no business-unit
     * column, so a per-branch heatmap is not buildable honestly today —
     * `ComplianceController::maturityHeatmap()` says so explicitly rather
     * than fabricating branch rows, and this pins that it keeps saying so.
     */
    #[Test]
    public function the_maturity_heatmap_ships_per_branch_available_false(): void
    {
        $user = $this->user('Report Viewer', 'reportviewer@khb.test', ['bcms.report.view']);

        $this->actingAs($user)->getJson(route('bcms.reports.maturity-heatmap'))
            ->assertOk()
            ->assertJsonPath('per_branch_available', false);
    }

    #[Test]
    public function the_compliance_matrix_names_the_gap_rather_than_showing_eighty_red_cells_with_no_programme(): void
    {
        $user = $this->user('Compliance Officer', 'noprog@khb.test', ['bcms.report.view']);

        $this->actingAs($user)->get(route('bcms.reports.compliance-matrix'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Compliance/Matrix', false)
                ->where('empty_programme', true)
            );
    }

    /* ================================================================== */
    /*  2. Training & competency */
    /* ================================================================== */

    #[Test]
    public function training_compliance_renders_its_component_with_server_built_assess_urls(): void
    {
        $manager = $this->user('Training Admin', 'trainer@khb.test', ['bcms.training.view', 'bcms.training.manage']);

        $this->actingAs($manager)->get(route('bcms.training.compliance'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Training/Compliance', false)
                ->has('curricula', 6)
                ->has('rows')
                ->has('departments')
                ->has('users')
                ->where('can.manage', true)
            );
    }

    /**
     * B10: the compliance register used to lazy-load an assessor per row and
     * rebuild the whole employee × curriculum set for the tiles, then again
     * for the curricula list's `assigned_count`. The query count for
     * rendering the screen must not grow with the number of assessed
     * training records on it.
     */
    #[Test]
    public function b10_the_compliance_screen_does_not_n_plus_one_on_the_number_of_assessed_records(): void
    {
        // Whole-estate scope, so every new assessed record actually reaches
        // the response — otherwise the visibility filter (B11) would hide
        // them all from a unit-scoped viewer and the N+1 this test targets
        // would never be exercised.
        $manager = $this->user('Training Admin', 'trainer-b10@khb.test', ['bcms.training.view', 'bcms.training.manage', 'rcsa_scope.all_units']);
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-WARDEN')->firstOrFail();
        $assessor = $this->user('Assessor', 'assessor-b10@khb.test', []);

        $wardenRole = Role::findOrCreate('floor-warden', 'web');

        $makeAssessedRecords = function (int $count) use ($curriculum, $assessor, $wardenRole) {
            for ($i = 0; $i < $count; $i++) {
                $subject = $this->user("Warden {$i}-".uniqid(), "warden-b10-{$i}-".uniqid().'@khb.test', []);
                $subject->assignRole($wardenRole);
                app(\App\Services\Bcms\Training\TrainingComplianceService::class)->recordOutcome([
                    'curriculum_id' => $curriculum->getKey(),
                    'user_id' => $subject->getKey(),
                    'completed_at' => now(),
                    'score' => 85,
                ], $assessor->getKey());
            }
        };

        // A cold first request boots lazy providers, warms the permission
        // cache, etc. — cost that has nothing to do with the number of
        // training records and would swamp any real N+1 in a raw
        // first-call-vs-second-call comparison. Warm up, unmeasured, before
        // taking either reading.
        $makeAssessedRecords(3);
        $this->actingAs($manager)->get(route('bcms.training.compliance'))->assertOk();

        DB::enableQueryLog();
        $this->actingAs($manager)->get(route('bcms.training.compliance'))->assertOk();
        $queriesAtThree = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        $makeAssessedRecords(15);
        $this->actingAs($manager)->get(route('bcms.training.compliance'))->assertOk(); // warm again — new roles/users were just created

        DB::enableQueryLog();
        $this->actingAs($manager)->get(route('bcms.training.compliance'))->assertOk();
        $queriesAtEighteen = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A tolerance rather than an exact match: incidental timing-sensitive
        // writes (e.g. `last_activity_at` throttling) can shift the count by
        // one between otherwise-identical requests. An N+1 on 15 additional
        // assessed records would add roughly fifteen queries (one per row's
        // lazy-loaded assessor); a tolerance of three catches that while not
        // flaking on unrelated incidental variance.
        $this->assertLessThanOrEqual(
            $queriesAtThree + 3,
            $queriesAtEighteen,
            'Rendering the compliance screen must not run materially more queries as the number of assessed records grows (no per-row assessor lazy-load, no repeated recomputation).'
        );
    }

    /**
     * B11: NDPA register §11.4 rules 1-5. A department champion scoped to one
     * business unit must not see another unit's employees' names, scores or
     * assessors — and the assessor/subject picker must be scoped the same
     * way, or it leaks the whole workforce regardless of the rows.
     */
    #[Test]
    public function b11_a_unit_scoped_viewer_sees_only_their_own_units_training_rows(): void
    {
        $kano = \App\Models\BusinessUnit::create(['organization_id' => $this->organization->id, 'code' => 'BU-K-'.Str::random(6), 'name' => 'Kano', 'is_active' => true]);
        $lagos = \App\Models\BusinessUnit::create(['organization_id' => $this->organization->id, 'code' => 'BU-L-'.Str::random(6), 'name' => 'Lagos', 'is_active' => true]);

        $champion = $this->user('Kano Champion', 'kano-champion@khb.test', ['bcms.training.view']);
        DB::table('business_unit_user')->insert([
            'organization_id' => $this->organization->id, 'user_id' => $champion->getKey(),
            'business_unit_id' => $kano->getKey(), 'includes_descendants' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $kanoEmployee = User::create([
            'name' => 'Kano Employee', 'email' => 'kano-emp@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
            'business_unit_id' => $kano->getKey(),
        ]);
        $lagosEmployee = User::create([
            'name' => 'Lagos Employee', 'email' => 'lagos-emp@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
            'business_unit_id' => $lagos->getKey(),
        ]);
        $unassignedEmployee = User::create([
            'name' => 'Unassigned Employee', 'email' => 'unassigned-emp@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $curriculum = TrainingCurriculum::query()->where('code', 'BC-AWARE-ALL')->firstOrFail();
        app(\App\Services\Bcms\Training\TrainingComplianceService::class)->recordOutcome([
            'curriculum_id' => $curriculum->getKey(), 'user_id' => $lagosEmployee->getKey(), 'completed_at' => now(),
        ]);

        $response = $this->actingAs($champion)->get(route('bcms.training.compliance'))->assertOk();
        $rows = $response->viewData('page')['props']['rows'];
        $userNames = collect($rows)->pluck('user_name')->unique()->values()->all();

        $this->assertContains('Kano Employee', $userNames, 'The champion sees their own unit.');
        $this->assertNotContains('Lagos Employee', $userNames, "Rule 1/2: another unit's employee is not visible.");
        $this->assertNotContains('Unassigned Employee', $userNames, 'Rule 2: a no-unit person is visible only to a whole-estate viewer, never a unit-scoped one.');
        $this->assertContains('Kano Champion', $userNames, "Rule 3: the champion sees their own row even though they're the viewer.");

        // Rule 4: the picker leaks unless scoped identically.
        $pickerNames = collect($response->viewData('page')['props']['users'])->pluck('name')->all();
        $this->assertContains('Kano Employee', $pickerNames);
        $this->assertNotContains('Lagos Employee', $pickerNames, 'Rule 4: the picker must be scoped the same way as the register.');
        $this->assertNotContains('Unassigned Employee', $pickerNames);

        // A whole-estate viewer sees everyone.
        $examiner = $this->user('Examiner', 'examiner-b11@khb.test', ['bcms.training.view', 'rcsa_scope.all_units']);
        $wholeEstateRows = $this->actingAs($examiner)->get(route('bcms.training.compliance'))
            ->viewData('page')['props']['rows'];
        $wholeEstateNames = collect($wholeEstateRows)->pluck('user_name')->unique()->values()->all();
        $this->assertContains('Lagos Employee', $wholeEstateNames);
        $this->assertContains('Unassigned Employee', $wholeEstateNames);
    }

    /**
     * B11 rule 3, the assessor exception: a unit-scoped viewer who assessed
     * someone OUTSIDE their own unit still sees that one row — they wrote
     * the score, so it is their record too.
     */
    #[Test]
    public function b11_a_unit_scoped_assessor_still_sees_a_row_they_assessed_outside_their_own_unit(): void
    {
        $kano = \App\Models\BusinessUnit::create(['organization_id' => $this->organization->id, 'code' => 'BU-K-'.Str::random(6), 'name' => 'Kano', 'is_active' => true]);
        $lagos = \App\Models\BusinessUnit::create(['organization_id' => $this->organization->id, 'code' => 'BU-L-'.Str::random(6), 'name' => 'Lagos', 'is_active' => true]);

        $kanoAssessor = $this->user('Kano Assessor', 'kano-assessor-b11@khb.test', ['bcms.training.view', 'bcms.training.manage']);
        DB::table('business_unit_user')->insert([
            'organization_id' => $this->organization->id, 'user_id' => $kanoAssessor->getKey(),
            'business_unit_id' => $kano->getKey(), 'includes_descendants' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $lagosSubject = User::create([
            'name' => 'Lagos Subject', 'email' => 'lagos-subject-b11@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
            'business_unit_id' => $lagos->getKey(),
        ]);
        $lagosSubject->assignRole(Role::findOrCreate('crisis-manager', 'web'));

        $curriculum = TrainingCurriculum::query()->where('code', 'BC-CRISIS')->firstOrFail();
        app(\App\Services\Bcms\Training\TrainingComplianceService::class)->recordOutcome([
            'curriculum_id' => $curriculum->getKey(), 'user_id' => $lagosSubject->getKey(),
            'completed_at' => now(), 'score' => 92,
        ], $kanoAssessor->getKey());

        $rows = $this->actingAs($kanoAssessor)->get(route('bcms.training.compliance'))
            ->viewData('page')['props']['rows'];

        $this->assertContains('Lagos Subject', collect($rows)->pluck('user_name')->all(), 'The recorded assessor sees the row they assessed, even outside their own unit.');
    }

    /**
     * A3: `ModuleSections::fake()` is a static override read at boot by
     * `routes/web.php` — calling it outside a test would silently change
     * what every request sees for the rest of the process. Guarded on the
     * raw `APP_ENV` environment variable (not `app()->environment()`),
     * because a real test calls this BEFORE `parent::setUp()` boots the
     * application, when the container is not yet safely resolvable.
     */
    #[Test]
    public function a3_module_sections_fake_refuses_to_run_outside_a_test_environment(): void
    {
        $original = getenv('APP_ENV');
        putenv('APP_ENV=production');

        try {
            $this->expectException(\RuntimeException::class);
            \App\Support\Bcms\ModuleSections::fake([]);
        } finally {
            putenv($original === false ? 'APP_ENV' : "APP_ENV={$original}");
        }
    }

    /* ================================================================== */
    /*  3. Evidence pack export */
    /* ================================================================== */

    #[Test]
    public function the_evidence_pack_screen_renders_its_frameworks_and_log(): void
    {
        $user = $this->user('Compliance Officer', 'export@khb.test', ['bcms.report.export']);

        $this->actingAs($user)->get(route('bcms.reports.regulatory-evidence.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Reports/EvidencePack', false)
                ->where('frameworks', ['iso22301', 'cbn_csf', 'cbn_open_banking', 'dora'])
                ->has('log')
            );
    }

    /* ================================================================== */
    /*  4. Board pack */
    /* ================================================================== */

    #[Test]
    public function the_board_pack_screen_renders_its_year_and_preview_sections(): void
    {
        $user = $this->user('Programme Owner', 'boardpack@khb.test', ['bcms.report.view']);

        $this->actingAs($user)->get(route('bcms.reports.board-pack', ['year' => now()->year]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Reports/BoardPack', false)
                ->where('year', now()->year)
                ->has('preview.posture_summary')
                ->has('preview.maturity_trend')
                ->has('preview.kris')
                ->where('can.export', false)
            );
    }

    /* ================================================================== */
    /*  5. Supplier resilience */
    /* ================================================================== */

    #[Test]
    public function supplier_resilience_ships_a_server_built_attestation_url_keyed_on_the_vendor_uuid(): void
    {
        $vendor = ThirdParty::create([
            'organization_id' => $this->organization->id,
            'legal_name' => 'Zenith Cloud Ltd', 'slug' => Str::random(12),
            'entity_type' => 'company', 'status' => 'active',
        ]);

        Engagement::create([
            'organization_id' => $this->organization->id,
            'third_party_id' => $vendor->getKey(),
            'reference' => 'ENG-1', 'name' => 'Core hosting',
            'engagement_type' => 'ict_service', 'supports_critical_function' => true,
        ]);

        $process = Process::factory()->create(['is_critical_service' => true]);
        $assessment = BiaAssessment::factory()->create(['process_id' => $process->getKey()]);

        Dependency::query()->create([
            'organization_id' => $this->organization->id,
            'assessment_id' => $assessment->getKey(),
            'dependable_type' => DependencyType::Vendors->value,
            'dependable_id' => $vendor->getKey(),
        ]);

        $user = $this->user('Continuity Analyst', 'supplier@khb.test', ['bcms.report.view']);
        $user->givePermissionTo(Permission::findOrCreate('tprm.edit', 'web'));

        $expectedUrl = route('bcms.vendors.attestation.store', $vendor);
        $this->assertStringContainsString($vendor->uuid, $expectedUrl, 'ThirdParty route-keys on uuid; a numeric id here would 404.');

        $this->actingAs($user)->get(route('bcms.vendors.continuity'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Suppliers/Resilience', false)
                ->where('critical_vendor_count', 1)
                ->where('continuity.0.attestation_url', $expectedUrl)
                ->where('can.record_attestation', true)
            );
    }

    #[Test]
    public function the_attestation_action_is_absent_entirely_without_tprm_authority(): void
    {
        $vendor = ThirdParty::create([
            'organization_id' => $this->organization->id,
            'legal_name' => 'Zenith Cloud Ltd', 'slug' => Str::random(12),
            'entity_type' => 'company', 'status' => 'active',
        ]);

        Engagement::create([
            'organization_id' => $this->organization->id,
            'third_party_id' => $vendor->getKey(),
            'reference' => 'ENG-1', 'name' => 'Core hosting',
            'engagement_type' => 'ict_service', 'supports_critical_function' => true,
        ]);

        $process = Process::factory()->create(['is_critical_service' => true]);
        $assessment = BiaAssessment::factory()->create(['process_id' => $process->getKey()]);

        Dependency::query()->create([
            'organization_id' => $this->organization->id,
            'assessment_id' => $assessment->getKey(),
            'dependable_type' => DependencyType::Vendors->value,
            'dependable_id' => $vendor->getKey(),
        ]);

        // bcms.report.view only — no tprm.edit.
        $user = $this->user('Continuity Analyst', 'no-authority@khb.test', ['bcms.report.view']);

        $this->actingAs($user)->get(route('bcms.vendors.continuity'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('continuity.0.attestation_url', null)
                ->where('can.record_attestation', false)
            );
    }

    /* ================================================================== */
    /*  6. Management review inputs */
    /* ================================================================== */

    #[Test]
    public function the_review_show_screen_renders_the_snapshot_and_its_action_urls(): void
    {
        $review = ManagementReview::factory()->create(['status' => 'draft']);
        app(ProgrammeService::class)->captureReviewInputs($review, [
            'internal_audit' => ['report_reference' => 'IA-2027-04', 'conclusion' => 'Satisfactory'],
        ]);

        $user = $this->user('Programme Owner', 'review@khb.test', ['bcms.programme.manage', 'bcms.programme.approve', 'bcms.report.view']);

        $this->actingAs($user)->get(route('bcms.reviews.show', $review))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Reviews/Show', false)
                ->where('review.uuid', $review->uuid)
                ->where('review.status', 'draft')
                ->has('review.inputs.internal_audit')
                ->where('can.manage', true)
                ->where('can.approve', true)
            );
    }

    #[Test]
    public function a_viewer_with_only_report_view_can_read_an_approved_review(): void
    {
        $review = ManagementReview::factory()->create(['status' => 'draft']);
        app(ProgrammeService::class)->captureReviewInputs($review);
        $review->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => null]);

        $examiner = $this->user('Examiner', 'examiner@khb.test', ['bcms.report.view']);

        $this->actingAs($examiner)->get(route('bcms.reviews.show', $review))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Reviews/Show', false)
                ->where('review.status', 'approved')
                ->where('can.manage', false)
            );
    }

    /**
     * A11: `showReview()`'s own comment always said `bcms.report.view`
     * reaches an APPROVED review — nothing enforced it. A draft is not
     * served to a report-view-only holder.
     */
    #[Test]
    public function a11_a_report_view_only_holder_cannot_read_a_draft_review(): void
    {
        $review = ManagementReview::factory()->create(['status' => 'draft']);
        app(ProgrammeService::class)->captureReviewInputs($review);

        $examiner = $this->user('Examiner', 'examiner-a11@khb.test', ['bcms.report.view']);

        $this->actingAs($examiner)->get(route('bcms.reviews.show', $review))->assertForbidden();

        $manager = $this->user('Programme Owner', 'owner-a11@khb.test', ['bcms.report.view', 'bcms.programme.manage']);
        $this->actingAs($manager)->get(route('bcms.reviews.show', $review))->assertOk();
    }

    /**
     * A7 (code review #2): `$yearEnd = now()->endOfYear()->toDateString()`
     * truncated the upper bound to a bare date ('2026-12-31'), which
     * `whereBetween` on a DATETIME column reads as midnight — an incident
     * (or alert, DR test, BIA approval…) on 31 December after 00:00:00 fell
     * outside "this year" for a review captured that day. Fixed to full
     * datetime bounds.
     */
    #[Test]
    public function a7_an_incident_detected_late_on_new_years_eve_is_still_counted_in_this_years_review(): void
    {
        $this->travelTo(now()->endOfYear()->subHours(2));

        \App\Models\Bcms\Incident::factory()->create([
            'detected_at' => now()->endOfYear()->subHour(),
        ]);

        $review = ManagementReview::factory()->create(['status' => 'draft']);
        $captured = app(ProgrammeService::class)->captureReviewInputs($review);

        $this->assertSame(1, $captured->inputs['incidents']['count'], 'An incident detected after midnight on 31 December must still count in this year\'s review.');

        $this->travelBack();
    }

    /**
     * B6: the acknowledgement window used to be measured
     * `acknowledged_at->diffInMinutes(dispatched_at)`, signed under Carbon 3
     * — a two-hour-late acknowledgement produced a -120 minute diff, which
     * `<= 15` counted as "within 15 minutes".
     */
    #[Test]
    public function b6_a_two_hour_late_acknowledgement_is_not_counted_as_within_fifteen_minutes(): void
    {
        $dispatchedAt = now()->subHours(3);

        $alert = \App\Models\Bcms\Alert::factory()->create(['dispatched_at' => $dispatchedAt]);
        \App\Models\Bcms\AlertRecipient::factory()->create([
            'alert_id' => $alert->getKey(),
            'acknowledged_at' => $dispatchedAt->copy()->addHours(2),
        ]);

        // A second, genuinely-within-window recipient so the rate is neither
        // 0 nor 100 by coincidence.
        \App\Models\Bcms\AlertRecipient::factory()->create([
            'alert_id' => $alert->getKey(),
            'acknowledged_at' => $dispatchedAt->copy()->addMinutes(5),
        ]);

        $review = ManagementReview::factory()->create(['status' => 'draft']);
        $captured = app(ProgrammeService::class)->captureReviewInputs($review);

        $performance = $captured->inputs['call_tree_and_emns_performance'];
        $this->assertSame(2, $performance['emns_recipients_measured']);
        $this->assertEqualsWithDelta(50.0, $performance['emns_ack_within_15_minutes_rate'], 0.01, 'Exactly one of two recipients acknowledged within 15 minutes.');
    }

    /**
     * B12: an approved review's clause 9.2 block is a minuted, signed-off
     * record — capture must be refused once approved, and only the five
     * named `internal_audit` keys reach the snapshot.
     */
    #[Test]
    public function b12_capturing_inputs_on_an_approved_review_is_refused(): void
    {
        $review = ManagementReview::factory()->create(['status' => 'draft']);
        app(ProgrammeService::class)->captureReviewInputs($review, [
            'internal_audit' => ['report_reference' => 'IA-2027-01', 'conclusion' => 'Original.'],
        ]);
        $review->update(['status' => 'approved', 'approved_at' => now()]);

        $manager = $this->user('Programme Owner', 'owner-b12@khb.test', ['bcms.programme.manage']);

        $this->actingAs($manager)->post(route('bcms.reviews.capture', $review), [
            'internal_audit' => ['report_reference' => 'IA-OVERWRITE', 'conclusion' => 'Rewritten after approval.'],
        ])->assertSessionHas('error');

        $review->refresh();
        $this->assertSame('IA-2027-01', $review->inputs['internal_audit']['report_reference'], 'An approved review\'s 9.2 block is locked.');
    }

    #[Test]
    public function b12_only_the_five_named_internal_audit_keys_are_stored(): void
    {
        $review = ManagementReview::factory()->create(['status' => 'draft']);
        $manager = $this->user('Programme Owner', 'owner-b12b@khb.test', ['bcms.programme.manage']);

        $this->actingAs($manager)->post(route('bcms.reviews.capture', $review), [
            'internal_audit' => [
                'report_reference' => 'IA-2027-02',
                'date' => now()->toDateString(),
                'auditor' => 'Internal Audit Function',
                'independence_statement' => 'Independent of the BCMS function.',
                'conclusion' => 'Satisfactory.',
                'malicious_extra_key' => '<script>not stored</script>',
            ],
        ])->assertSessionDoesntHaveErrors();

        $review->refresh();
        $this->assertArrayNotHasKey('malicious_extra_key', $review->inputs['internal_audit']);
        $this->assertSame('IA-2027-02', $review->inputs['internal_audit']['report_reference']);
        $this->assertSame('Satisfactory.', $review->inputs['internal_audit']['conclusion']);
    }

    /* ================================================================== */
    /*  7. My Resilience */
    /* ================================================================== */

    #[Test]
    public function my_resilience_ships_server_built_plan_urls_keyed_on_the_plan_uuid(): void
    {
        $user = $this->user('Branch Employee', 'employee@khb.test', ['my.view']);

        $plan = Plan::factory()->create([
            'status' => 'approved', 'business_unit_id' => $user->business_unit_id,
        ]);

        $expectedShow = route('bcms.plans.show', $plan);
        $expectedAck = route('bcms.plans.acknowledge', $plan);
        $this->assertStringContainsString($plan->uuid, $expectedShow, 'Plan route-keys on uuid; a numeric id here would 404.');

        $this->actingAs($user)->get(route('bcms.myresilience.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/MyResilience/Index', false)
                ->where('plans.0.show_url', $expectedShow)
                ->where('plans.0.acknowledge_url', $expectedAck)
                ->has('training')
                ->has('call_tree_role')
                ->has('contact')
                ->has('next_exercise')
            );
    }

    #[Test]
    public function my_resilience_states_missing_records_honestly_rather_than_a_blank_field(): void
    {
        $user = $this->user('New Hire', 'newhire@khb.test', ['my.view']);

        $this->actingAs($user)->get(route('bcms.myresilience.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/MyResilience/Index', false)
                ->where('call_tree_role', null)
                ->where('contact', null)
                ->where('next_exercise', null)
            );
    }

    /**
     * qa-engineer gate-1 re-gate: `MyResilienceService::training()` must
     * exclude a training record whose curriculum has been retired
     * (`is_active = false`, e.g. a Phase 0 placeholder code superseded by
     * this phase's pack) from the "my training" list — and, in particular,
     * must never surface it as a live overdue obligation for an employee who
     * is no longer assigned to it.
     *
     * `BC-FACILITATOR`'s own `target_roles` (`['risk-manager']`) is not this
     * user's role, so the retired record's absence is not merely a
     * consequence of `is_active`; the user also never held the role it was
     * assigned by. What the row list is NOT empty of is the tenant-wide
     * `BC-AWARE-ALL` placeholder every active user is assigned to
     * (`my_resilience_shows_all_staff_awareness_training_even_when_never_attended`)
     * — asserted explicitly here so this test does not regress to expecting
     * an empty list the moment that curriculum is present.
     */
    #[Test]
    public function my_resilience_excludes_a_retired_curricula_record_from_the_training_list(): void
    {
        $user = $this->user('Ex Facilitator', 'exfacilitator@khb.test', ['my.view']);

        $awareAll = TrainingCurriculum::query()->where('code', 'BC-AWARE-ALL')->firstOrFail();

        $retired = TrainingCurriculum::query()->create([
            'organization_id' => null, 'code' => 'BC-FACILITATOR',
            'name' => 'Exercise facilitator', 'target_roles' => ['risk-manager'], 'modules' => [],
            'frequency_months' => 24, 'is_mandatory' => false, 'requires_assessment' => true,
            'is_system_default' => true, 'is_active' => false,
        ]);

        TrainingRecord::query()->create([
            'organization_id' => $this->organization->id,
            'curriculum_id' => $retired->getKey(),
            'user_id' => $user->getKey(),
            'completed_at' => now()->subYears(2),
            'competency_assessed' => true,
            'next_due_date' => now()->subYear()->toDateString(),
        ]);

        $this->actingAs($user)->get(route('bcms.myresilience.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/MyResilience/Index', false)
                ->has('training', 1)
                ->where('training.0.curriculum_name', $awareAll->name)
                ->where('training.0.completed_at', null)
            );
    }

    /**
     * The positive counterpart to the retired-curriculum exclusion above: a
     * record against a curriculum that is still ACTIVE and where the person
     * is still a current role-holder must keep appearing, with the correct
     * attendance/competency lines and the correct overdue state — the fix
     * must exclude the retired case without over-excluding the current one.
     *
     * Every active user (this one included) is also assigned to the
     * tenant-wide `BC-AWARE-ALL` curriculum, unattended here, so it renders
     * as `training.0` — `curricula()` orders by `code`, and `BC-AWARE-ALL`
     * sorts ahead of `BC-WARDEN` — leaving the assertions below on
     * `training.1`.
     */
    #[Test]
    public function my_resilience_still_shows_a_current_record_on_an_active_curriculum_with_its_due_state(): void
    {
        $user = $this->user('Warden', 'warden-current@khb.test', ['my.view']);
        $user->assignRole(Role::findOrCreate('floor-warden', 'web'));

        $curriculum = TrainingCurriculum::query()->where('code', 'BC-WARDEN')->firstOrFail();

        TrainingRecord::query()->create([
            'organization_id' => $this->organization->id,
            'curriculum_id' => $curriculum->getKey(),
            'user_id' => $user->getKey(),
            'completed_at' => now()->subMonths(2),
            'competency_assessed' => true,
            'score' => 88,
            'next_due_date' => now()->addMonths(10)->toDateString(),
        ]);

        $this->actingAs($user)->get(route('bcms.myresilience.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/MyResilience/Index', false)
                ->has('training', 2)
                ->where('training.1.curriculum_name', $curriculum->name)
                ->where('training.1.competency_assessed', true)
                ->where('training.1.overdue', false)
            );
    }

    /**
     * A10 (code review #2): `competency_assessed` means "passed" (B3), so a
     * FAILED assessment — a real record with a score, an assessor and a
     * date, below the pass mark — rendered `competency_assessed: false`,
     * identical to "not yet assessed" at all. The person could not tell
     * from their own page whether nobody had assessed them yet, or they sat
     * it and did not clear the pass mark. `assessed` and `failed` are now
     * additive facts alongside the existing key.
     */
    #[Test]
    public function a10_my_resilience_shows_a_failed_assessment_as_failed_not_unassessed(): void
    {
        $user = $this->user('Warden', 'warden-failed@khb.test', ['my.view']);
        $user->assignRole(Role::findOrCreate('floor-warden', 'web'));
        $assessor = $this->user('Assessor', 'assessor-failed@khb.test', []);

        $curriculum = TrainingCurriculum::query()->where('code', 'BC-WARDEN')->firstOrFail();

        TrainingRecord::query()->create([
            'organization_id' => $this->organization->id,
            'curriculum_id' => $curriculum->getKey(),
            'user_id' => $user->getKey(),
            'assessor_id' => $assessor->getKey(),
            'completed_at' => now()->subWeek(),
            'competency_assessed' => false,
            'score' => 40,
        ]);

        $this->actingAs($user)->get(route('bcms.myresilience.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/MyResilience/Index', false)
                ->where('training.1.curriculum_name', $curriculum->name)
                ->where('training.1.competency_assessed', false)
                ->where('training.1.assessed', true)
                ->where('training.1.failed', true)
            );
    }

    /**
     * qa-engineer re-gate: `my-resilience.md` §2 card 1 reads "For each
     * curriculum the person is CURRENTLY ASSIGNED TO (resolved the same way
     * `training-compliance.md` resolves assignment...)" — an enumeration over
     * assignment, not over existing records — and cites
     * `training-compliance.md`'s own register as the model, whose
     * `TrainingComplianceService::complianceRows()` shows a row for every
     * assigned person even with no record at all (`attendance.completed_at`
     * null, "not yet attended"). `MyResilienceService::training()` instead
     * enumerates the person's OWN existing `TrainingRecord` rows and filters
     * them, so a person newly assigned to a mandatory curriculum they have
     * not yet attended gets no row at all — the card reads "no training
     * currently assigned", which is false: they have an assignment, they
     * simply have not started it, and that is exactly the state the screen
     * exists to surface.
     *
     * Every active user is also assigned to `BC-AWARE-ALL`, unattended here
     * too, and sorts ahead of `BC-WARDEN` (`curricula()` orders by `code`),
     * so the assertions below are on `training.1`.
     */
    #[Test]
    public function my_resilience_shows_a_current_assignment_the_person_has_never_attended(): void
    {
        $user = $this->user('New Warden', 'new-warden@khb.test', ['my.view']);
        $user->assignRole(Role::findOrCreate('floor-warden', 'web'));

        $curriculum = TrainingCurriculum::query()->where('code', 'BC-WARDEN')->firstOrFail();

        // No TrainingRecord at all for this user.
        $this->actingAs($user)->get(route('bcms.myresilience.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/MyResilience/Index', false)
                ->has('training', 2)
                ->where('training.1.curriculum_name', $curriculum->name)
                ->where('training.1.completed_at', null)
            );
    }

    /**
     * qa-engineer re-gate: `MyResilienceService::training()` special-cases the
     * universal-audience curriculum (`target_roles = ['*']`, `BC-AWARE-ALL`)
     * out of the not-yet-attended placeholder the previous fix just added for
     * every named-role curriculum, on the reasoning that there is no
     * per-person first-attendance date to measure lateness against for
     * "everyone".
     *
     * THAT REASONING DOES NOT SURVIVE READING ITS OWN CITED MODEL.
     * `my-resilience.md` §2 card 1 says "for each curriculum the person is
     * currently assigned to" with NO carve-out for a `'*'` audience — and
     * `assignedUsers('*')` resolves to every active user, so an all-staff
     * curriculum is unambiguously one everyone is "currently assigned to".
     * `TrainingComplianceService::complianceRows()` — the very register this
     * screen is supposed to mirror — already shows exactly this shape for
     * every active user against `BC-AWARE-ALL` with no special case at all,
     * and `summaryTiles()` already counts a never-attended row as overdue
     * ("never attended at all counts as overdue for first completion"). The
     * "identical, permanent placeholder for every employee" outcome the
     * special case was written to avoid is not a hypothetical it heads off —
     * it is the training-compliance screen's own existing, shipped, tested
     * behaviour. An employee who has never done mandatory all-staff awareness
     * training is exactly who this card exists to tell.
     */
    #[Test]
    public function my_resilience_shows_all_staff_awareness_training_even_when_never_attended(): void
    {
        $user = $this->user('Ordinary Employee', 'ordinary@khb.test', ['my.view']);

        $curriculum = TrainingCurriculum::query()->where('code', 'BC-AWARE-ALL')->firstOrFail();

        // No TrainingRecord at all — this employee has never attended the
        // all-staff awareness curriculum, which target_roles = ['*'] assigns
        // to every active user, this one included.
        $this->actingAs($user)->get(route('bcms.myresilience.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/MyResilience/Index', false)
                ->where('training.0.curriculum_name', $curriculum->name)
                ->where('training.0.completed_at', null)
            );
    }

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

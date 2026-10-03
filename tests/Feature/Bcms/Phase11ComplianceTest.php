<?php

namespace Tests\Feature\Bcms;

use App\Models\Bcms\Aar;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\CallTreeNode;
use App\Models\Bcms\Contact;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\Finding;
use App\Models\Bcms\ManagementReview;
use App\Models\Bcms\Programme;
use App\Models\Bcms\ProgrammeObligation;
use App\Models\KeyRiskIndicator;
use App\Models\MeasureBreach;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\CallTrees\TreeHealthService;
use App\Services\Bcms\Compliance\ClauseComplianceMatrixService;
use App\Services\Bcms\ProgrammeService;
use App\Services\Bcms\Reports\BoardPackPptxWriter;
use App\Services\Bcms\ResilienceKriPublisher;
use App\Support\Bcms\ResilienceKris;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * BCMS Phase 11 — compliance matrix, KRI adoption and the Phase 6
 * remediation, ADR 0021.
 */
class Phase11ComplianceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->admin = $this->user('Programme Admin', 'admin@khb.test', [
            'bcms.view', 'bcms.report.view', 'bcms.report.export', 'bcms.programme.manage',
            'bcms.programme.approve', 'bcms.finding.manage',
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ================================================================== */
    /*  1. Phase 6 remediation — mirrorKris() now records through the bridge */
    /* ================================================================== */

    #[Test]
    public function a_call_tree_kri_files_a_real_breach_not_just_a_measurement_row(): void
    {
        app(ResilienceKriPublisher::class)->adopt($this->admin->id);

        $contact = Contact::factory()->create(['is_active' => true]);
        $tree = CallTree::factory()->create(['status' => 'approved']);

        // A must-reach node with no deputy — BCMS-CT-DEPUTY-GAP's target is
        // zero, so one such node is already a breach.
        CallTreeNode::factory()->create([
            'call_tree_id' => $tree->getKey(),
            'contact_id' => $contact->getKey(),
            'is_must_reach' => true,
            'deputy_user_id' => null,
            'deputy_contact_id' => null,
        ]);

        $written = app(TreeHealthService::class)->mirrorKris();

        $this->assertContains('BCMS-CT-DEPUTY-GAP', $written);

        $kri = KeyRiskIndicator::query()->where('kri_code', 'BCMS-CT-DEPUTY-GAP')->firstOrFail();

        $this->assertSame(1.0, (float) $kri->current_value);
        $this->assertNotNull($kri->last_measurement_at);

        // The bridge, not a direct write: a measure and a breach exist, not
        // only the legacy mirror column.
        $measure = \App\Models\Measure::query()->where('code', 'BCMS-CT-DEPUTY-GAP')->first();
        $this->assertNotNull($measure, 'mirrorKris() must record through KriMeasureBridge, which defines a Measure for the KRI.');

        $breach = MeasureBreach::query()->where('measure_id', $measure->id)->first();
        $this->assertNotNull($breach, 'A KRI at its red threshold must open a breach, not merely file a measurement.');
    }

    #[Test]
    public function a_null_reading_is_skipped_and_never_published_as_zero(): void
    {
        app(ResilienceKriPublisher::class)->adopt($this->admin->id);

        // No call trees at all: BCMS-CT-COMPLETION has no test to average.
        $written = app(TreeHealthService::class)->mirrorKris();

        $this->assertNotContains('BCMS-CT-COMPLETION', $written);

        $kri = KeyRiskIndicator::query()->where('kri_code', 'BCMS-CT-COMPLETION')->first();
        $this->assertNotNull($kri, 'adopt() creates the definition regardless of whether it can be measured yet.');
        $this->assertNull($kri->current_value, 'No cascade test exists to average — this must stay unmeasured, not a fabricated zero.');
        $this->assertNull($kri->last_measurement_at);
    }

    /* ================================================================== */
    /*  2. KRI adoption — ADR 0021 §2 */
    /* ================================================================== */

    #[Test]
    public function adoption_creates_all_seventeen_definitions_and_is_idempotent(): void
    {
        $result = app(ResilienceKriPublisher::class)->adopt($this->admin->id);
        $this->assertSame(17, $result['created']);
        $this->assertSame(17, KeyRiskIndicator::query()->whereIn('kri_code', ResilienceKris::codes())->count());

        $second = app(ResilienceKriPublisher::class)->adopt($this->admin->id);
        $this->assertSame(0, $second['created']);
        $this->assertSame(17, $second['existing']);
    }

    #[Test]
    public function a_republish_never_overwrites_a_tenant_tuned_threshold(): void
    {
        app(ResilienceKriPublisher::class)->adopt($this->admin->id);

        $kri = KeyRiskIndicator::query()->where('kri_code', 'BCMS-EX-COMPLETION')->firstOrFail();
        $kri->forceFill(['green_threshold_min' => 99, 'kri_name' => 'Our own name'])->save();

        app(ResilienceKriPublisher::class)->adopt($this->admin->id);
        $kri->refresh();

        $this->assertSame(99.0, (float) $kri->green_threshold_min);
        $this->assertSame('Our own name', $kri->kri_name);
    }

    #[Test]
    public function status_reports_an_unlinked_code_plainly_rather_than_dropping_it(): void
    {
        $status = collect(app(ResilienceKriPublisher::class)->status());

        $this->assertCount(17, $status);
        $this->assertTrue($status->every(fn (array $s) => $s['linked'] === false));
    }

    /* ================================================================== */
    /*  3. The clause matrix — four states */
    /* ================================================================== */

    #[Test]
    public function with_no_programme_the_matrix_reports_itself_empty(): void
    {
        $built = app(ClauseComplianceMatrixService::class)->build();
        $this->assertTrue($built['empty_programme']);
    }

    #[Test]
    public function a_completed_occurrence_with_no_finalised_aar_is_an_honest_red(): void
    {
        // A4 (gate 1 code review #1): `$registerSeeded` now checks the
        // LATEST programme's own obligations, not any obligation for any
        // programme the tenant has ever loaded — so the obligation row must
        // actually belong to the programme `build()` will select.
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        ProgrammeObligation::factory()->create(['programme_id' => $programme->getKey(), 'clause_ref' => 'iso22301.9.2.programme', 'applies' => true]);

        // Code review #3, D2: aarSufficiency() bounds "completed" on
        // `actual_end`, not `status`, now — `actual_end` matches
        // `OccurrenceExecutionService::complete()`'s own write.
        ExerciseOccurrence::factory()->create(['status' => 'completed', 'scheduled_date' => now()->toDateString(), 'actual_end' => now()]);

        $built = app(ClauseComplianceMatrixService::class)->build();
        $row = $this->rowFor($built, 'iso22301.8.5.report');

        $this->assertSame('red', $row['state']);
        $this->assertStringContainsString('have no finalised AAR', $row['artefact']);
    }

    #[Test]
    public function a_finalised_aar_for_every_completed_occurrence_is_green(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        ProgrammeObligation::factory()->create([
            'programme_id' => $programme->getKey(), 'clause_ref' => 'iso22301.8.5.report', 'applies' => true,
        ]);

        $occurrence = ExerciseOccurrence::factory()->create(['status' => 'completed', 'scheduled_date' => now()->toDateString(), 'actual_end' => now()]);
        Aar::factory()->create(['occurrence_id' => $occurrence->getKey(), 'status' => 'final', 'approved_at' => now()]);

        $built = app(ClauseComplianceMatrixService::class)->build();
        $row = $this->rowFor($built, 'iso22301.8.5.report');

        $this->assertSame('green', $row['state']);
    }

    /**
     * Reviewer #1 of Phase 10: a post-incident review (a `bcms_aars` row with
     * `incident_id` set and `occurrence_id` null — ADR 0020) shares the same
     * table as an exercise AAR but is not an exercise evaluation. A finalised
     * PIR, with no exercise ever completed, must leave clause 8.5 exactly as
     * unevidenced as no AAR at all — never counted toward it.
     */
    #[Test]
    public function a_finalised_post_incident_review_alone_does_not_evidence_clause_8_5(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        ProgrammeObligation::factory()->create([
            'programme_id' => $programme->getKey(), 'clause_ref' => 'iso22301.8.5.report', 'applies' => true,
        ]);

        $incident = \App\Models\Bcms\Incident::factory()->create();
        Aar::factory()->create([
            'occurrence_id' => null, 'incident_id' => $incident->getKey(),
            'status' => 'final', 'approved_at' => now(),
        ]);

        $built = app(ClauseComplianceMatrixService::class)->build();
        $row = $this->rowFor($built, 'iso22301.8.5.report');

        $this->assertSame('red', $row['state']);
        $this->assertStringContainsString('No exercise occurrence has completed', $row['artefact']);
    }

    /**
     * B3 (gate 1 code review #1, confirmed by compliance-analyst against
     * `docs/bcms/screens/training-compliance.md` §3): `competenceSufficiency()`
     * (clause 7.2) must count only records scoring AT OR ABOVE the
     * curriculum's own pass mark, and only while still current. This is the
     * matrix-reading proof `Phase11TrainingTest::
     * an_assessed_record_is_green_while_attendance_only_is_amber` (a
     * different engineer's file, not editable here) only asserted by name —
     * it never actually read the matrix state.
     */
    #[Test]
    public function a_below_pass_mark_assessed_record_does_not_evidence_clause_7_2(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        ProgrammeObligation::factory()->create([
            'programme_id' => $programme->getKey(), 'clause_ref' => 'iso22301.7.2', 'applies' => true,
        ]);

        $curriculum = \App\Models\Bcms\TrainingCurriculum::query()->where('code', 'BC-WARDEN')->firstOrFail();
        $this->assertSame(80, $curriculum->pass_mark, 'The fixture only proves the point if the pass mark is really 80.');

        // Assigned to the curriculum's own target role — R4: coverage is
        // measured against TrainingComplianceService::assignedUsers(), a
        // record for someone who does not even hold the role would prove
        // nothing about coverage.
        $subject = $this->user('Failed Warden', 'failed-warden@khb.test', []);
        $subject->assignRole(Role::findOrCreate('floor-warden', 'web'));

        // The write side's own current shape (competency_assessed = passed):
        // a failed assessment carries a real score and assessor but
        // competency_assessed = false.
        \App\Models\Bcms\TrainingRecord::query()->create([
            'organization_id' => $this->organization->id,
            'curriculum_id' => $curriculum->getKey(), 'user_id' => $subject->getKey(),
            'completed_at' => now(), 'score' => 60, 'competency_assessed' => false,
            'assessor_id' => $this->user('Assessor', 'assessor-7-2@khb.test', [])->getKey(),
            'next_due_date' => now()->addMonths(12)->toDateString(),
        ]);

        $built = app(ClauseComplianceMatrixService::class)->build();
        $row = $this->rowFor($built, 'iso22301.7.2');

        $this->assertSame('red', $row['state'], 'A record scoring below its curriculum\'s pass mark must not count toward clause 7.2, however the write side flagged competency_assessed.');
        // R4: a FAILED assessment must be named as failed, never blended
        // into "not yet assessed".
        $this->assertStringContainsString('1 failed assessment', $row['artefact']);
        $this->assertStringNotContainsString('not yet assessed', $row['artefact']);
    }

    /**
     * The positive counterpart: a CURRENT, PASSING record turns clause 7.2
     * green; an EXPIRED one (past its own `next_due_date`, as of the
     * matrix's own period end) does not count even though it passed at the
     * time.
     */
    #[Test]
    public function a_current_passing_record_evidences_clause_7_2_but_an_expired_one_does_not(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        ProgrammeObligation::factory()->create([
            'programme_id' => $programme->getKey(), 'clause_ref' => 'iso22301.7.2', 'applies' => true,
        ]);

        $curriculum = \App\Models\Bcms\TrainingCurriculum::query()->where('code', 'BC-WARDEN')->firstOrFail();

        $expiredSubject = $this->user('Expired Warden', 'expired-warden@khb.test', []);
        $expiredSubject->assignRole(Role::findOrCreate('floor-warden', 'web'));
        \App\Models\Bcms\TrainingRecord::query()->create([
            'organization_id' => $this->organization->id,
            'curriculum_id' => $curriculum->getKey(), 'user_id' => $expiredSubject->getKey(),
            'completed_at' => now()->subMonths(14), 'score' => 95, 'competency_assessed' => true,
            'next_due_date' => now()->subMonths(2)->toDateString(),
        ]);

        $expiredOnly = app(ClauseComplianceMatrixService::class)->build();
        $this->assertSame('red', $this->rowFor($expiredOnly, 'iso22301.7.2')['state'], 'An expired record must not evidence 7.2 on its own.');

        $currentSubject = $this->user('Current Warden', 'current-warden@khb.test', []);
        $currentSubject->assignRole(Role::findOrCreate('floor-warden', 'web'));
        \App\Models\Bcms\TrainingRecord::query()->create([
            'organization_id' => $this->organization->id,
            'curriculum_id' => $curriculum->getKey(), 'user_id' => $currentSubject->getKey(),
            'completed_at' => now(), 'score' => 95, 'competency_assessed' => true,
            'next_due_date' => now()->addMonths(12)->toDateString(),
        ]);

        // Two assigned, one current — amber, not green: R4's own coverage
        // rule, exercised here rather than only in its dedicated test below.
        $withCurrent = app(ClauseComplianceMatrixService::class)->build();
        $this->assertSame('amber', $this->rowFor($withCurrent, 'iso22301.7.2')['state']);
    }

    /**
     * R4 (gate 1 code review #2, spec §3.2 row 5): "every role in a
     * mandatory curriculum holds a current, assessed record" is a COVERAGE
     * rule, not an existence check. One current, passed record out of three
     * assigned people must not be green.
     */
    #[Test]
    public function one_passed_of_three_assigned_is_not_green(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        ProgrammeObligation::factory()->create([
            'programme_id' => $programme->getKey(), 'clause_ref' => 'iso22301.7.2', 'applies' => true,
        ]);

        $curriculum = \App\Models\Bcms\TrainingCurriculum::query()->where('code', 'BC-WARDEN')->firstOrFail();
        $role = Role::findOrCreate('floor-warden', 'web');

        $passed = $this->user('Passed Warden', 'passed-warden@khb.test', []);
        $passed->assignRole($role);
        \App\Models\Bcms\TrainingRecord::query()->create([
            'organization_id' => $this->organization->id,
            'curriculum_id' => $curriculum->getKey(), 'user_id' => $passed->getKey(),
            'completed_at' => now(), 'score' => 95, 'competency_assessed' => true,
            'next_due_date' => now()->addMonths(12)->toDateString(),
        ]);

        // Two more assigned, with no record at all.
        $this->user('Never Assessed Warden A', 'never-a@khb.test', [])->assignRole($role);
        $this->user('Never Assessed Warden B', 'never-b@khb.test', [])->assignRole($role);

        $built = app(ClauseComplianceMatrixService::class)->build();
        $row = $this->rowFor($built, 'iso22301.7.2');

        $this->assertSame('amber', $row['state'], 'One of three assigned must not be green.');
        $this->assertStringContainsString('1 of 3', $row['artefact']);
    }

    /**
     * R4's own second test: a failed assessment is reported AS FAILED, not
     * blended into "not yet assessed" — the same fact
     * `a_below_pass_mark_assessed_record_does_not_evidence_clause_7_2`
     * checks, restated with three assigned people so the wording is
     * checked against a genuinely mixed population.
     */
    #[Test]
    public function a_failed_assessment_is_reported_as_failed_not_as_unassessed(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        ProgrammeObligation::factory()->create([
            'programme_id' => $programme->getKey(), 'clause_ref' => 'iso22301.7.2', 'applies' => true,
        ]);

        $curriculum = \App\Models\Bcms\TrainingCurriculum::query()->where('code', 'BC-WARDEN')->firstOrFail();
        $role = Role::findOrCreate('floor-warden', 'web');
        $assessor = $this->user('Assessor', 'assessor-mix@khb.test', []);

        $failed = $this->user('Failed Warden', 'failed-mix@khb.test', []);
        $failed->assignRole($role);
        \App\Models\Bcms\TrainingRecord::query()->create([
            'organization_id' => $this->organization->id,
            'curriculum_id' => $curriculum->getKey(), 'user_id' => $failed->getKey(),
            'completed_at' => now(), 'score' => 50, 'competency_assessed' => false,
            'assessor_id' => $assessor->getKey(), 'next_due_date' => now()->addMonths(12)->toDateString(),
        ]);

        // One genuinely never assessed at all — no record.
        $this->user('Unassessed Warden', 'unassessed-mix@khb.test', [])->assignRole($role);

        $built = app(ClauseComplianceMatrixService::class)->build();
        $row = $this->rowFor($built, 'iso22301.7.2');

        $this->assertSame('red', $row['state']);
        $this->assertStringContainsString('1 failed assessment', $row['artefact']);
        $this->assertStringContainsString('1 not yet assessed', $row['artefact']);
    }

    /**
     * B17 (gate 1 code review #1): ADR 0020 §4 requires `is_exercise =
     * false` "in every aggregate". A crisis-simulation (exercise) incident
     * alone must leave `iso22320.incident_response`, `iso22361.crisis` and
     * the CBN crosswalk row they answer non-green.
     */
    #[Test]
    public function an_exercise_incident_alone_leaves_incident_response_crisis_and_cbn_incident_non_green(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        foreach (['iso22320.incident_response', 'iso22361.crisis_management', 'cbn.rcf.incident_response'] as $ref) {
            ProgrammeObligation::factory()->create([
                'programme_id' => $programme->getKey(), 'clause_ref' => $ref, 'applies' => true,
            ]);
        }

        \App\Models\Bcms\Incident::factory()->create(['is_exercise' => true, 'declared_at' => now()]);

        $built = app(ClauseComplianceMatrixService::class)->build();

        $this->assertSame('red', $this->rowFor($built, 'iso22320.incident_response')['state']);
        $this->assertSame('red', $this->rowFor($built, 'iso22361.crisis_management')['state']);
        $this->assertNotSame('green', $this->rowFor($built, 'cbn.rcf.incident_response')['state'], 'A CBN regulatory row must never go green on a drill.');
    }

    /* ================================================================== */
    /*  ISO 22301 8.6 — the compliance ruling "PIRs and clause 8.6" */
    /* ================================================================== */

    #[Test]
    public function clause_8_6_is_red_with_no_active_tier_1_process(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        ProgrammeObligation::factory()->create([
            'programme_id' => $programme->getKey(), 'clause_ref' => 'iso22301.8.6', 'applies' => true,
        ]);

        $built = app(ClauseComplianceMatrixService::class)->build();
        $row = $this->rowFor($built, 'iso22301.8.6');

        $this->assertSame('red', $row['state']);
        $this->assertStringContainsString('No active Tier-1 process', $row['artefact']);
    }

    #[Test]
    public function clause_8_6_is_red_when_a_tier_1_process_has_never_been_drilled_above_a_walkthrough(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        ProgrammeObligation::factory()->create([
            'programme_id' => $programme->getKey(), 'clause_ref' => 'iso22301.8.6', 'applies' => true,
        ]);

        $this->tier1ProcessRunAt('BCP-WALK', 'WALKTHRU', now());

        $built = app(ClauseComplianceMatrixService::class)->build();
        $row = $this->rowFor($built, 'iso22301.8.6');

        $this->assertSame('red', $row['state']);
        $this->assertStringContainsString('never been exercised above a walkthrough', $row['artefact']);
    }

    #[Test]
    public function clause_8_6_is_red_when_a_tier_1_process_is_stale(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        ProgrammeObligation::factory()->create([
            'programme_id' => $programme->getKey(), 'clause_ref' => 'iso22301.8.6', 'applies' => true,
        ]);

        // Proven at Drill level 20 months ago — past LadderAdvisor::TIER1_STALE_MONTHS (18).
        $this->tier1ProcessRunAt('BCP-STALE', 'CALLTREE', now()->subMonths(20));

        $built = app(ClauseComplianceMatrixService::class)->build();
        $row = $this->rowFor($built, 'iso22301.8.6');

        $this->assertSame('red', $row['state']);
        $this->assertStringContainsString('has not been exercised above a walkthrough since', $row['artefact']);
    }

    #[Test]
    public function clause_8_6_is_amber_when_coverage_holds_but_a_closed_real_incident_has_no_final_pir(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        ProgrammeObligation::factory()->create([
            'programme_id' => $programme->getKey(), 'clause_ref' => 'iso22301.8.6', 'applies' => true,
        ]);

        $this->tier1ProcessRunAt('BCP-OK', 'CALLTREE', now());

        \App\Models\Bcms\Incident::factory()->create([
            'is_exercise' => false, 'status' => 'closed', 'reference' => 'INC-NOPIR',
        ]);

        $built = app(ClauseComplianceMatrixService::class)->build();
        $row = $this->rowFor($built, 'iso22301.8.6');

        $this->assertSame('amber', $row['state']);
        $this->assertStringContainsString('INC-NOPIR', $row['artefact']);
        $this->assertNull($row['last_evidenced'], 'A PIR gap must never carry a PIR\'s own date as last_evidenced.');
    }

    #[Test]
    public function clause_8_6_is_green_when_coverage_holds_and_every_closed_real_incident_has_a_final_pir(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        ProgrammeObligation::factory()->create([
            'programme_id' => $programme->getKey(), 'clause_ref' => 'iso22301.8.6', 'applies' => true,
        ]);

        $this->tier1ProcessRunAt('BCP-GREEN', 'CALLTREE', now());

        $incident = \App\Models\Bcms\Incident::factory()->create(['is_exercise' => false, 'status' => 'closed']);
        Aar::factory()->create([
            'occurrence_id' => null, 'incident_id' => $incident->getKey(), 'status' => 'final', 'approved_at' => now(),
        ]);

        $built = app(ClauseComplianceMatrixService::class)->build();
        $row = $this->rowFor($built, 'iso22301.8.6');

        $this->assertSame('green', $row['state']);
    }

    #[Test]
    public function clause_8_6_a_lone_pir_never_makes_it_green_and_an_exercise_incident_never_triggers_the_amber(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        ProgrammeObligation::factory()->create([
            'programme_id' => $programme->getKey(), 'clause_ref' => 'iso22301.8.6', 'applies' => true,
        ]);

        // No Tier-1 coverage at all, but a lone finalised PIR — a PIR can
        // hold 8.6 at amber but can never LIFT it; with no coverage this
        // must stay red, never green.
        $incident = \App\Models\Bcms\Incident::factory()->create(['is_exercise' => false, 'status' => 'closed']);
        Aar::factory()->create([
            'occurrence_id' => null, 'incident_id' => $incident->getKey(), 'status' => 'final', 'approved_at' => now(),
        ]);

        $lonePir = app(ClauseComplianceMatrixService::class)->build();
        $this->assertSame('red', $this->rowFor($lonePir, 'iso22301.8.6')['state'], 'A lone PIR with no Tier-1 coverage must never be green.');

        // Now give coverage, and add a CLOSED EXERCISE incident (a drill's
        // own crisis-simulation record) with no PIR — ADR 0020 §4: it must
        // never trigger the amber, because it is not a real incident.
        $this->tier1ProcessRunAt('BCP-EX', 'CALLTREE', now());
        \App\Models\Bcms\Incident::factory()->create(['is_exercise' => true, 'status' => 'closed']);

        $withExerciseIncident = app(ClauseComplianceMatrixService::class)->build();
        $this->assertSame('green', $this->rowFor($withExerciseIncident, 'iso22301.8.6')['state'], 'An exercise incident must never trigger the amber.');
    }

    /**
     * A Tier-1 (`criticality_tier = 1`, `status = active`) process with a
     * single completed, successful exercise occurrence of the given
     * `$exerciseTypeCode` (an `ExerciseType` seeded by `BcmsReferenceSeeder`),
     * run at `$ranAt`.
     */
    private function tier1ProcessRunAt(string $processCode, string $exerciseTypeCode, \Illuminate\Support\Carbon $ranAt): \App\Models\Bcms\Process
    {
        $process = \App\Models\Bcms\Process::query()->create([
            'code' => $processCode, 'name' => 'Process '.$processCode, 'status' => 'active', 'criticality_tier' => 1,
        ]);

        $type = \App\Models\Bcms\ExerciseType::query()->where('code', $exerciseTypeCode)->sole();
        $definition = \App\Models\Bcms\ExerciseDefinition::factory()->create([
            'exercise_type_id' => $type->getKey(), 'process_ids' => [$process->getKey()],
        ]);

        \App\Models\Bcms\ExerciseOccurrence::factory()->create([
            'definition_id' => $definition->getKey(), 'status' => 'completed',
            'outcome' => \App\Enums\Bcms\ExerciseOutcome::Pass->value,
            'actual_end' => $ranAt, 'scheduled_date' => $ranAt->toDateString(),
        ]);

        return $process;
    }

    #[Test]
    public function a_not_applicable_obligation_renders_grey_with_its_rationale(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        // Seed the register so it counts as "loaded" — every other clause's
        // obligation defaults to applies=true via the factory below.
        foreach (\App\Enums\Bcms\IsoClauseRef::cases() as $ref) {
            ProgrammeObligation::factory()->create([
                'programme_id' => $programme->getKey(), 'clause_ref' => $ref->value, 'applies' => true,
            ]);
        }

        ProgrammeObligation::query()
            ->where('programme_id', $programme->getKey())
            ->where('clause_ref', 'cbn.open_banking.threshold')
            ->update(['applies' => false, 'applicability_note' => 'No Open Banking licence.']);

        $built = app(ClauseComplianceMatrixService::class)->build();
        $row = $this->rowFor($built, 'cbn.open_banking.threshold');

        $this->assertSame('grey', $row['state']);
        $this->assertStringContainsString('No Open Banking licence.', $row['artefact']);
    }

    #[Test]
    public function clause_9_2_is_never_grey_even_when_marked_not_applicable(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        ProgrammeObligation::factory()->create([
            'programme_id' => $programme->getKey(), 'clause_ref' => 'iso22301.9.2.programme', 'applies' => false,
            'applicability_note' => 'Attempted not-applicable.',
        ]);

        $built = app(ClauseComplianceMatrixService::class)->build();
        $row = $this->rowFor($built, 'iso22301.9.2.programme');

        // Both directly asserted: 9.2 must not read "grey" under any
        // circumstance, and the actual computed state here is the honest red
        // (no audit programme is held), not the not-applicable rationale
        // just recorded against it.
        $this->assertNotSame('grey', $row['state']);
        $this->assertSame('red', $row['state']);
        $this->assertStringContainsString('No internal audit programme is held in this system', $row['artefact']);
    }

    #[Test]
    public function clause_9_2_turns_amber_once_a_review_carries_the_internal_audit_block(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $review = ManagementReview::factory()->create(['status' => 'draft']);
        app(ProgrammeService::class)->captureReviewInputs($review, [
            'internal_audit' => ['report_reference' => 'IA-2027-04', 'conclusion' => 'Satisfactory'],
        ]);
        app(ProgrammeService::class)->approveManagementReview($review, $this->admin->id);

        $built = app(ClauseComplianceMatrixService::class)->build();
        $row = $this->rowFor($built, 'iso22301.9.2.programme');

        $this->assertSame('amber', $row['state']);
        $this->assertStringContainsString($review->title, $row['artefact']);
    }

    #[Test]
    public function the_mandatory_gap_count_includes_both_red_and_amber_mandatory_rows(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $built = app(ClauseComplianceMatrixService::class)->build();

        // With nothing else on file, 9.2 programme/results are both red —
        // two mandatory gaps at minimum.
        $this->assertGreaterThanOrEqual(2, $built['summary']['mandatory_gap_count']);
    }

    /**
     * B10 (gate 1 code review #1): `nonconformitySufficiency()` ran one
     * `correctiveActions()->count()` query PER nonconformity, and — before
     * `sufficiency()`'s own ref-level cache plus the method-level `memo()`
     * — the whole method ran TWICE per `build()` (two `IsoClauseRef` cases
     * share it: 10.1.nonconformity and 10.1.corrective). `build()`'s own
     * query count must not grow with the number of nonconformities on file.
     */
    #[Test]
    public function build_does_not_n_plus_one_on_the_number_of_nonconformities(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        foreach (\App\Enums\Bcms\IsoClauseRef::cases() as $ref) {
            ProgrammeObligation::factory()->create([
                'programme_id' => $programme->getKey(), 'clause_ref' => $ref->value, 'applies' => true,
            ]);
        }

        foreach (range(1, 3) as $i) {
            $finding = Finding::factory()->create(['classification' => 'nonconformity', 'status' => 'open']);
            \App\Models\Bcms\CorrectiveAction::factory()->create(['finding_id' => $finding->getKey()]);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        app(ClauseComplianceMatrixService::class)->build();
        $queriesAtThree = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        foreach (range(1, 17) as $i) {
            $finding = Finding::factory()->create(['classification' => 'nonconformity', 'status' => 'open']);
            \App\Models\Bcms\CorrectiveAction::factory()->create(['finding_id' => $finding->getKey()]);
        }

        // The fixture rows above must not be counted — only build() itself.
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        app(ClauseComplianceMatrixService::class)->build();
        $queriesAtTwenty = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertSame(
            $queriesAtThree,
            $queriesAtTwenty,
            'build() must run the same number of queries at 3 nonconformities as at 20 — no per-row lazy-load, no repeated recomputation.',
        );
    }

    /* ================================================================== */
    /*  4. captureReviewInputs() extension — phase-11-spec §2.4 */
    /* ================================================================== */

    #[Test]
    public function recapturing_without_manual_fields_preserves_the_internal_audit_block(): void
    {
        $review = ManagementReview::factory()->create(['status' => 'draft']);

        app(ProgrammeService::class)->captureReviewInputs($review, [
            'internal_audit' => ['report_reference' => 'IA-2027-01', 'conclusion' => 'Satisfactory'],
        ]);

        // Re-capture with nothing passed — the computed sections refresh,
        // the manual one must survive.
        $review->refresh();
        $recaptured = app(ProgrammeService::class)->captureReviewInputs($review);

        $this->assertSame('IA-2027-01', $recaptured->inputs['internal_audit']['report_reference']);
    }

    #[Test]
    public function the_extended_snapshot_carries_every_new_section(): void
    {
        Finding::factory()->create(['classification' => 'nonconformity', 'status' => 'open']);

        $review = ManagementReview::factory()->create(['status' => 'draft']);
        $captured = app(ProgrammeService::class)->captureReviewInputs($review);

        foreach ([
            'previous_review_actions', 'internal_audit', 'incidents', 'exercise_evaluation_outputs',
            'call_tree_and_emns_performance', 'dr_achievement', 'supplier_continuity',
            'interested_party_feedback', 'bia_and_risk_changes', 'context_changes',
            'improvement_opportunities',
        ] as $key) {
            $this->assertArrayHasKey($key, $captured->inputs, "Missing snapshot key: {$key}");
        }
    }

    /* ================================================================== */
    /*  5. The board pack PPTX writer produces a valid, openable archive */
    /* ================================================================== */

    #[Test]
    public function the_pptx_writer_produces_a_valid_zip_with_the_required_parts(): void
    {
        $bytes = app(BoardPackPptxWriter::class)->write('Test pack', [
            ['title' => 'Section one', 'lines' => ['A line of text.']],
            ['title' => 'Section two', 'lines' => []],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pptx-test-');
        file_put_contents($path, $bytes);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertNotFalse($zip->locateName('ppt/presentation.xml'));
        $this->assertNotFalse($zip->locateName('ppt/slides/slide1.xml'));
        $this->assertNotFalse($zip->locateName('ppt/slides/slide2.xml'));
        $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
        $zip->close();
        unlink($path);
    }

    /**
     * Code review #4, E2: `bestDateColumn()` used to return `null` for an
     * unmapped model, and `existsCheck()` then silently applied NO bound
     * and NO label — fail loud instead, naming the model, so a new
     * `existsCheck()` caller added later for a model nobody has reviewed a
     * date column for cannot slip through unbounded and unlabelled.
     * `ManagementReview` is a real model in this app, deliberately NOT one
     * of the 9 `DATE_COLUMN_BY_MODEL` entries (nothing in this file passes
     * it to `existsCheck()`).
     */
    #[Test]
    public function best_date_column_throws_for_a_model_with_no_reviewed_entry(): void
    {
        $service = app(ClauseComplianceMatrixService::class);

        $reflection = new ReflectionMethod($service, 'bestDateColumn');
        $reflection->setAccessible(true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/ManagementReview/');

        $reflection->invoke($service, new ManagementReview);
    }

    /* ================================================================== */

    /** @param array<string, mixed> $built */
    private function rowFor(array $built, string $code): array
    {
        foreach ($built['sections'] as $section) {
            foreach ($section as $row) {
                if ($row['code'] === $code) {
                    return $row;
                }
            }
        }

        $this->fail("No row found for clause {$code}");
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

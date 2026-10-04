<?php

namespace Tests\Feature\Bcms;

use App\Console\Commands\AdoptBcmsResilienceKris;
use App\Console\Commands\LinkBcmsTrainingFromOccurrences;
use App\Models\Bcms\AuditLog;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseParticipant;
use App\Models\Bcms\Finding;
use App\Models\Bcms\Incident;
use App\Models\Bcms\IncidentNotification;
use App\Models\Bcms\ManagementReview;
use App\Models\Bcms\Plan;
use App\Models\Bcms\Programme;
use App\Models\Bcms\TrainingCurriculum;
use App\Models\Bcms\TrainingRecord;
use App\Models\KeyRiskIndicator;
use App\Models\Organization;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Bcms\Compliance\ClauseComplianceMatrixService;
use App\Services\Bcms\Compliance\GapAnalyserService;
use App\Services\Bcms\ProgrammeService;
use App\Services\Bcms\Reports\BoardPackService;
use App\Services\Bcms\Reports\CsatPrefillService;
use App\Services\Bcms\Reports\EvidencePackService;
use App\Support\Bcms\ResilienceKris;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Gate coverage for Phase 11 surfaces that were built but left unexercised by
 * the implementer's own test files: PDF generation for both packs (named
 * "verify at integration" in phase-11-notes.md but never actually rendered
 * end to end by a test), the CSAT pre-fill's real PhpSpreadsheet round trip,
 * the AI gap analyser's citation shape, and the two Phase 11 console
 * commands, one of which is scheduled and had never once been invoked by a
 * test.
 */
class Phase11ReportingGateTest extends TestCase
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
    /*  PDF generation — the frontend found both packs 500'd before its */
    /*  layout-variable fix; nothing had rendered either end to end. */
    /* ================================================================== */

    #[Test]
    public function the_evidence_pack_pdf_renders_and_logs_a_pack_exported_audit_entry(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $bytes = app(EvidencePackService::class)->generate(
            'iso22301', now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString(), $this->admin,
        );

        $this->assertStringStartsWith('%PDF', $bytes, 'The evidence pack must render a real PDF, not 500.');

        $this->assertDatabaseHas('bcms_audit_logs', [
            'organization_id' => $this->organization->id,
            'auditable_type' => 'bcms_report_pack',
            'event' => 'pack.exported',
            'actor_id' => $this->admin->getKey(),
        ]);

        $log = AuditLog::query()->where('event', 'pack.exported')->firstOrFail();
        $this->assertSame('iso22301', $log->after['framework']);
    }

    #[Test]
    public function the_board_pack_pdf_renders_with_the_required_layout_variables_present(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $bytes = app(BoardPackService::class)->generatePdf(now()->year, $this->admin);

        $this->assertStringStartsWith('%PDF', $bytes, 'The board pack must render a real PDF, not 500.');

        $this->assertDatabaseHas('bcms_audit_logs', [
            'organization_id' => $this->organization->id,
            'auditable_type' => 'bcms_report_pack',
            'event' => 'pack.exported',
        ]);
    }

    #[Test]
    public function the_board_pack_pptx_generates_through_the_service_and_logs_its_own_export(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $bytes = app(BoardPackService::class)->generatePptx(now()->year, $this->admin);

        $path = tempnam(sys_get_temp_dir(), 'pptx-service-');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertNotFalse($zip->locateName('ppt/presentation.xml'));
        $zip->close();
        unlink($path);

        $this->assertDatabaseHas('bcms_audit_logs', [
            'organization_id' => $this->organization->id,
            'event' => 'pack.exported',
        ]);
        $log = AuditLog::query()->where('event', 'pack.exported')->firstOrFail();
        $this->assertSame('pptx', $log->after['format']);
    }

    /**
     * A8 (gate 1 code review #1): `current_value ?? 'not linked'` conflated
     * two different states — a KRI that IS adopted but has no measurement
     * yet is "not yet measured", not "not linked". An adopted-but-unmeasured
     * KRI must never read "not linked" in the PPTX.
     */
    #[Test]
    public function the_pptx_kri_slide_distinguishes_not_linked_from_linked_but_unmeasured(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        app(\App\Services\Bcms\ResilienceKriPublisher::class)->adopt();

        $pptx = app(BoardPackService::class)->generatePptx(now()->year, $this->admin);
        $zip = new \ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'pptx-kri-');
        file_put_contents($path, $pptx);
        $zip->open($path);
        // Slide order: 1 posture, 2 maturity, 3 exercise completion, 4 plan
        // currency, 5 top RTO gaps, 6 open nonconformities, 7 incident
        // summary, 8 resilience KRI dashboard, 9 management review.
        $kriSlide = $zip->getFromName('ppt/slides/slide8.xml');
        $zip->close();
        unlink($path);

        $this->assertStringContainsString('not yet measured', $kriSlide, 'An adopted-but-unmeasured KRI must read "not yet measured", not "not linked".');
    }

    /**
     * Live-browser follow-up to B2: the on-screen preview must carry an
     * `as_at` timestamp — the header, the PDF subtitle and the PPTX both
     * need it to state honestly which sections are live figures.
     *
     * A2 (gate 1 code review #2): `as_at` moved from `preview()` itself
     * (the reproducibility-compared content, exercised directly by the two
     * tests below) to `stampedPreview()` — the wrapper every caller that
     * renders to a person, rather than compares content, actually calls.
     */
    #[Test]
    public function the_board_pack_preview_carries_an_as_at_timestamp_for_its_live_sections(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $preview = app(BoardPackService::class)->stampedPreview(now()->year);

        $this->assertArrayHasKey('as_at', $preview);
        // Carbon::parse() throws on anything that is not a real timestamp —
        // a genuine parse failure fails this test just as well as an
        // assertion would.
        \Illuminate\Support\Carbon::parse($preview['as_at']);
    }

    /**
     * A2's own point: `preview()` — the array the reproducibility test
     * below compares with `===` — must never carry `as_at` at all, so a
     * future re-introduction of it inside `preview()` cannot silently make
     * that comparison flaky again.
     */
    #[Test]
    public function preview_itself_never_carries_an_as_at_key(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $preview = app(BoardPackService::class)->preview(now()->year);

        $this->assertArrayNotHasKey('as_at', $preview);
    }

    /**
     * Live-browser follow-up to B2: "1 nonconformity(ies) remain open" was
     * grammatically wrong for the single-digit case. One open nonconformity
     * reads "1 nonconformity remains open."; two read "2 nonconformities
     * remain open."
     */
    #[Test]
    public function the_posture_summary_pluralises_the_open_nonconformity_count_correctly(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        Finding::factory()->create(['classification' => 'nonconformity', 'status' => 'open']);

        $one = app(BoardPackService::class)->preview(now()->year);
        $this->assertStringContainsString('1 nonconformity remains open.', implode(' ', $one['posture_summary']['sentences']));

        Finding::factory()->create(['classification' => 'nonconformity', 'status' => 'open']);

        $two = app(BoardPackService::class)->preview(now()->year);
        $this->assertStringContainsString('2 nonconformities remain open.', implode(' ', $two['posture_summary']['sentences']));
    }

    /**
     * Live-browser follow-up to B2: "shortfall none recorded hours" read as
     * a stray unit on an undefined figure; "1 hour" must not read "1 hours".
     */
    #[Test]
    public function the_top_rto_gaps_shortfall_is_worded_and_pluralised_honestly(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        // A Tier-1 process with an approved BIA requiring a 2-hour RTO and a
        // selected strategy only achieving 1 hour slower — a genuine 1-hour
        // shortfall (singular), and a second process with NO strategy at
        // all (an undefined shortfall — "no shortfall recorded", not "none
        // recorded hours").
        $slow = \App\Models\Bcms\Process::factory()->create(['status' => 'active', 'criticality_tier' => 1]);
        \App\Models\Bcms\BiaAssessment::factory()->create([
            'process_id' => $slow->getKey(), 'status' => 'approved', 'rto_hours' => 2, 'approved_at' => now(),
        ]);
        \App\Models\Bcms\Strategy::factory()->create([
            'process_id' => $slow->getKey(), 'is_selected' => true, 'rto_achievable_hours' => 3,
        ]);

        $unprotected = \App\Models\Bcms\Process::factory()->create(['status' => 'active', 'criticality_tier' => 1]);
        \App\Models\Bcms\BiaAssessment::factory()->create([
            'process_id' => $unprotected->getKey(), 'status' => 'approved', 'rto_hours' => 4, 'approved_at' => now(),
        ]);

        $preview = app(BoardPackService::class)->preview(now()->year);
        $gaps = collect($preview['top_rto_gaps'])->keyBy('process_id');

        $this->assertSame(1.0, $gaps->get($slow->getKey())['shortfall_hours']);
        $this->assertNull($gaps->get($unprotected->getKey())['shortfall_hours'], 'No selected strategy at all is an undefined shortfall, not zero.');

        $pptx = app(BoardPackService::class)->generatePptx(now()->year, $this->admin);
        $zip = new \ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'pptx-shortfall-');
        file_put_contents($path, $pptx);
        $zip->open($path);
        // Slide order matches BoardPackService::generatePptx()'s own array:
        // 1 posture, 2 maturity, 3 exercise completion, 4 plan currency,
        // 5 top RTO gaps.
        $slide5 = $zip->getFromName('ppt/slides/slide5.xml');
        $zip->close();
        unlink($path);

        $this->assertStringContainsString('shortfall 1 hour', $slide5, 'A one-hour shortfall must not read "1 hours".');
    }

    /**
     * B15 (gate 1 code review #1): criterion 2's own examiner test — "show
     * me your last DR test report and the corrective actions arising" —
     * must actually be in the pack, with the finding's full
     * corrective-action chain, reached through `dr_test_id` and never a
     * second lookup path.
     */
    #[Test]
    public function the_evidence_pack_carries_the_last_dr_test_report_and_its_corrective_action_chain(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $system = \App\Models\Bcms\DrSystem::factory()->create(['name' => 'Core Banking DR']);
        $test = \App\Models\Bcms\DrTest::factory()->create([
            'dr_system_id' => $system->getKey(), 'test_type' => 'failback',
            'test_date' => now()->subDays(10), 'met_objectives' => false,
        ]);
        $finding = Finding::factory()->create([
            'classification' => 'nonconformity', 'status' => 'open', 'dr_test_id' => $test->getKey(),
        ]);
        \App\Models\Bcms\CorrectiveAction::factory()->create([
            'finding_id' => $finding->getKey(), 'title' => 'Fix the failback runbook',
        ]);

        $report = app(EvidencePackService::class)->drTestReport(now());

        $this->assertNotNull($report);
        $this->assertSame('failback', $report['test_type']);
        $this->assertSame($finding->reference, $report['finding']['reference']);
        $this->assertCount(1, $report['corrective_actions']);
        $this->assertSame('Fix the failback runbook', $report['corrective_actions'][0]['title']);

        // The actual PDF must carry it too, not only the data method.
        $bytes = app(EvidencePackService::class)->generate(
            'iso22301', now()->subDays(30)->toDateString(), now()->toDateString(), $this->admin,
        );

        $this->assertStringStartsWith('%PDF', $bytes);
    }

    /**
     * Code review #3, D1: `drTestReport()` is criterion 2's own examiner
     * content ("show me your last DR test report and the corrective
     * actions arising") — only `age_days` was ever bounded to `$asOf`; the
     * finding lookup and the corrective-action set both still moved on a
     * later write.
     */
    #[Test]
    public function dr_test_report_does_not_move_for_a_past_period_when_a_corrective_action_is_added_or_closed_after_it(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $pastAsOf = now()->subYears(2)->endOfYear();

        $system = \App\Models\Bcms\DrSystem::factory()->create(['name' => 'Core Banking DR']);
        $test = \App\Models\Bcms\DrTest::factory()->create([
            'dr_system_id' => $system->getKey(), 'test_type' => 'failback',
            'test_date' => $pastAsOf->copy()->subDays(10), 'met_objectives' => false,
        ]);
        $finding = Finding::factory()->create([
            'classification' => 'nonconformity', 'status' => 'open', 'dr_test_id' => $test->getKey(),
            'raised_at' => $pastAsOf->copy()->subDays(9),
        ]);
        $existingOpenAction = \App\Models\Bcms\CorrectiveAction::factory()->create([
            'finding_id' => $finding->getKey(), 'title' => 'Fix the failback runbook',
            'due_date' => $pastAsOf->copy()->addDays(5)->toDateString(),
            'created_at' => $pastAsOf->copy()->subDays(8),
        ]);

        $firstPastReport = app(EvidencePackService::class)->drTestReport($pastAsOf->copy());
        $firstLiveReport = app(EvidencePackService::class)->drTestReport(now());

        // An action added TODAY — strictly after the past pack's own `$to`.
        \App\Models\Bcms\CorrectiveAction::factory()->create([
            'finding_id' => $finding->getKey(), 'title' => 'A newly raised action',
        ]);
        // The EXISTING action closed TODAY.
        $existingOpenAction->update(['completed_at' => now(), 'status' => 'completed']);

        $secondPastReport = app(EvidencePackService::class)->drTestReport($pastAsOf->copy());
        $secondLiveReport = app(EvidencePackService::class)->drTestReport(now());

        $this->assertSame(
            $firstPastReport,
            $secondPastReport,
            'A DR test report for a past period must not move when an action is added, or an existing one closed, after that period.'
        );
        $this->assertNotSame(
            $firstLiveReport,
            $secondLiveReport,
            'A live DR test report must move when a new action is added and an existing one is closed.'
        );
    }

    /**
     * Code review #4, E1: `EvidencePackService::drTestReport()` printed
     * the finding's LIVE `status` — a finding open at the end of Q1 and
     * closed in Q2 printed "(closed)" when the Q1 pack was regenerated.
     * `FindingService::close()` (:165-169) and `::acceptRisk()` (:179-184)
     * both stamp `closed_at` on every closure — `closed_at !== null &&
     * closed_at <= $asOf` derives open/closed correctly either way.
     */
    #[Test]
    public function dr_test_report_does_not_move_for_a_past_period_when_the_finding_is_closed_after_it(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $pastAsOf = now()->subYears(2)->endOfYear();

        $system = \App\Models\Bcms\DrSystem::factory()->create(['name' => 'Core Banking DR']);
        $test = \App\Models\Bcms\DrTest::factory()->create([
            'dr_system_id' => $system->getKey(), 'test_type' => 'failback',
            'test_date' => $pastAsOf->copy()->subDays(10), 'met_objectives' => false,
        ]);
        $finding = Finding::factory()->create([
            'classification' => 'nonconformity', 'status' => 'open', 'dr_test_id' => $test->getKey(),
            'raised_at' => $pastAsOf->copy()->subDays(9),
        ]);

        $firstPastReport = app(EvidencePackService::class)->drTestReport($pastAsOf->copy());
        $firstLiveReport = app(EvidencePackService::class)->drTestReport(now());

        // Closed TODAY — strictly after the past pack's own `$to`.
        $finding->update(['status' => 'closed', 'closed_at' => now()]);

        $secondPastReport = app(EvidencePackService::class)->drTestReport($pastAsOf->copy());
        $secondLiveReport = app(EvidencePackService::class)->drTestReport(now());

        $this->assertSame(
            $firstPastReport,
            $secondPastReport,
            'A DR test report for a past period must not move when its finding is closed after that period.'
        );
        $this->assertNotSame(
            $firstLiveReport,
            $secondLiveReport,
            'A live DR test report must move when its finding is closed inside its own (current) period.'
        );
    }

    /**
     * §3.3's `cbn_csf`-specific content: the DR/failover register, DR tests
     * for the period, and the incident register with notification timing
     * against the 24-hour window — none of it invented, all read from
     * stored rows.
     */
    #[Test]
    public function the_cbn_csf_content_carries_the_dr_register_tests_and_incident_notification_timing(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $system = \App\Models\Bcms\DrSystem::factory()->create([
            'name' => 'Core Banking DR', 'next_test_due' => now()->subDay(),
        ]);
        \App\Models\Bcms\DrTest::factory()->create([
            'dr_system_id' => $system->getKey(), 'test_type' => 'failover',
            'test_date' => now()->subDays(5), 'met_objectives' => true,
        ]);

        $incident = Incident::factory()->create([
            'is_exercise' => false, 'detected_at' => now()->subDays(3), 'status' => 'closed',
        ]);
        IncidentNotification::query()->create([
            'organization_id' => $this->organization->id, 'incident_id' => $incident->getKey(),
            'regulator' => 'cbn', 'basis_clause_ref' => 'cbn.rcf.incident_response', 'kind' => 'initial',
            'sequence' => 1, 'awareness_at' => now()->subDays(3), 'due_at' => now()->subDays(2),
            'submitted_at' => now()->subDays(3),
        ]);

        $content = app(EvidencePackService::class)->cbnContent(now()->subDays(30), now());

        $this->assertSame('Core Banking DR', $content['dr_systems'][0]['name']);
        $this->assertTrue($content['dr_systems'][0]['overdue']);
        $this->assertSame('failover', $content['dr_tests'][0]['test_type']);
        $this->assertSame($incident->reference, $content['incidents'][0]['reference']);
        $this->assertTrue($content['incidents'][0]['notifications'][0]['within_window']);
    }

    /**
     * Reviewer #1 of Phase 10 found `ProgrammeService` and `BoardPackService`
     * still reading `bcms_incidents.reporting_due_at`/`regulator_notified_at`
     * — retired in place by ADR 0020 §2 and NULL on every incident created
     * from Phase 10 onward. An incident with an open, overdue
     * `bcms_incident_notifications` row and NULL retired columns must still
     * be counted as an overdue notification by both the board pack and the
     * management-review snapshot — reading the retired columns instead would
     * silently show zero overdue notifications for ever.
     */
    #[Test]
    public function an_overdue_notification_row_is_counted_by_the_board_pack_and_management_review(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $incident = Incident::factory()->create([
            'is_reportable' => true,
            'detected_at' => now()->subDays(2),
        ]);

        $this->assertNull($incident->reporting_due_at);
        $this->assertNull($incident->regulator_notified_at);
        $this->assertNull($incident->cbn_reference);

        IncidentNotification::query()->create([
            'organization_id' => $this->organization->id,
            'incident_id' => $incident->getKey(),
            'regulator' => 'cbn',
            'basis_clause_ref' => 'cbn.rcf.incident_response',
            'kind' => 'initial',
            'sequence' => 1,
            'awareness_at' => now()->subHours(30),
            'due_at' => now()->subHours(6),
        ]);

        $boardPack = app(BoardPackService::class)->preview(now()->year);
        $this->assertSame(1, $boardPack['incident_summary']['notified_late_or_missing']);
        $this->assertSame(0, $boardPack['incident_summary']['notified_within_due']);

        $review = ManagementReview::factory()->create(['status' => 'draft']);
        $captured = app(ProgrammeService::class)->captureReviewInputs($review);
        $this->assertSame(1, $captured->inputs['incidents']['notified_late_or_missing']);
        $this->assertSame(0, $captured->inputs['incidents']['notified_within_due']);
    }

    /**
     * Found by Phase 10's code review, same family as B17 (gate 1 code
     * review #1): `BoardPackService::incidentSummary()` did not exclude
     * exercise incidents — a drill's incident record was counted in the
     * board's own incident summary. ADR 0020 §4 requires `is_exercise =
     * false` "in every aggregate".
     */
    #[Test]
    public function an_exercise_incident_is_excluded_from_the_board_packs_incident_summary(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        Incident::factory()->create(['is_exercise' => true, 'detected_at' => now(), 'severity' => 'sev1']);
        Incident::factory()->create(['is_exercise' => false, 'detected_at' => now(), 'severity' => 'sev4']);

        $boardPack = app(BoardPackService::class)->preview(now()->year);

        $this->assertSame(1, $boardPack['incident_summary']['count'], 'A drill\'s incident must not inflate the board pack\'s own incident count.');
        $this->assertSame(['sev4' => 1], $boardPack['incident_summary']['by_severity']);
    }

    /**
     * An incident that was never classified as reportable (`is_reportable =
     * false`) carries no regulatory-notification obligation at all — it must
     * not be counted as "within due" (it was never due anything) nor as
     * "late or missing" (nothing is missing that was never owed). Both
     * `incidentReportingOutcomes()` and `BoardPackService::incidentSummary()`
     * scope their obligation set to `is_reportable = true` before touching
     * `bcms_incident_notifications` for exactly this reason.
     */
    #[Test]
    public function a_not_reportable_incident_is_in_neither_notification_bucket(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        Incident::factory()->create([
            'is_reportable' => false,
            'detected_at' => now()->subDays(2),
        ]);

        $boardPack = app(BoardPackService::class)->preview(now()->year);
        $this->assertSame(0, $boardPack['incident_summary']['notified_within_due']);
        $this->assertSame(0, $boardPack['incident_summary']['notified_late_or_missing']);

        $review = ManagementReview::factory()->create(['status' => 'draft']);
        $captured = app(ProgrammeService::class)->captureReviewInputs($review);
        $this->assertSame(0, $captured->inputs['incidents']['notified_within_due']);
        $this->assertSame(0, $captured->inputs['incidents']['notified_late_or_missing']);
        // The incident itself still counts toward the raw incident total —
        // only the notification buckets are scoped to reportable incidents.
        $this->assertSame(1, $captured->inputs['incidents']['count']);
    }

    /**
     * phase-11-spec §6 criterion 2: generating a pack twice over the same
     * period, with nothing written in between, must yield byte-identical
     * SECTION CONTENT. `generate()`/`generatePdf()`/`generatePptx()` bake
     * `now()` into `generatedAt` on the rendered document, so this compares
     * the content BEFORE that — `sectionsFor()`/`preview()`, exactly what
     * the PDF/PPTX writers are handed — which never carries `generatedAt`
     * at all, so no normalisation is needed to compare two calls with
     * `===`. Two fixture rows sharing a timestamp (`Aar`, `Finding`) are
     * included so a tie is actually exercised, not just assumed absent.
     */
    #[Test]
    public function evidence_pack_and_board_pack_section_content_is_reproducible_across_two_calls(): void
    {
        \Illuminate\Support\Carbon::setTestNow(now());
        $tie = now();

        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        foreach (\App\Enums\Bcms\IsoClauseRef::cases() as $ref) {
            \App\Models\Bcms\ProgrammeObligation::factory()->create([
                'programme_id' => $programme->getKey(), 'clause_ref' => $ref->value, 'applies' => true,
            ]);
        }

        // A SECOND programme for the SAME year (`bcms_programmes` is unique
        // on organization_id/year/name, not year alone, so two same-year
        // programmes — one superseding the other mid-year — is legal data,
        // not a fixture bug). qa-engineer re-gate #5: `scopeSufficiency()`/
        // `policySufficiency()`'s own `Programme::query()->orderByDesc('year')
        // ->first()` reads had no id tiebreaker before this cycle, so which
        // of the two a re-run picked was undefined.
        Programme::factory()->create(['status' => 'draft', 'year' => now()->year]);

        // Two completed occurrences, each with a finalised AAR approved at
        // the exact same instant — a genuine tie on `approved_at`.
        foreach (range(1, 2) as $i) {
            $occurrence = ExerciseOccurrence::factory()->create(['status' => 'completed', 'scheduled_date' => now()->toDateString()]);
            \App\Models\Bcms\Aar::factory()->create(['occurrence_id' => $occurrence->getKey(), 'status' => 'final', 'approved_at' => $tie]);
        }

        // Two open nonconformity findings raised at the same instant.
        foreach (range(1, 2) as $i) {
            Finding::factory()->create(['classification' => 'nonconformity', 'status' => 'open', 'raised_at' => $tie]);
        }

        // Two APPROVED exercise programmes for the SAME year, approved at
        // the exact same instant — no unique constraint on
        // (organization_id, year) exists for `bcms_exercise_programmes`
        // (only year+name), so this is legal data too. Exercises
        // `ClauseComplianceMatrixService::exerciseProgrammeSufficiency()`
        // and `BoardPackService::exerciseCompletion()`'s own
        // `ExerciseProgramme::where('year', ...)->where('status', 'approved')
        // ->first()` reads, which had no ORDER BY at all before this cycle.
        foreach (range(1, 2) as $i) {
            \App\Models\Bcms\ExerciseProgramme::factory()->create([
                'year' => now()->year, 'status' => 'approved', 'approved_at' => $tie,
                'total_planned' => 1,
            ]);
        }

        $firstSections = app(EvidencePackService::class)->sectionsFor('iso22301');
        $secondSections = app(EvidencePackService::class)->sectionsFor('iso22301');
        $this->assertSame($firstSections, $secondSections);

        $firstPreview = app(BoardPackService::class)->preview(now()->year);
        $secondPreview = app(BoardPackService::class)->preview(now()->year);
        $this->assertSame($firstPreview, $secondPreview);

        \Illuminate\Support\Carbon::setTestNow();
    }

    /**
     * B2/B15 (gate 1 code review #1)/R3 (gate 1 code review #2): the
     * reproducibility test above proves "nothing written, nothing changes"
     * — it cannot fail on a regression that makes EVERY pack silently
     * live-scoped, because it never writes between calls. This test writes
     * one new row into EVERY class R3's code review named as having lacked
     * an upper bound (exercise programme, AAR, finding/corrective action,
     * KRI measurement, notification delivery, DR test), each dated strictly
     * AFTER the past pack's own `$to`, and checks the two different,
     * correct answers: a pack for a PAST period must NOT move (every new
     * row falls outside it); a LIVE pack must move (every new row falls
     * inside its own, current period) — proving each class's bound
     * individually rather than trusting one row to stand for all six.
     */
    #[Test]
    public function a_past_period_pack_does_not_move_on_a_new_write_but_a_live_one_does(): void
    {
        // QA ruling on the build() programme picker: programmeAsOf() bounds
        // on `created_at <= periodEnd` — a programme governing the
        // two-years-ago period this test evaluates ought to have existed
        // by then, not been created today.
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year, 'created_at' => now()->subYears(2)->startOfYear()]);
        foreach (\App\Enums\Bcms\IsoClauseRef::cases() as $ref) {
            \App\Models\Bcms\ProgrammeObligation::factory()->create([
                'programme_id' => $programme->getKey(), 'clause_ref' => $ref->value, 'applies' => true,
            ]);
        }

        app(\App\Services\Bcms\ResilienceKriPublisher::class)->adopt($this->admin->id);

        $pastAsOf = now()->subYears(2)->endOfYear();
        $pastFrom = now()->subYears(2)->startOfYear();

        // A baseline occurrence, scheduled (never completed) within the
        // past window, BEFORE the first snapshot — so `iso22398.exercise_
        // design`'s own existsCheck() (bound on `scheduled_date` alone,
        // untouched by D2) already reads "on file" in `$firstPast`. Without
        // this, the D2 fixture added below (its own `scheduled_date` also
        // falling inside the past window) would flip that UNRELATED row
        // too, which is not what this test means to isolate.
        ExerciseOccurrence::factory()->create(['status' => 'planned', 'scheduled_date' => $pastAsOf->copy()->subMonths(2)->toDateString()]);

        $firstPast = app(EvidencePackService::class)->sectionsFor('iso22301', $pastAsOf->copy(), $pastFrom->copy());
        $firstLive = app(EvidencePackService::class)->sectionsFor('iso22301', now());

        // Exercise programme — exerciseProgrammeSufficiency() (8.5.programme):
        // year = this year, approved today.
        \App\Models\Bcms\ExerciseProgramme::factory()->create([
            'year' => now()->year, 'status' => 'approved', 'approved_at' => now(), 'total_planned' => 1,
        ]);

        // AAR — aarSufficiency() (8.5.report): a completed occurrence
        // scheduled today, with a finalised AAR approved today.
        $occurrence = ExerciseOccurrence::factory()->create(['status' => 'completed', 'scheduled_date' => now()->toDateString()]);
        \App\Models\Bcms\Aar::factory()->create(['occurrence_id' => $occurrence->getKey(), 'status' => 'final', 'approved_at' => now()]);

        // Finding/corrective action — continualImprovementSufficiency()
        // (10.2), via existsCheck() bounded on `carried_at`.
        \App\Models\Bcms\CorrectiveAction::factory()->create(['carried_to_occurrence_id' => $occurrence->getKey(), 'carried_at' => now()]);

        // KRI measurement — kriSufficiency() (9.1): an adopted KRI measured
        // today, inside its own (monthly) frequency.
        $kri = KeyRiskIndicator::query()->where('kri_code', 'BCMS-EX-COMPLETION')->firstOrFail();
        $kri->forceFill(['measurement_frequency' => 'monthly', 'last_measurement_at' => now()])->save();

        // Notification delivery — communicationSufficiency() (7.4/8.4.3).
        \App\Models\Bcms\NotificationDelivery::factory()->create(['delivered_at' => now()]);

        // DR test — recoverySufficiency() (8.4.5): a failback tested today.
        \App\Models\Bcms\DrTest::factory()->create(['test_type' => 'failback', 'test_date' => now()->toDateString()]);

        // Coordinator follow-up to R3 (the ten sufficiency methods gate 1
        // code review #2 named as unbound and unlabelled): two of the
        // newly-bounded classes, each dated today — a plan approved today
        // (plansSufficiency(), 8.4.1/8.4.2/8.4.4, bound on `approved_at`)
        // and a nonconformity raised today with its corrective action
        // (nonconformitySufficiency(), 10.1, bound on `raised_at`/
        // `created_at`).
        \App\Models\Bcms\Plan::factory()->create(['status' => 'approved', 'approved_at' => now()]);
        $newFinding = Finding::factory()->create(['classification' => 'nonconformity', 'status' => 'open', 'raised_at' => now()]);
        \App\Models\Bcms\CorrectiveAction::factory()->create(['finding_id' => $newFinding->getKey()]);

        // Code review #3, D2: two more classes this fix bounded, each with
        // its own cheap, honest bound — an occurrence SCHEDULED inside the
        // past period but COMPLETED today (`actual_end`), and a scope
        // exclusion with no rationale ADDED today.
        // `scheduled_date` deliberately EARLIER than the baseline occurrence
        // above — `existsCheck()`'s own `last_evidenced` reflects the
        // MOST RECENT matching row, and a later date here would move that
        // for `iso22398.exercise_design`/`_ladder` too, which is not what
        // this fixture means to isolate.
        \App\Models\Bcms\ExerciseOccurrence::factory()->create([
            'status' => 'completed', 'scheduled_date' => $pastAsOf->copy()->subMonths(3)->toDateString(), 'actual_end' => now(),
        ]);
        // `status: 'retired'`, deliberately — a fresh Process otherwise
        // defaults to `status = 'active'`, which would ALSO move every
        // OTHER Process-denominator row (raci/bia/strategy/8.6 — already
        // documented as current_state, unbounded by design) for a reason
        // that has nothing to do with THIS fixture's own point.
        $retiredProcess = \App\Models\Bcms\Process::factory()->create(['status' => 'retired']);
        \App\Models\Bcms\ProgrammeScopeItem::factory()->create([
            'programme_id' => $programme->getKey(), 'scopable_id' => $retiredProcess->getKey(),
            'in_scope' => false, 'rationale' => null,
        ]);

        $secondPast = app(EvidencePackService::class)->sectionsFor('iso22301', $pastAsOf->copy(), $pastFrom->copy());
        $secondLive = app(EvidencePackService::class)->sectionsFor('iso22301', now());

        $this->assertSame($firstPast, $secondPast, 'A pack for a past period must not move when ten new facts are written today, all outside that period.');
        $this->assertNotSame($firstLive, $secondLive, 'A live pack must move when new facts are written that fall inside its own (current) period.');

        // The coordinator's own second assertion: a section whose
        // denominator cannot be reconstructed for a past period (which
        // processes are Tier 1/critical RIGHT NOW — no history table
        // exists) must carry the `current_state` label, identically in the
        // past and the live pack, since neither pack can reconstruct it.
        $pastRaci = collect($secondPast)->firstWhere('code', 'iso22301.5.3');
        $liveRaci = collect($secondLive)->firstWhere('code', 'iso22301.5.3');

        $this->assertNotNull($pastRaci);
        $this->assertTrue($pastRaci['current_state'] ?? false, 'iso22301.5.3 (RACI) must carry current_state — its denominator (active Tier-1/critical processes) has no history.');
        $this->assertTrue($liveRaci['current_state'] ?? false);
    }

    /**
     * QA re-gate #8: `awarenessSufficiency()` (iso22301.7.3) was still
     * unbounded and unlabelled — neither a `<= periodEnd` bound nor a
     * `current_state` flag on either of its two reads (attendance,
     * awareness alert). Spec §3.2 row 6's own sufficiency rule is "A
     * campaign IN THE PERIOD with reach reported" — a true window, bound
     * two-sided the same way row 7 (`communicationSufficiency()`) already
     * is, not `<= periodEnd` alone.
     */
    #[Test]
    public function a_training_record_or_awareness_alert_after_the_period_does_not_move_a_past_pack_but_does_move_a_live_one(): void
    {
        // QA ruling on the build() programme picker: backdated the same
        // way as the sibling test above.
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year, 'created_at' => now()->subYears(2)->startOfYear()]);
        foreach (\App\Enums\Bcms\IsoClauseRef::cases() as $ref) {
            \App\Models\Bcms\ProgrammeObligation::factory()->create([
                'programme_id' => $programme->getKey(), 'clause_ref' => $ref->value, 'applies' => true,
            ]);
        }

        $pastAsOf = now()->subYears(2)->endOfYear();
        $pastFrom = now()->subYears(2)->startOfYear();

        $firstPast = app(EvidencePackService::class)->sectionsFor('iso22301', $pastAsOf->copy(), $pastFrom->copy());
        $firstLive = app(EvidencePackService::class)->sectionsFor('iso22301', now());

        // A training record completed TODAY — strictly after the past
        // pack's own two-years-ago `$to`.
        \App\Models\Bcms\TrainingRecord::factory()->create(['completed_at' => now()]);

        // An awareness alert dispatched TODAY, with reach reported.
        $template = \App\Models\Bcms\AlertTemplate::factory()->create(['category' => 'awareness']);
        \App\Models\Bcms\Alert::factory()->create([
            'template_id' => $template->getKey(), 'dispatched_at' => now(), 'recipient_count' => 10,
        ]);

        $secondPast = app(EvidencePackService::class)->sectionsFor('iso22301', $pastAsOf->copy(), $pastFrom->copy());
        $secondLive = app(EvidencePackService::class)->sectionsFor('iso22301', now());

        $pastBefore = collect($firstPast)->firstWhere('code', 'iso22301.7.3');
        $pastAfter = collect($secondPast)->firstWhere('code', 'iso22301.7.3');
        $liveBefore = collect($firstLive)->firstWhere('code', 'iso22301.7.3');
        $liveAfter = collect($secondLive)->firstWhere('code', 'iso22301.7.3');

        $this->assertSame($pastBefore, $pastAfter, 'A pack for a past period must not move when a training record or an awareness alert is written today, both outside that period.');
        $this->assertNotSame($liveBefore, $liveAfter, 'A live pack must move when a training record and an awareness alert are dispatched inside its own (current) period.');
    }

    /**
     * QA re-gate #9: `competenceSufficiency()` (iso22301.7.2) had no upper
     * bound on the "latest TrainingRecord per assigned user" selection —
     * only the currency half (`next_due_date` vs `periodEnd`) was bound. A
     * record entered TODAY for a user with none in a past period was still
     * picked for the past pack, flipping this mandatory clause from red to
     * green.
     */
    #[Test]
    public function a_training_record_after_the_period_does_not_move_a_past_pack_but_does_move_a_live_one(): void
    {
        // QA ruling on the build() programme picker: backdated the same
        // way as the sibling tests above.
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year, 'created_at' => now()->subYears(2)->startOfYear()]);
        foreach (\App\Enums\Bcms\IsoClauseRef::cases() as $ref) {
            \App\Models\Bcms\ProgrammeObligation::factory()->create([
                'programme_id' => $programme->getKey(), 'clause_ref' => $ref->value, 'applies' => true,
            ]);
        }

        // BC-WARDEN (seeded by BcmsReferenceSeeder): requires_assessment,
        // is_mandatory, target_roles = ['floor-warden'].
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-WARDEN')->firstOrFail();
        $subject = $this->user('Assigned Warden', 'warden-729@khb.test', []);
        $subject->assignRole(Role::findOrCreate('floor-warden', 'web'));

        $pastAsOf = now()->subYears(2)->endOfYear();
        $pastFrom = now()->subYears(2)->startOfYear();

        // No TrainingRecord exists yet at all — the assigned warden has
        // none in the past period, so both packs start red.
        $firstPast = app(EvidencePackService::class)->sectionsFor('iso22301', $pastAsOf->copy(), $pastFrom->copy());
        $firstLive = app(EvidencePackService::class)->sectionsFor('iso22301', now());

        // A passing record entered TODAY — strictly after the past pack's
        // own two-years-ago `$to` — with a far-future next_due_date so
        // currency alone would otherwise let it through.
        TrainingRecord::query()->create([
            'organization_id' => $this->organization->id,
            'curriculum_id' => $curriculum->getKey(), 'user_id' => $subject->getKey(),
            'completed_at' => now(), 'score' => 95, 'competency_assessed' => true,
            'next_due_date' => now()->addMonths(12)->toDateString(),
        ]);

        $secondPast = app(EvidencePackService::class)->sectionsFor('iso22301', $pastAsOf->copy(), $pastFrom->copy());
        $secondLive = app(EvidencePackService::class)->sectionsFor('iso22301', now());

        $pastBefore = collect($firstPast)->firstWhere('code', 'iso22301.7.2');
        $pastAfter = collect($secondPast)->firstWhere('code', 'iso22301.7.2');
        $liveBefore = collect($firstLive)->firstWhere('code', 'iso22301.7.2');
        $liveAfter = collect($secondLive)->firstWhere('code', 'iso22301.7.2');

        $this->assertSame($pastBefore, $pastAfter, 'A pack for a past period must not move when a training record dated today is entered for a user who had none in that period.');
        $this->assertNotSame($liveBefore, $liveAfter, 'A live pack must move when a passing training record is entered inside its own (current) period.');
    }

    /**
     * QA ruling on the `build()` programme picker (gate 1 code review #2's
     * "out-of-scope candidate", closed this cycle) — the sibling hole:
     * `whereNull('approved_at')->orWhere('approved_at', '<=', periodEnd)`
     * bounded APPROVAL, not EXISTENCE. A DRAFT programme's `approved_at` is
     * null, so it sailed through that filter regardless of when it was
     * created and could win `orderByDesc('year')` over an approved
     * programme from an earlier, correct year — a 2027 draft created today
     * making a 2025 pack's 5.3/5.2 read red "no approved programme".
     */
    #[Test]
    public function a_draft_programme_for_a_later_year_created_today_does_not_move_a_past_packs_own_programme(): void
    {
        $pastAsOf = now()->subYears(2)->endOfYear();
        $pastFrom = now()->subYears(2)->startOfYear();

        // A linked, approved policy plan — so `policySufficiency()` (5.2)
        // actually DIFFERS depending on which programme is picked, rather
        // than reading "no approved policy" either way (a draft has no
        // `policy_plan_id` of its own, so a fixture with none on the old
        // programme either would not exercise the sibling hole at all).
        $approver = $this->user('Policy Approver', 'policy-approver-790@khb.test', []);
        $policy = \App\Models\Bcms\Plan::factory()->create([
            'status' => 'approved', 'owner_id' => $this->admin->getKey(), 'approver_id' => $approver->getKey(),
            'approved_at' => $pastAsOf->copy()->subMonths(3), 'next_review_date' => $pastAsOf->copy()->addYears(3)->toDateString(),
        ]);

        // The programme that genuinely governed the past period — approved,
        // existing (`created_at`) well before it, and pointing at the
        // policy above.
        $oldProgramme = Programme::factory()->create([
            'status' => 'approved', 'year' => $pastAsOf->year, 'policy_plan_id' => $policy->getKey(),
            'approved_at' => $pastAsOf->copy()->subMonths(6),
            'created_at' => $pastAsOf->copy()->subMonths(9),
        ]);
        foreach (\App\Enums\Bcms\IsoClauseRef::cases() as $ref) {
            \App\Models\Bcms\ProgrammeObligation::factory()->create([
                'programme_id' => $oldProgramme->getKey(), 'clause_ref' => $ref->value, 'applies' => true,
            ]);
        }

        // A critical process with an accountable owner — raciSufficiency()
        // (5.3) does not consult the programme at all, so this is what
        // makes it GREEN rather than red for its OWN, unrelated reason ("no
        // Tier-1 process"); the byte-identical assertion below then proves
        // the whole matrix, not only the programme-dependent rows, is
        // undisturbed by the new draft.
        $criticalProcess = \App\Models\Bcms\Process::factory()->create(['status' => 'active', 'criticality_tier' => 1]);
        \App\Models\Bcms\RaciAssignment::factory()->create([
            'assignable_type' => 'bcms_process', 'assignable_id' => $criticalProcess->getKey(), 'raci_role' => 'A',
            // raciSufficiency() bounds the assignment to `created_at <=
            // periodEnd` (QA re-gate #9) — backdated so it counts for the
            // past period this test evaluates.
            'created_at' => $pastAsOf->copy()->subMonths(3),
        ]);

        $matrix = app(ClauseComplianceMatrixService::class);

        $firstPastBuild = $matrix->build($pastAsOf->copy(), $pastFrom->copy());
        $firstLiveBuild = $matrix->build(now());

        // A draft for a LATER year — QA re-gate #10's own example shape:
        // created BEFORE the past pack's own periodEnd (so it would ALSO
        // pass an existence bound), the more adversarial case than "created
        // after". Only `approved_at` (which this draft has none of) may
        // decide the pick — `created_at`/`year` must not.
        $draft = Programme::factory()->create([
            'status' => 'draft', 'year' => $pastAsOf->year + 2, 'created_at' => $pastAsOf->copy()->subMonths(1),
        ]);

        $secondPastBuild = $matrix->build($pastAsOf->copy(), $pastFrom->copy());
        $secondLiveBuild = $matrix->build(now());

        $this->assertSame(
            $oldProgramme->getKey(),
            $secondPastBuild['programme']['id'],
            'The past pack must still be about the programme that actually governed it (A), not the new draft.'
        );
        $this->assertSame(
            $firstPastBuild['programme']['id'],
            $secondPastBuild['programme']['id'],
            'A draft programme for a later year, created today, must not change which programme a past pack is about.'
        );
        $this->assertTrue($secondPastBuild['register_seeded'], 'The past pack must keep reading A\'s own seeded obligations register, not the draft\'s empty one.');

        $firstPastSections = collect($firstPastBuild['sections'])->flatten(1);
        $secondPastSections = collect($secondPastBuild['sections'])->flatten(1);

        $this->assertSame(
            $firstPastSections->firstWhere('code', 'iso22301.5.3'),
            $secondPastSections->firstWhere('code', 'iso22301.5.3'),
            'iso22301.5.3 must be byte-identical — the whole matrix, not only the programme id, must be unaffected.'
        );
        $this->assertNotSame('red', $secondPastSections->firstWhere('code', 'iso22301.5.3')['state']);
        $this->assertSame(
            $firstPastSections->firstWhere('code', 'iso22301.5.2'),
            $secondPastSections->firstWhere('code', 'iso22301.5.2'),
            'iso22301.5.2 must be byte-identical.'
        );
        $this->assertNotSame('red', $secondPastSections->firstWhere('code', 'iso22301.5.2')['state']);

        // QA re-gate #10 overturned the assumption this test previously
        // encoded: the LIVE pack does NOT pick an unapproved draft over the
        // programme that IS approved as of now — approval, not `created_at`
        // alone, governs the live screen exactly the way it governs a past
        // pack. A newly drafted programme for a later year does not pre-empt
        // the matrix the moment someone starts drafting it.
        $this->assertSame(
            $firstLiveBuild['programme']['id'],
            $secondLiveBuild['programme']['id'],
            'The live pack must keep showing the approved programme; an unapproved draft for a later year must not pre-empt it.'
        );
        $this->assertNotSame($draft->getKey(), $secondLiveBuild['programme']['id']);
    }

    /**
     * QA ruling item 2: `bcms_programme_obligations` carries no dated
     * applicability history, so `build()` labels the whole obligations
     * register as current state — one pack-level flag, not per-row.
     */
    #[Test]
    public function build_and_the_evidence_pack_carry_the_obligations_current_state_flag(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $built = app(ClauseComplianceMatrixService::class)->build();
        $this->assertTrue($built['obligations_current_state'] ?? null);

        $this->assertTrue(app(EvidencePackService::class)->obligationsCurrentState());
    }

    /**
     * QA re-gate #10 item (b), the pre-existing leak named alongside the
     * two-concurrent-programmes defect: `scopeSufficiency()`'s own
     * `status !== 'approved'` read CURRENT status — a programme approved
     * WITHIN the period that has since moved on to `active` (the
     * coordinator's own named progression: draft → approved → active) read
     * red "no approved programme" for its own past pack. No fixture
     * covered `active` before this fix.
     */
    #[Test]
    public function an_active_programme_approved_within_the_period_is_not_read_as_unapproved(): void
    {
        $pastAsOf = now()->subYears(2)->endOfYear();
        $pastFrom = now()->subYears(2)->startOfYear();

        $programme = Programme::factory()->create([
            'status' => 'active', 'year' => $pastAsOf->year,
            'approved_at' => $pastAsOf->copy()->subMonths(6),
            'created_at' => $pastAsOf->copy()->subMonths(9),
        ]);
        foreach (\App\Enums\Bcms\IsoClauseRef::cases() as $ref) {
            \App\Models\Bcms\ProgrammeObligation::factory()->create([
                'programme_id' => $programme->getKey(), 'clause_ref' => $ref->value, 'applies' => true,
            ]);
        }

        $built = app(ClauseComplianceMatrixService::class)->build($pastAsOf->copy(), $pastFrom->copy());

        $this->assertSame($programme->getKey(), $built['programme']['id']);
        $this->assertTrue($built['register_seeded']);

        $scope = collect($built['sections'])->flatten(1)->firstWhere('code', 'iso22301.4.1');
        $this->assertNotSame('red', $scope['state'], 'An `active` programme approved within the period must not read as unapproved.');
        $this->assertStringNotContainsString('No approved programme', $scope['artefact']);
    }

    /**
     * QA re-gate #10 item (c): a programme approved AFTER `periodEnd` (but
     * created before it) must read as NOT approved for a past pack — the
     * exact `status`-vs-`approved_at` distinction the whole ruling turns
     * on — while the LIVE pack, whose own `periodEnd` is today, treats the
     * same programme as approved.
     */
    #[Test]
    public function a_programme_approved_after_period_end_is_not_approved_for_the_past_pack_but_is_for_the_live_one(): void
    {
        $pastAsOf = now()->subYears(2)->endOfYear();
        $pastFrom = now()->subYears(2)->startOfYear();

        // Existed (created) before the past period ended, but was only
        // approved AFTER it — today.
        $programme = Programme::factory()->create([
            'status' => 'approved', 'year' => $pastAsOf->year,
            'approved_at' => now(),
            'created_at' => $pastAsOf->copy()->subMonths(3),
        ]);
        foreach (\App\Enums\Bcms\IsoClauseRef::cases() as $ref) {
            \App\Models\Bcms\ProgrammeObligation::factory()->create([
                'programme_id' => $programme->getKey(), 'clause_ref' => $ref->value, 'applies' => true,
            ]);
        }

        $pastBuilt = app(ClauseComplianceMatrixService::class)->build($pastAsOf->copy(), $pastFrom->copy());
        $liveBuilt = app(ClauseComplianceMatrixService::class)->build(now());

        // Past: no OTHER programme exists, so the fallback tier still
        // finds this one (it existed by `created_at`) — but not approved.
        $this->assertSame($programme->getKey(), $pastBuilt['programme']['id']);
        $pastScope = collect($pastBuilt['sections'])->flatten(1)->firstWhere('code', 'iso22301.4.1');
        $this->assertSame('red', $pastScope['state'], 'A programme approved AFTER periodEnd must read as not approved for a past pack.');
        $this->assertStringContainsString('No approved programme', $pastScope['artefact']);

        // Live: the SAME programme, approved as of `now()`, reads approved.
        $this->assertSame($programme->getKey(), $liveBuilt['programme']['id']);
        $liveScope = collect($liveBuilt['sections'])->flatten(1)->firstWhere('code', 'iso22301.4.1');
        $this->assertNotSame('red', $liveScope['state']);
    }

    /**
     * QA re-gate #11 item 1: `PlanService::supersede()` (:184) archives the
     * OLD approved version's `status` without touching its own
     * `approved_at` — a policy/continuity plan that genuinely governed a
     * past period must not read as unapproved for that pack merely because
     * it has SINCE been superseded.
     */
    #[Test]
    public function a_plan_approved_in_the_period_then_superseded_after_period_end_is_unchanged_for_the_past_pack(): void
    {
        $pastAsOf = now()->subYears(2)->endOfYear();
        $pastFrom = now()->subYears(2)->startOfYear();

        $programme = Programme::factory()->create([
            'status' => 'approved', 'year' => $pastAsOf->year,
            'approved_at' => $pastAsOf->copy()->subMonths(6), 'created_at' => $pastAsOf->copy()->subMonths(9),
        ]);
        foreach (\App\Enums\Bcms\IsoClauseRef::cases() as $ref) {
            \App\Models\Bcms\ProgrammeObligation::factory()->create([
                'programme_id' => $programme->getKey(), 'clause_ref' => $ref->value, 'applies' => true,
            ]);
        }

        $v1 = Plan::factory()->create(['status' => 'approved', 'approved_at' => $pastAsOf->copy()->subMonths(3)]);
        \App\Models\Bcms\PlanAttestation::factory()->create([
            'plan_id' => $v1->getKey(), 'attestation_type' => 'read', 'attested_at' => $pastAsOf->copy()->subMonths(2),
        ]);

        $matrix = app(ClauseComplianceMatrixService::class);
        $firstPast = $matrix->build($pastAsOf->copy(), $pastFrom->copy());

        // v1 superseded TODAY — strictly after the past pack's own $to. v2
        // is approved today; v1's own status moves to `archived`, its
        // `approved_at` untouched.
        Plan::factory()->create(['status' => 'approved', 'approved_at' => now(), 'supersedes_plan_id' => $v1->getKey()]);
        $v1->update(['status' => 'archived']);

        $secondPast = $matrix->build($pastAsOf->copy(), $pastFrom->copy());

        $firstSections = collect($firstPast['sections'])->flatten(1);
        $secondSections = collect($secondPast['sections'])->flatten(1);

        $this->assertSame(
            $firstSections->firstWhere('code', 'iso22301.7.5'),
            $secondSections->firstWhere('code', 'iso22301.7.5'),
            'iso22301.7.5 must be byte-identical.'
        );
        $this->assertNotSame('red', $secondSections->firstWhere('code', 'iso22301.7.5')['state']);

        $this->assertSame(
            $firstSections->firstWhere('code', 'iso22301.8.4.1'),
            $secondSections->firstWhere('code', 'iso22301.8.4.1'),
            'iso22301.8.4.1 must be byte-identical.'
        );
        $this->assertNotSame('red', $secondSections->firstWhere('code', 'iso22301.8.4.1')['state']);
    }

    /**
     * QA re-gate #11 item 1: a plan family (linked via `supersedes_plan_id`)
     * with TWO versions both approved by `periodEnd` must count ONCE, with
     * the LATEST such version's own content/date, not twice.
     */
    #[Test]
    public function two_approved_by_period_end_versions_of_one_plan_family_count_once(): void
    {
        $pastAsOf = now()->subYears(2)->endOfYear();
        $pastFrom = now()->subYears(2)->startOfYear();

        $programme = Programme::factory()->create([
            'status' => 'approved', 'year' => $pastAsOf->year,
            'approved_at' => $pastAsOf->copy()->subMonths(6), 'created_at' => $pastAsOf->copy()->subMonths(9),
        ]);
        foreach (\App\Enums\Bcms\IsoClauseRef::cases() as $ref) {
            \App\Models\Bcms\ProgrammeObligation::factory()->create([
                'programme_id' => $programme->getKey(), 'clause_ref' => $ref->value, 'applies' => true,
            ]);
        }

        // Both versions carry `status = 'approved'` here, deliberately — the
        // point of the dedup is that `approved_at <= periodEnd` alone,
        // status never entering into it, would otherwise double-count a
        // family with two rows both independently satisfying that bound.
        $v1 = Plan::factory()->create(['status' => 'approved', 'approved_at' => $pastAsOf->copy()->subMonths(6)]);
        $v2 = Plan::factory()->create([
            'status' => 'approved', 'approved_at' => $pastAsOf->copy()->subMonths(1), 'supersedes_plan_id' => $v1->getKey(),
        ]);
        \App\Models\Bcms\PlanAttestation::factory()->create([
            'plan_id' => $v2->getKey(), 'attestation_type' => 'read', 'attested_at' => $pastAsOf->copy(),
        ]);

        $built = app(ClauseComplianceMatrixService::class)->build($pastAsOf->copy(), $pastFrom->copy());
        $plans = collect($built['sections'])->flatten(1)->firstWhere('code', 'iso22301.8.4.1');

        $this->assertSame('green', $plans['state']);
        $this->assertStringContainsString('1 approved plan(s)', $plans['artefact'], 'Two versions of ONE family must count once.');
        $this->assertSame($v2->approved_at->toDateString(), $plans['last_evidenced'], 'The LATEST approved-by-periodEnd version must be the one kept.');
    }

    /**
     * QA re-gate #11 item 2: `StrategyService::select()` (:106-107)
     * deselects a SIBLING strategy the instant a different one is chosen
     * for the same process, with no trace of when the deselected one
     * stopped being selected — a strategy approved and selected within a
     * past period must not read as unevidenced for that pack merely
     * because a sibling was selected LATER.
     */
    #[Test]
    public function a_strategy_approved_in_the_period_then_deselected_after_period_end_is_unchanged_for_the_past_pack(): void
    {
        $pastAsOf = now()->subYears(2)->endOfYear();
        $pastFrom = now()->subYears(2)->startOfYear();

        $programme = Programme::factory()->create([
            'status' => 'approved', 'year' => $pastAsOf->year,
            'approved_at' => $pastAsOf->copy()->subMonths(6), 'created_at' => $pastAsOf->copy()->subMonths(9),
        ]);
        foreach (\App\Enums\Bcms\IsoClauseRef::cases() as $ref) {
            \App\Models\Bcms\ProgrammeObligation::factory()->create([
                'programme_id' => $programme->getKey(), 'clause_ref' => $ref->value, 'applies' => true,
            ]);
        }

        $criticalProcess = \App\Models\Bcms\Process::factory()->create(['status' => 'active', 'criticality_tier' => 1]);
        $strategyA = \App\Models\Bcms\Strategy::factory()->create([
            'process_id' => $criticalProcess->getKey(), 'is_selected' => true, 'selection_rationale' => 'Because A',
            'approval_status' => 'approved', 'approved_at' => $pastAsOf->copy()->subMonths(3),
        ]);

        $matrix = app(ClauseComplianceMatrixService::class);
        $firstPast = $matrix->build($pastAsOf->copy(), $pastFrom->copy());

        // A sibling selected TODAY — strictly after the past pack's own
        // $to — deselects A; A's own `approval_status`/`approved_at` are
        // untouched. B is deliberately draft/unapproved with NO rationale:
        // it must not itself become a second reason this row reads green,
        // which would mask the very defect this test exists to catch.
        \App\Models\Bcms\Strategy::factory()->create([
            'process_id' => $criticalProcess->getKey(), 'is_selected' => true,
        ]);
        $strategyA->update(['is_selected' => false]);

        $secondPast = $matrix->build($pastAsOf->copy(), $pastFrom->copy());

        $firstSections = collect($firstPast['sections'])->flatten(1);
        $secondSections = collect($secondPast['sections'])->flatten(1);

        $this->assertSame(
            $firstSections->firstWhere('code', 'iso22301.8.3'),
            $secondSections->firstWhere('code', 'iso22301.8.3'),
            'iso22301.8.3 must be byte-identical.'
        );
        $this->assertNotSame('red', $secondSections->firstWhere('code', 'iso22301.8.3')['state']);
    }

    /**
     * QA re-gate #11 item 3 (design ruling): `AarService::reopen()`
     * (:728-758) sets `status` back to `draft` AND nulls `approved_at` —
     * no column anywhere holds the fact that an AAR/PIR was once
     * finalised. Every row whose state depends on that read must carry
     * `current_state: true`, in BOTH the past and the live pack (neither
     * can reconstruct it).
     */
    #[Test]
    public function every_aar_dependent_row_carries_current_state_in_the_past_and_the_live_pack(): void
    {
        $pastAsOf = now()->subYears(2)->endOfYear();
        $pastFrom = now()->subYears(2)->startOfYear();

        $programme = Programme::factory()->create([
            'status' => 'approved', 'year' => $pastAsOf->year,
            'approved_at' => $pastAsOf->copy()->subMonths(6), 'created_at' => $pastAsOf->copy()->subMonths(9),
        ]);
        foreach (\App\Enums\Bcms\IsoClauseRef::cases() as $ref) {
            \App\Models\Bcms\ProgrammeObligation::factory()->create([
                'programme_id' => $programme->getKey(), 'clause_ref' => $ref->value, 'applies' => true,
            ]);
        }

        // aarSufficiency()'s OWN first red branch ("no occurrence completed
        // in the period") does not read Aar at all and deliberately does
        // NOT carry current_state — a completed occurrence, in EACH of the
        // past and live windows, is needed to reach the AAR-dependent
        // branch this test means to check. Code review #3, D2: "completed"
        // is now bound on `actual_end`, not `status`.
        ExerciseOccurrence::factory()->create([
            'status' => 'completed', 'scheduled_date' => $pastAsOf->copy()->subMonths(1)->toDateString(),
            'actual_end' => $pastAsOf->copy()->subMonths(1),
        ]);
        ExerciseOccurrence::factory()->create([
            'status' => 'completed', 'scheduled_date' => now()->startOfYear()->toDateString(), 'actual_end' => now(),
        ]);

        $aarCodes = ['iso22398.exercise_evaluation', 'iso22301.8.5.report', 'iso22301.8.6'];

        $pastBuilt = app(ClauseComplianceMatrixService::class)->build($pastAsOf->copy(), $pastFrom->copy());
        $liveBuilt = app(ClauseComplianceMatrixService::class)->build(now());

        foreach ($aarCodes as $code) {
            $pastRow = collect($pastBuilt['sections'])->flatten(1)->firstWhere('code', $code);
            $liveRow = collect($liveBuilt['sections'])->flatten(1)->firstWhere('code', $code);

            $this->assertTrue($pastRow['current_state'] ?? false, "{$code} must carry current_state in the past pack.");
            $this->assertTrue($liveRow['current_state'] ?? false, "{$code} must carry current_state in the live pack.");
        }

        $evaluationRow = collect($pastBuilt['sections'])->flatten(1)->firstWhere('code', 'iso22398.exercise_evaluation');
        $this->assertSame(
            'AAR finalisation is read as currently recorded: an AAR reopened after the period is not counted until re-finalised.',
            $evaluationRow['current_state_reason'] ?? null
        );
    }

    /**
     * R3 (gate 1 code review #2): `whereBetween('detected_at', [$from, $to])`
     * with `$to` read as midnight silently dropped everything dated on the
     * LAST day of the period — `generate()` now passes `$to` as the end of
     * its own day (23:59:59.999) into `cbnContent()`.
     */
    #[Test]
    public function an_incident_detected_on_the_last_day_of_the_period_is_included(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $to = now()->endOfDay();
        $from = now()->subDays(30)->startOfDay();

        $incident = Incident::factory()->create([
            'is_exercise' => false,
            // Late on the last day of the period — a bare `Carbon::parse($to)`
            // reads as that day's midnight and would exclude this row.
            'detected_at' => now()->startOfDay()->addHours(23)->addMinutes(45),
        ]);

        $content = app(EvidencePackService::class)->cbnContent($from, $to);

        $this->assertSame($incident->reference, $content['incidents'][0]['reference'] ?? null, 'An incident detected late on the period\'s own last day must be included, not silently dropped by a midnight-only upper bound.');
    }

    #[Test]
    public function a_pack_that_cannot_hold_the_two_audit_mandatory_records_still_generates_and_names_them(): void
    {
        // Criterion 12: nothing on file for 9.2 — the pack must still
        // generate, and the two mandatory rows must be NAMED in the sections
        // it renders rather than silently dropped.
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $sections = app(EvidencePackService::class)->sectionsFor('iso22301');
        $codes = collect($sections)->pluck('code');

        $this->assertContains('iso22301.9.2.programme', $codes);
        $this->assertContains('iso22301.9.2.results', $codes);

        $bytes = app(EvidencePackService::class)->generate(
            'iso22301', now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString(), $this->admin,
        );
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    /* ================================================================== */
    /*  CSAT pre-fill — the real PhpSpreadsheet round trip */
    /* ================================================================== */

    #[Test]
    public function the_csat_prefill_matches_by_label_writes_the_adjacent_cell_and_lists_the_unmatched_on_a_cover_sheet(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        // Give the RACI-backed clauses something to be green about so the
        // written answer text is non-trivial, but the important assertions
        // below do not depend on which state it lands in.

        $path = tempnam(sys_get_temp_dir(), 'csat-fixture-').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Question');
        $sheet->setCellValue('B1', 'Response');
        // Only ONE of the eight mapped questions appears in this fixture —
        // the CSAT workbook we do not hold. Everything else must end up on
        // the unmatched cover sheet, not invented.
        $sheet->setCellValue('A2', 'Are disaster recovery test results reported to the board?');
        (new Xlsx($spreadsheet))->save($path);

        $result = app(CsatPrefillService::class)->fill($path, $this->admin);
        unlink($path);

        $this->assertSame(1, $result['matched']);
        $this->assertCount(7, $result['unmatched'], 'Seven of the eight mapped questions are absent from this fixture and must be listed, not guessed at.');
        $this->assertContains(
            'Does the institution evidence its critical vendors\' own business continuity and disaster recovery testing?',
            $result['unmatched'],
        );

        // Reload the produced workbook and assert the answer landed in the
        // cell ADJACENT to the matched question, and that a cover sheet with
        // the unmatched count exists — never an invented coordinate.
        $tmp = tempnam(sys_get_temp_dir(), 'csat-out-');
        file_put_contents($tmp, $result['bytes']);
        $reloaded = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        unlink($tmp);

        $cover = $reloaded->getSheetByName('Pre-fill cover sheet');
        $this->assertNotNull($cover);
        $this->assertStringContainsString('1 question(s) matched', (string) $cover->getCell('A2')->getValue());
        $this->assertStringContainsString('7 question(s)', (string) $cover->getCell('A3')->getValue());

        $dataSheet = $reloaded->getSheetByName($sheet->getTitle()) ?? $reloaded->getSheet(1);
        $answer = (string) $dataSheet->getCell('B2')->getValue();
        $this->assertNotSame('', $answer, 'The answer must be written into the cell adjacent to the matched question.');
    }

    /**
     * A9 (gate 1 code review #2): a customer's own workbook may already
     * carry an answer in the cell adjacent to a matched question — this
     * class must never overwrite it. The question is reported as
     * `occupied`, distinct from `unmatched` (it WAS found), and the
     * existing content is left exactly as it was.
     */
    #[Test]
    public function the_csat_prefill_never_overwrites_an_already_occupied_adjacent_cell(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $path = tempnam(sys_get_temp_dir(), 'csat-occupied-').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Are disaster recovery test results reported to the board?');
        $sheet->setCellValue('B1', 'The customer\'s own existing answer.');
        (new Xlsx($spreadsheet))->save($path);

        $result = app(CsatPrefillService::class)->fill($path, $this->admin);
        unlink($path);

        $this->assertSame(0, $result['matched'], 'An occupied cell must not be counted as filled.');
        $this->assertCount(1, $result['occupied']);
        $this->assertContains('Are disaster recovery test results reported to the board?', $result['occupied']);
        $this->assertNotContains('Are disaster recovery test results reported to the board?', $result['unmatched'], 'A found-but-occupied question is not the same fact as a not-found one.');

        $tmp = tempnam(sys_get_temp_dir(), 'csat-occupied-out-');
        file_put_contents($tmp, $result['bytes']);
        $reloaded = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        unlink($tmp);

        $dataSheet = $reloaded->getSheetByName($sheet->getTitle()) ?? $reloaded->getSheet(1);
        $this->assertSame(
            'The customer\'s own existing answer.',
            (string) $dataSheet->getCell('B1')->getValue(),
            'The customer\'s own existing content must be untouched.'
        );
    }

    #[Test]
    public function the_csat_prefill_never_writes_when_no_question_on_the_workbook_matches_any_mapping(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $path = tempnam(sys_get_temp_dir(), 'csat-empty-').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Nothing in this workbook resembles a mapped CSAT question.');
        (new Xlsx($spreadsheet))->save($path);

        $result = app(CsatPrefillService::class)->fill($path, $this->admin);
        unlink($path);

        $this->assertSame(0, $result['matched']);
        $this->assertCount(8, $result['unmatched']);
    }

    /**
     * B9 (gate 1 code review #1): `for ($col = 'A'; $col <= $highestColumn;
     * $col++)` compared column LETTERS as strings, and PHP compares strings
     * byte-by-byte — a target column past `Z` (e.g. `AD`, column 30) makes
     * every one-letter `$col` from `B` onward compare GREATER than it
     * (`'B' > 'A'` at the first byte, same as `'AD'`'s first byte), so the
     * naive loop's own condition goes false after column `A` and the rest
     * of the sheet, including this one, is never scanned. Column 30 is `AD`.
     */
    #[Test]
    public function the_csat_prefill_finds_a_question_past_column_z_on_a_wide_workbook(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $path = tempnam(sys_get_temp_dir(), 'csat-wide-').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        // Column 30 (AD) carries the question; column 31 (AE) is where the
        // answer must land.
        $sheet->setCellValueByColumnAndRow(30, 1, 'Are disaster recovery test results reported to the board?');
        (new Xlsx($spreadsheet))->save($path);

        $result = app(CsatPrefillService::class)->fill($path, $this->admin);
        unlink($path);

        $this->assertSame(1, $result['matched'], 'A question past column Z must still be found.');

        $tmp = tempnam(sys_get_temp_dir(), 'csat-wide-out-');
        file_put_contents($tmp, $result['bytes']);
        $reloaded = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        unlink($tmp);

        $dataSheet = $reloaded->getSheetByName($sheet->getTitle()) ?? $reloaded->getSheet(1);
        $answer = (string) $dataSheet->getCellByColumnAndRow(31, 1)->getValue();
        $this->assertNotSame('', $answer, 'The answer must land in the cell immediately after the question, at column AE.');
    }

    /**
     * B9: the export must be audited, with the organisation named as the
     * pack's own org node (A2).
     */
    #[Test]
    public function the_csat_prefill_writes_a_pack_exported_audit_entry(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $path = tempnam(sys_get_temp_dir(), 'csat-audit-').'.xlsx';
        (new Xlsx(new Spreadsheet))->save($path);

        app(CsatPrefillService::class)->fill($path, $this->admin);
        unlink($path);

        $this->assertDatabaseHas('bcms_audit_logs', [
            'organization_id' => $this->organization->id,
            'auditable_type' => 'bcms_report_pack',
            'event' => 'pack.exported',
            'actor_id' => $this->admin->getKey(),
        ]);

        $log = AuditLog::query()->where('event', 'pack.exported')
            ->whereJsonContains('after->framework', 'cbn_csat_prefill')->firstOrFail();
        $this->assertSame($this->organization->id, $log->after['org_node']['organization_id']);
    }

    /* ================================================================== */
    /*  The AI gap analyser — criterion 9 */
    /* ================================================================== */

    #[Test]
    public function the_gap_analyser_cites_the_org_node_and_elapsed_time_never_a_generic_statement(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $findings = app(GapAnalyserService::class)->draftFindings();

        $this->assertNotEmpty($findings, 'With nothing else on file, at least the two 9.2 mandatory rows must surface.');

        foreach ($findings as $finding) {
            // B8 (gate 1 code review #1, orchestrator decision): a
            // deterministic, templated citation over computed matrix rows —
            // never an LLM call — so `ai_generated` is false, a truthful
            // provenance label.
            $this->assertFalse($finding['ai_generated']);
            $this->assertContains($finding['state'], ['red', 'amber'], 'The gap analyser may never cite a green row.');
            $this->assertSame($this->organization->name, $finding['org_node']);
            $this->assertMatchesRegularExpression(
                '/no artefact has ever been recorded|the last artefact was recorded on/',
                $finding['finding'],
                'The citation must name elapsed time, not a generic "not fully met" statement.',
            );
            $this->assertStringContainsString($finding['clause_ref'], $finding['finding']);
        }
    }

    #[Test]
    public function the_gap_analyser_reports_nothing_over_an_empty_programme_rather_than_fabricating_gaps(): void
    {
        $this->assertSame([], app(GapAnalyserService::class)->draftFindings());
    }

    #[Test]
    public function raising_a_gap_analysis_finding_creates_a_real_finding_row_labelled_matrix_generated_not_ai(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $this->actingAs($this->admin)
            ->post(route('bcms.reports.gap-analysis.raise'), [
                'finding' => 'No internal audit programme is held in this system.',
                'clause_ref' => 'iso22301.9.2.programme',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('bcms_findings', [
            'organization_id' => $this->organization->id,
            'source' => 'gap_analysis',
            'iso_clause_ref' => 'iso22301.9.2.programme',
        ]);

        // B8 (gate 1 code review #1, orchestrator decision): the gap
        // analyser is a deterministic, templated draft over computed matrix
        // rows, never an LLM call — `ai_generated` must be false, a truthful
        // provenance label, not a claim this class cannot back up.
        $finding = Finding::query()->where('source', 'gap_analysis')->firstOrFail();
        $this->assertFalse((bool) $finding->ai_generated);
    }

    /**
     * B8: an unknown `clause_ref` (never mandatory-red-or-amber, or simply
     * invented) must fail validation, not reach
     * `FindingService::raise()` and throw an uncaught `InvalidArgumentException`
     * as a 500.
     */
    #[Test]
    public function raising_a_gap_analysis_finding_against_an_unknown_clause_ref_is_refused_as_validation_not_a_500(): void
    {
        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);

        $this->actingAs($this->admin)
            ->post(route('bcms.reports.gap-analysis.raise'), [
                'finding' => 'This clause does not exist.',
                'clause_ref' => 'iso22301.99.99',
            ])
            ->assertSessionHasErrors('clause_ref');

        $this->assertDatabaseMissing('bcms_findings', ['source' => 'gap_analysis']);
    }

    /**
     * B8: a clause that is currently GREEN (not in the mandatory red/amber
     * set the gap panel is showing) must be refused too — raising it as if
     * it were still a gap would misrepresent the matrix.
     */
    #[Test]
    public function raising_a_gap_analysis_finding_against_a_clause_that_is_not_currently_red_or_amber_is_refused(): void
    {
        $programme = Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        foreach (\App\Enums\Bcms\IsoClauseRef::cases() as $ref) {
            \App\Models\Bcms\ProgrammeObligation::factory()->create([
                'programme_id' => $programme->getKey(), 'clause_ref' => $ref->value, 'applies' => false,
                'applicability_note' => 'Not applicable to this tenant.',
            ]);
        }

        // Every non-9.2 clause is now grey (not applicable) rather than
        // red/amber — none of them is in the mandatory red/amber set
        // draftFindings() surfaces, so any clause_ref is refused.
        $this->actingAs($this->admin)
            ->post(route('bcms.reports.gap-analysis.raise'), [
                'finding' => 'Attempting to raise a clause that is not currently a gap.',
                'clause_ref' => 'iso22301.8.4.5',
            ])
            ->assertSessionHasErrors('clause_ref');
    }

    /* ================================================================== */
    /*  The two Phase 11 console commands — one of them scheduled daily */
    /*  at 06:00 and never once invoked by a test before this file. */
    /* ================================================================== */

    #[Test]
    public function the_kri_adopt_command_creates_seventeen_definitions_for_the_named_organization_only(): void
    {
        $other = Organization::create([
            'name' => 'Lagos Continuity Bank', 'short_name' => 'LCB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        Artisan::call(AdoptBcmsResilienceKris::class, ['--organization' => $this->organization->id]);

        TenantContext::set($this->organization->id);
        $this->assertSame(17, KeyRiskIndicator::query()->whereIn('kri_code', ResilienceKris::codes())->count());
        TenantContext::clear();

        // The command was scoped to one organisation: the other tenant must
        // have nothing, proving the loop's TenantContext::set() per iteration
        // is what makes the --organization filter real rather than cosmetic.
        TenantContext::set($other->id);
        $this->assertSame(0, KeyRiskIndicator::query()->whereIn('kri_code', ResilienceKris::codes())->count());
        TenantContext::clear();
    }

    #[Test]
    public function the_kri_adopt_command_run_with_no_organization_filter_covers_every_tenant_and_stays_idempotent(): void
    {
        $other = Organization::create([
            'name' => 'Lagos Continuity Bank', 'short_name' => 'LCB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        Artisan::call(AdoptBcmsResilienceKris::class);

        foreach ([$this->organization, $other] as $org) {
            TenantContext::set($org->id);
            $this->assertSame(17, KeyRiskIndicator::query()->whereIn('kri_code', ResilienceKris::codes())->count());
            TenantContext::clear();
        }

        // Re-run: must create nothing new anywhere.
        Artisan::call(AdoptBcmsResilienceKris::class);

        foreach ([$this->organization, $other] as $org) {
            TenantContext::set($org->id);
            $this->assertSame(17, KeyRiskIndicator::query()->whereIn('kri_code', ResilienceKris::codes())->count());
            TenantContext::clear();
        }
    }

    #[Test]
    public function the_training_link_command_links_across_tenants_without_mixing_one_tenants_participants_into_another(): void
    {
        $other = Organization::create([
            'name' => 'Lagos Continuity Bank', 'short_name' => 'LCB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        TenantContext::set($other->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::clear();

        TenantContext::set($this->organization->id);
        $kanoWarden = $this->user('Kano Warden', 'kwarden@khb.test', []);
        $kanoWarden->assignRole(Role::findOrCreate('floor-warden', 'web'));
        $kanoOccurrence = ExerciseOccurrence::factory()->create([
            'status' => 'completed', 'scheduled_date' => now()->subDay()->toDateString(),
        ]);
        ExerciseParticipant::factory()->create([
            'occurrence_id' => $kanoOccurrence->getKey(), 'user_id' => $kanoWarden->getKey(),
            'attendance_status' => 'present',
        ]);
        TenantContext::clear();

        TenantContext::set($other->id);
        $lagosWarden = User::create([
            'name' => 'Lagos Warden', 'email' => 'lwarden@lcb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $other->id, 'is_active' => true,
        ]);
        $lagosWarden->assignRole(Role::findOrCreate('floor-warden', 'web'));
        $lagosOccurrence = ExerciseOccurrence::factory()->create([
            'status' => 'completed', 'scheduled_date' => now()->subDay()->toDateString(),
        ]);
        ExerciseParticipant::factory()->create([
            'occurrence_id' => $lagosOccurrence->getKey(), 'user_id' => $lagosWarden->getKey(),
            'attendance_status' => 'present',
        ]);
        TenantContext::clear();

        Artisan::call(LinkBcmsTrainingFromOccurrences::class, ['--days' => 7]);

        TenantContext::set($this->organization->id);
        $this->assertSame(
            1,
            TrainingRecord::query()->where('occurrence_id', $kanoOccurrence->getKey())->count(),
        );
        $this->assertSame(
            0,
            TrainingRecord::query()->where('occurrence_id', $lagosOccurrence->getKey())->count(),
            'A Kano-scoped read must never see a record created against a Lagos occurrence.',
        );
        TenantContext::clear();

        TenantContext::set($other->id);
        $this->assertSame(
            1,
            TrainingRecord::query()->where('occurrence_id', $lagosOccurrence->getKey())->count(),
        );
        TenantContext::clear();

        // Idempotent across a second sweep.
        Artisan::call(LinkBcmsTrainingFromOccurrences::class, ['--days' => 7]);

        TenantContext::set($this->organization->id);
        $this->assertSame(
            1,
            TrainingRecord::query()->where('occurrence_id', $kanoOccurrence->getKey())->count(),
        );
        TenantContext::clear();
    }

    /* ================================================================== */
    /*  Cross-tenant route-model binding — the three models Phase 11 binds */
    /*  for the first time on a route: TrainingRecord (assess), ThirdParty */
    /*  (vendor attestation, uuid-keyed) and ManagementReview (uuid-keyed, */
    /*  reviews.show). Each must 404 rather than resolve another tenant's */
    /*  row, closed rather than open. */
    /* ================================================================== */

    #[Test]
    public function assessing_another_tenants_training_record_404s_rather_than_resolving_it(): void
    {
        $other = Organization::create([
            'name' => 'Lagos Continuity Bank', 'short_name' => 'LCB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);
        $this->seed(BcmsReferenceSeeder::class);
        $otherSubject = User::create([
            'name' => 'Lagos Employee', 'email' => 'lagos-emp@lcb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $other->id, 'is_active' => true,
        ]);
        $otherCurriculum = TrainingCurriculum::query()->where('requires_assessment', true)->firstOrFail();
        $otherRecord = TrainingRecord::query()->create([
            'organization_id' => $other->id,
            'curriculum_id' => $otherCurriculum->getKey(),
            'user_id' => $otherSubject->getKey(),
            'completed_at' => now(),
            'competency_assessed' => false,
        ]);
        TenantContext::clear();

        TenantContext::set($this->organization->id);
        $kanoManager = $this->user('Kano Training Admin', 'kano-trainer@khb.test', ['bcms.training.manage']);
        $kanoAssessor = $this->user('Kano Assessor', 'kano-assessor@khb.test', []);

        $this->actingAs($kanoManager)
            ->post(route('bcms.training-records.assess', $otherRecord), [
                'assessor_id' => $kanoAssessor->getKey(),
                'score' => 90,
            ])
            ->assertNotFound();

        $otherRecord->refresh();
        $this->assertFalse((bool) $otherRecord->competency_assessed, 'A Kano-scoped request must never assess a Lagos record.');
        TenantContext::clear();
    }

    #[Test]
    public function posting_an_attestation_against_another_tenants_vendor_404s(): void
    {
        $other = Organization::create([
            'name' => 'Lagos Continuity Bank', 'short_name' => 'LCB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);
        $otherVendor = ThirdParty::create([
            'organization_id' => $other->id,
            'legal_name' => 'Lagos Vendor Ltd', 'slug' => Str::random(12),
            'entity_type' => 'company', 'status' => 'active',
        ]);
        $otherEngagement = Engagement::create([
            'organization_id' => $other->id,
            'third_party_id' => $otherVendor->getKey(),
            'reference' => 'ENG-LAGOS', 'name' => 'Lagos service',
            'engagement_type' => 'ict_service', 'supports_critical_function' => true,
        ]);
        TenantContext::clear();

        TenantContext::set($this->organization->id);
        $kanoUser = $this->user('Kano Continuity Analyst', 'kano-supplier@khb.test', ['bcms.report.view']);
        $kanoUser->givePermissionTo(Permission::findOrCreate('tprm.edit', 'web'));

        $this->actingAs($kanoUser)
            ->post(route('bcms.vendors.attestation.store', $otherVendor), [
                'engagement_id' => $otherEngagement->getKey(),
                'test_date' => now()->toDateString(),
                'test_type' => 'failover',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('tp_bcp_tests', ['engagement_id' => $otherEngagement->getKey()]);
        TenantContext::clear();
    }

    #[Test]
    public function opening_another_tenants_management_review_404s_rather_than_resolving_it(): void
    {
        $other = Organization::create([
            'name' => 'Lagos Continuity Bank', 'short_name' => 'LCB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($other->id);
        $otherReview = ManagementReview::factory()->create(['status' => 'draft']);
        app(ProgrammeService::class)->captureReviewInputs($otherReview);
        TenantContext::clear();

        TenantContext::set($this->organization->id);
        $kanoExaminer = $this->user('Kano Examiner', 'kano-examiner@khb.test', ['bcms.report.view']);

        $this->actingAs($kanoExaminer)
            ->get(route('bcms.reviews.show', $otherReview))
            ->assertNotFound();
        TenantContext::clear();
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

<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\CorrectiveActionStatus;
use App\Enums\Bcms\FindingClassification;
use App\Enums\Bcms\FindingSource;
use App\Enums\Bcms\IsoClauseRef;
use App\Enums\Bcms\MaturityClauseGroup;
use App\Enums\Bcms\PlanType;
use App\Enums\Bcms\RaciRole;
use App\Models\Bcms\Aar;
use App\Models\Bcms\AuditLog;
use App\Models\Bcms\CallTreeTest;
use App\Models\Bcms\DrTest;
use App\Models\Bcms\Finding;
use App\Models\Bcms\Incident;
use App\Models\Bcms\ManagementReview;
use App\Models\Bcms\Plan;
use App\Models\Bcms\Process;
use App\Models\Bcms\Programme;
use App\Models\Bcms\ProgrammeObligation;
use App\Models\BusinessUnit;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Findings\CorrectiveActionService;
use App\Services\Bcms\Findings\FindingService;
use App\Services\Bcms\MaturityService;
use App\Services\Bcms\PolicyService;
use App\Services\Bcms\ProcessCatalogueService;
use App\Services\Bcms\ProgrammeService;
use App\Services\Bcms\RaciService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Phase 1 acceptance criteria, as tests.
 *
 * Criterion 8 is the one that matters most to the other three tracks: the whole
 * finding → corrective action → verification pipeline has to work **before any
 * producer phase exists**, because P6, P9 and P10 will each call `raise()` and
 * none of them owns the register.
 */
class Phase1GovernanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $author;

    private User $approver;

    private User $doer;

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

        $this->author = $this->user('author@khb.test');
        $this->approver = $this->user('approver@khb.test');
        $this->doer = $this->user('doer@khb.test');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => Str::title(Str::before($email, '@')),
            'email' => $email,
            'password' => Hash::make(Str::random(32)),
            'email_verified_at' => now(),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
    }

    private function programme(): Programme
    {
        return app(ProgrammeService::class)->create([
            'name' => 'BCMS Programme', 'year' => (int) now()->year,
            'scope_statement' => 'Head office, eight branches and three data centres.',
            'owner_id' => $this->author->id,
        ], $this->author->id);
    }

    /** @return list<Process> */
    private function processes(int $count): array
    {
        $unit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);

        $out = [];

        for ($i = 1; $i <= $count; $i++) {
            $out[] = Process::query()->create([
                'code' => 'BCP-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => "Process {$i}",
                'business_unit_id' => $unit->id,
                'criticality_tier' => ($i % 4) + 1,
                'status' => 'active',
            ]);
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 1 — a programme with 50 processes, approved, fully audited */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_programme_is_created_populated_with_fifty_processes_and_approved_with_a_full_audit_trail(): void
    {
        $programme = $this->programme();
        $this->processes(50);

        $this->assertSame(50, Process::query()->count());

        app(ProgrammeService::class)->approve($programme, $this->approver->id);
        app(ProgrammeService::class)->activate($programme, $this->approver->id);

        $programme->refresh();
        $this->assertSame('active', $programme->status);
        $this->assertSame($this->approver->id, (int) $programme->approved_by);

        // Every state change, with actor, timestamp and before/after.
        $trail = AuditLog::query()
            ->where('auditable_type', Programme::class)
            ->where('auditable_id', $programme->getKey())
            ->orderBy('id')
            ->get();

        $this->assertSame(['created', 'updated', 'updated'], $trail->pluck('event')->all());

        $approval = $trail->where('event', 'updated')->first();
        $this->assertSame('draft', $approval->before['status']);
        $this->assertSame('approved', $approval->after['status']);
        $this->assertNotNull($approval->created_at);
    }

    #[Test]
    public function a_programme_cannot_be_approved_by_its_own_owner(): void
    {
        $programme = $this->programme();

        $this->expectException(InvalidArgumentException::class);

        app(ProgrammeService::class)->approve($programme, $this->author->id);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 2 — the policy version chain */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_policy_is_drafted_approved_attested_and_superseded_leaving_v1_immutable_and_retrievable(): void
    {
        $policies = app(PolicyService::class);

        $v1 = $policies->draft('Business Continuity Policy', ['owner_id' => $this->author->id], $this->author->id);
        $this->assertSame('draft', $v1->status);
        $this->assertSame(PlanType::Policy, $v1->plan_type);

        $policies->approve($v1, $this->approver);
        $v1->refresh();
        $this->assertSame('approved', $v1->status);
        $this->assertTrue($v1->isImmutable());

        $attestation = $policies->attest($v1, $this->approver, (int) now()->year, 'board');
        $this->assertSame($this->approver->name, $attestation->attested_by_name);
        $this->assertNotEmpty($attestation->statement);

        $v2 = $policies->supersede($v1, '2.0', $this->author->id);

        $v1->refresh();
        // v1 is ARCHIVED, not deleted, and stays retrievable forever: an
        // examiner asking "what did your policy say in 2026" is asking for it.
        $this->assertSame('archived', $v1->status);
        $this->assertNotNull(Plan::query()->find($v1->getKey()));
        $this->assertSame($v1->getKey(), $v2->supersedes_plan_id);
        $this->assertSame('draft', $v2->status);

        // And the attestation is still attached to the version that was
        // attested, not carried forward onto the new draft.
        $this->assertSame(1, $v1->attestations()->count());
        $this->assertSame(0, $v2->attestations()->count());
    }

    #[Test]
    public function an_approved_policy_cannot_be_approved_by_its_author_or_edited_afterwards(): void
    {
        $policies = app(PolicyService::class);
        $policy = $policies->draft('Policy', [], $this->author->id);

        try {
            $policies->approve($policy, $this->author);
            $this->fail('A policy was approved by its own author.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('other than its author', $e->getMessage());
        }

        $policies->approve($policy, $this->approver);

        $this->expectException(InvalidArgumentException::class);
        $policies->submitForReview($policy->refresh());
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 3 — the import round trip */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_dry_run_reports_every_invalid_row_and_writes_nothing(): void
    {
        BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);

        $path = $this->workbook([
            // valid
            ['BCP-001', 'Payments', 'Settlement', 'BU-OPS', '', $this->author->email, 'Operations', '1', 'yes', 'It is a critical service because…', '', ''],
            // 1: no code
            ['', 'Nameless code', '', 'BU-OPS', '', '', '', '2', 'no', '', '', ''],
            // 2: no name
            ['BCP-002', '', '', 'BU-OPS', '', '', '', '2', 'no', '', '', ''],
            // 3: unknown business unit
            ['BCP-003', 'Orphan', '', 'BU-NOPE', '', '', '', '2', 'no', '', '', ''],
            // 4: tier out of range
            ['BCP-004', 'Bad tier', '', 'BU-OPS', '', '', '', '9', 'no', '', '', ''],
            // 5: critical service with no justification
            ['BCP-005', 'Undefended', '', 'BU-OPS', '', '', '', '1', 'yes', '', '', ''],
            // 6: duplicate code
            ['BCP-001', 'Duplicate', '', 'BU-OPS', '', '', '', '3', 'no', '', '', ''],
        ]);

        $result = app(ProcessCatalogueService::class)->dryRun($path);

        $this->assertSame(6, $result['invalid'], 'The dry run should report exactly the six broken rows.');
        $this->assertSame(1, $result['valid']);
        $this->assertSame(0, Process::query()->count(), 'A dry run wrote to the database.');

        // The row numbers are the ones Excel shows, not the array index.
        $this->assertSame([3, 4, 5, 6, 7, 8], array_values(array_unique(array_column($result['errors'], 'row'))));

        $justification = collect($result['errors'])->firstWhere('column', 'Critical service justification');
        $this->assertNotNull($justification);
        $this->assertStringContainsString('resolution-planning register', $justification['message']);
    }

    #[Test]
    public function a_corrected_file_imports_cleanly_and_the_export_round_trips(): void
    {
        BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);

        $rows = [];

        for ($i = 1; $i <= 200; $i++) {
            $code = 'BCP-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $critical = $i <= 8;

            $rows[] = [
                $code, "Process {$i}", "Description {$i}", 'BU-OPS',
                $i > 1 ? 'BCP-001' : '', $this->author->email, 'Operations',
                (string) (($i % 4) + 1), $critical ? 'yes' : 'no',
                $critical ? 'Designated because it delivers a service the CBN treats as critical.' : '',
                $critical ? 'open_banking, payments' : '', '',
            ];
        }

        $result = app(ProcessCatalogueService::class)->import($this->workbook($rows));

        $this->assertSame(200, $result['created']);
        $this->assertSame(200, Process::query()->count());
        $this->assertSame(8, Process::query()->where('is_critical_service', true)->count());

        // The hierarchy resolved despite the parent appearing before its
        // children — two passes, so a forward reference is not a failure.
        $this->assertSame(199, Process::query()->whereNotNull('parent_process_id')->count());

        // Round trip: export writes the columns import reads, in order.
        $exported = app(ProcessCatalogueService::class)->exportRows();
        $this->assertCount(200, $exported);
        $this->assertCount(count(ProcessCatalogueService::HEADERS), $exported[0]);

        $reimport = app(ProcessCatalogueService::class)->import($this->workbook($exported));
        $this->assertSame(0, $reimport['created'], 'The round trip created duplicates instead of updating.');
        $this->assertSame(200, $reimport['updated']);
        $this->assertSame(8, Process::query()->where('is_critical_service', true)->count());
    }

    #[Test]
    public function an_import_with_any_invalid_row_writes_nothing_at_all(): void
    {
        // Partial success is the worse failure mode: it leaves a catalogue the
        // customer cannot trust and no way to tell what is missing.
        $path = $this->workbook([
            ['BCP-001', 'Fine', '', '', '', '', '', '1', 'no', '', '', ''],
            ['BCP-002', '', '', '', '', '', '', '1', 'no', '', '', ''],
        ]);

        try {
            app(ProcessCatalogueService::class)->import($path);
            $this->fail('An import with an invalid row was allowed to run.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('nothing has been imported', $e->getMessage());
        }

        $this->assertSame(0, Process::query()->count());
    }

    /** @param list<list<string|null>> $rows */
    private function workbook(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bcms').'.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, ProcessCatalogueService::HEADERS);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $path;
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 4 — the RACI gap report */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_raci_gap_report_finds_processes_with_no_accountable_owner(): void
    {
        $processes = $this->processes(5);
        $raci = app(RaciService::class);

        $raci->assign($processes[0], $this->author->id, RaciRole::Accountable);
        $raci->assign($processes[0], $this->doer->id, RaciRole::Responsible);
        $raci->assign($processes[1], $this->doer->id, RaciRole::Responsible);

        $gaps = $raci->processGaps();

        $this->assertSame(5, $gaps['total']);
        $this->assertCount(4, $gaps['without_accountable']);
        $this->assertNotContains($processes[0]->code, array_column($gaps['without_accountable'], 'code'));
        $this->assertContains($processes[1]->code, array_column($gaps['without_accountable'], 'code'));
        $this->assertCount(3, $gaps['without_responsible']);
    }

    #[Test]
    public function an_accountable_owner_who_has_left_is_reported_separately(): void
    {
        // The gap that hides: the matrix still looks complete.
        $processes = $this->processes(1);

        app(RaciService::class)->assign($processes[0], $this->doer->id, RaciRole::Accountable);
        $this->doer->update(['is_active' => false]);

        $gaps = app(RaciService::class)->processGaps();

        $this->assertCount(0, $gaps['without_accountable']);
        $this->assertCount(1, $gaps['inactive_accountable']);
        $this->assertSame($this->doer->name, $gaps['inactive_accountable'][0]['holder']);
    }

    #[Test]
    public function only_one_person_can_be_accountable_for_a_process(): void
    {
        // A process with two accountable people has no owner: when it fails,
        // each will reasonably believe it was the other's.
        $processes = $this->processes(1);
        $raci = app(RaciService::class);

        $raci->assign($processes[0], $this->author->id, RaciRole::Accountable);
        $raci->assign($processes[0], $this->doer->id, RaciRole::Accountable);

        $accountable = $processes[0]->raci()->where('raci_role', 'A')->get();

        $this->assertCount(1, $accountable);
        $this->assertSame($this->doer->id, (int) $accountable->first()->user_id);

        // Responsible is not exclusive — several people do the work.
        $raci->assign($processes[0], $this->author->id, RaciRole::Responsible);
        $raci->assign($processes[0], $this->doer->id, RaciRole::Responsible);
        $this->assertCount(2, $processes[0]->raci()->where('raci_role', 'R')->get());
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 6 and 9 — maturity */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_maturity_score_is_computed_from_artefacts_and_moves_when_one_is_added(): void
    {
        $programme = $this->programme();

        $before = app(MaturityService::class)->assess($programme);
        $leadershipBefore = $before->scores->firstWhere('clause_group', MaturityClauseGroup::Leadership)->score;

        $policies = app(PolicyService::class);
        $policy = $policies->draft('Policy', [], $this->author->id);
        $policies->approve($policy, $this->approver);
        $policies->attest($policy, $this->approver);
        app(RaciService::class)->assign($programme, $this->author->id, RaciRole::Accountable);

        $after = app(MaturityService::class)->assess($programme);
        $leadershipAfter = $after->scores->firstWhere('clause_group', MaturityClauseGroup::Leadership)->score;

        $this->assertGreaterThan($leadershipBefore, $leadershipAfter, 'Adding an approved, attested policy did not move the leadership score.');

        // Both assessments are STORED. A board pack printed from the first one
        // must still reprint the first one's numbers.
        $this->assertSame(2, \App\Models\Bcms\MaturityAssessment::query()->count());
        $this->assertSame($leadershipBefore, $before->refresh()->scores->firstWhere('clause_group', MaturityClauseGroup::Leadership)->score);
    }

    #[Test]
    public function a_clause_group_with_no_evidence_scores_null_rather_than_one(): void
    {
        // A rate over nothing is undefined. Printing 1/5 for a group the
        // organisation has not started asserts a judgement the data does not
        // support (development standard §5).
        $assessment = app(MaturityService::class)->assess(null);

        // A RATIO with no denominator: an empty plan register has no currency
        // rate, so the group scores null and says why.
        $plans = $assessment->scores->firstWhere('clause_group', MaturityClauseGroup::Plans);
        $this->assertNull($plans->score);
        $this->assertStringContainsString('undefined rather than zero', $plans->rationale);

        $analysis = $assessment->scores->firstWhere('clause_group', MaturityClauseGroup::Analysis);
        $this->assertNull($analysis->score);

        // An ABSENT ARTEFACT is a different thing and scores 1: a policy either
        // exists or it does not, and nothing existing is squarely level 1.
        $leadership = $assessment->scores->firstWhere('clause_group', MaturityClauseGroup::Leadership);
        $this->assertSame(1, $leadership->score);

        // The overall is the weighted mean of the groups that could be scored,
        // and is not dragged toward the middle by the ones that could not.
        $this->assertSame('1.00', (string) $assessment->overall_score);
    }

    #[Test]
    public function the_exercise_group_cannot_reach_four_on_documentation_alone(): void
    {
        // Blueprint §2.4 is the product thesis: certification proves you wrote
        // plans, only testing proves readiness. A complete plan library with no
        // exercise programme stops at 3, and a screen that let it score 5 would
        // be selling the failure this module exists to prevent.
        $programme = $this->programme();

        \App\Models\Bcms\ExerciseProgramme::query()->create([
            'programme_id' => $programme->getKey(),
            'year' => (int) now()->year,
            'name' => 'Annual programme',
            'status' => 'approved',
        ]);

        $assessment = app(MaturityService::class)->assess($programme);
        $exercises = $assessment->scores->firstWhere('clause_group', MaturityClauseGroup::Exercises);

        $this->assertLessThanOrEqual(3, $exercises->score);
    }

    #[Test]
    public function there_is_exactly_one_maturity_scoring_implementation(): void
    {
        // Criterion 9, as a grep rather than as a promise. Orchestration §5
        // makes the engine single-owner and Phase 11 builds views over it.
        $matches = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            if (str_ends_with($file, 'MaturityService.php')) {
                continue;
            }

            $contents = file_get_contents($file);

            // The shape a second scorer would have: writing a score row, or
            // computing a clause-group level of its own.
            if (preg_match('/MaturityScore::query\(\)->(create|insert|updateOrCreate)/', $contents)
                || preg_match('/function\s+scoreClauseGroup/', $contents)) {
                $matches[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame([], $matches, 'A second maturity scorer exists: '.implode(', ', $matches));
    }

    /** @return list<string> */
    private function phpFilesUnder(string $dir): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 7 — clause coverage */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_governance_artefacts_evidence_clauses_four_five_six_and_nine_three(): void
    {
        $programme = $this->programme();
        $policies = app(PolicyService::class);
        $policy = $policies->draft('Policy', [], $this->author->id);
        $policies->approve($policy, $this->approver);
        $policies->attest($policy, $this->approver);

        $review = app(ProgrammeService::class)->openManagementReview($programme, 'Annual review');
        app(ProgrammeService::class)->captureReviewInputs($review);

        $this->assertSame(IsoClauseRef::Iso22301_4_3->value, $programme->iso_clause_ref);
        $this->assertSame(IsoClauseRef::Iso22301_5_2->value, $policy->iso_clause_ref);
        $this->assertSame(IsoClauseRef::Cbn_cg_board->value, $policy->attestations()->first()->iso_clause_ref);
        $this->assertSame(IsoClauseRef::Iso22301_9_3_results->value, $review->refresh()->iso_clause_ref);

        $process = $this->processes(1)[0];
        $process->update(['iso_clause_ref' => IsoClauseRef::Iso22301_8_2_2->value]);
        $this->assertSame(IsoClauseRef::Iso22301_8_2_2->value, $process->refresh()->iso_clause_ref);
    }

    #[Test]
    public function a_management_review_cannot_be_approved_before_its_inputs_are_captured(): void
    {
        $programme = $this->programme();
        $review = app(ProgrammeService::class)->openManagementReview($programme, 'Annual review');

        try {
            app(ProgrammeService::class)->approveManagementReview($review, $this->approver->id);
            $this->fail('A management review was approved with no record of what it considered.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('inputs have been captured', $e->getMessage());
        }

        app(ProgrammeService::class)->captureReviewInputs($review);
        app(ProgrammeService::class)->approveManagementReview($review->refresh(), $this->approver->id);

        $this->assertSame('approved', $review->refresh()->status);
    }

    #[Test]
    public function management_review_inputs_are_a_snapshot_and_do_not_move_afterwards(): void
    {
        $programme = $this->programme();
        $review = app(ProgrammeService::class)->openManagementReview($programme, 'Annual review');
        app(ProgrammeService::class)->captureReviewInputs($review);

        $capturedOpen = $review->refresh()->inputs['findings']['open'];
        $this->assertSame(0, $capturedOpen);

        // A finding raised after the meeting must not appear in what the
        // meeting considered.
        $this->raiseNonconformity();

        $this->assertSame(0, $review->refresh()->inputs['findings']['open']);
    }

    #[Test]
    public function the_obligation_register_seeds_the_nigerian_obligations_and_keeps_a_decision_already_made(): void
    {
        $programme = $this->programme();
        $service = app(ProgrammeService::class);

        $added = $service->seedObligations($programme);
        $this->assertGreaterThan(0, $added);

        $failover = ProgrammeObligation::query()->where('clause_ref', IsoClauseRef::Cbn_ob_failover->value)->firstOrFail();
        $this->assertSame(4, (int) $failover->cadence_per_year, 'CBN Open Banking requires a quarterly failover exercise.');

        $psb = ProgrammeObligation::query()->where('clause_ref', IsoClauseRef::Cbn_psb_bcms->value)->firstOrFail();
        $psb->update(['applies' => false, 'applicability_note' => 'Not a payment service bank.']);

        // Re-running must not undo somebody's applicability decision.
        $this->assertSame(0, $service->seedObligations($programme));
        $this->assertFalse((bool) $psb->refresh()->applies);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 8 — the whole CAPA pipeline, from every source */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_finding_can_be_raised_from_every_source_type(): void
    {
        $findings = app(FindingService::class);

        foreach (FindingSource::cases() as $source) {
            $finding = $findings->raise(
                $source,
                FindingClassification::Observation,
                "Something observed via {$source->value}",
            );

            $this->assertSame($source, $finding->source);
            $this->assertSame($source->clauseRef()->value, $finding->iso_clause_ref);
            $this->assertMatchesRegularExpression('/^BCF-\d{4}-\d{4}$/', $finding->reference);
        }

        $this->assertSame(count(FindingSource::cases()), Finding::query()->count());
    }

    #[Test]
    public function the_full_pipeline_runs_before_any_producer_phase_exists(): void
    {
        // Criterion 8. Raise → assign → complete → verify with a note, for each
        // classification, from a producer that does not exist yet.
        $findings = app(FindingService::class);
        $actions = app(CorrectiveActionService::class);

        foreach (FindingClassification::cases() as $classification) {
            $finding = $findings->raise(
                FindingSource::Audit,
                $classification,
                "A {$classification->value} from the internal audit programme",
                null,
                ['iso_clause_ref' => IsoClauseRef::Iso22301_8_4_1->value, 'severity' => 'medium'],
                $this->author->id,
            );

            $action = $actions->create($finding, 'Fix it', [], $this->author->id);
            $actions->assign($action, $this->doer->id, now()->addMonth()->toDateString());
            $this->assertSame(CorrectiveActionStatus::InProgress, $action->refresh()->status);

            $actions->complete($action, $this->doer->id);
            $this->assertSame(CorrectiveActionStatus::Completed, $action->refresh()->status);

            $actions->verify($action->refresh(), $this->approver->id, 'Confirmed against the runbook.');
            $verified = $action->refresh();
            $this->assertSame(CorrectiveActionStatus::Verified, $verified->status);
            $this->assertSame($this->approver->id, (int) $verified->verified_by);
            $this->assertSame('Confirmed against the runbook.', $verified->verification_note);

            $findings->close($finding->refresh(), $this->author->id);
            $this->assertSame('closed', $finding->refresh()->status);
        }
    }

    #[Test]
    public function a_corrective_action_cannot_be_verified_by_the_person_who_did_it(): void
    {
        // Clause 10.1 asks whether the action WORKED, which the person who did
        // it cannot answer about themselves.
        $finding = $this->raiseNonconformity();
        $actions = app(CorrectiveActionService::class);

        $action = $actions->create($finding, 'Fix it', ['owner_id' => $this->doer->id], $this->author->id);
        $actions->complete($action, $this->doer->id);

        foreach ([$this->doer->id] as $forbidden) {
            try {
                $actions->verify($action->refresh(), $forbidden);
                $this->fail('A corrective action was verified by the person who completed it.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('other than the person who owned or completed it', $e->getMessage());
            }
        }

        $actions->verify($action->refresh(), $this->approver->id);
        $this->assertSame(CorrectiveActionStatus::Verified, $action->refresh()->status);
    }

    #[Test]
    public function a_nonconformity_cannot_be_closed_without_a_verified_corrective_action(): void
    {
        $findings = app(FindingService::class);
        $actions = app(CorrectiveActionService::class);
        $finding = $this->raiseNonconformity();

        try {
            $findings->close($finding);
            $this->fail('A nonconformity was closed with no corrective action at all.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('clause 10.1', $e->getMessage());
        }

        $action = $actions->create($finding, 'Fix it', ['owner_id' => $this->doer->id], $this->author->id);

        try {
            $findings->close($finding);
            $this->fail('A nonconformity was closed with an unverified action against it.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('verified', $e->getMessage());
        }

        $actions->complete($action, $this->doer->id);
        $actions->verify($action->refresh(), $this->approver->id);

        $findings->close($finding->refresh());
        $this->assertSame('closed', $finding->refresh()->status);
    }

    #[Test]
    public function a_nonconformity_must_name_the_requirement_it_failed(): void
    {
        // Clause 10.1 defines a nonconformity as a failure to meet A
        // REQUIREMENT. One recorded without naming it is an opinion — and the
        // refusal is an exception rather than a silent downgrade to
        // `observation`, because quietly reclassifying somebody's finding is
        // worse than making them say which clause.
        $this->expectException(InvalidArgumentException::class);

        app(FindingService::class)->raise(
            FindingSource::GapAnalysis,
            FindingClassification::Nonconformity,
            'Something is wrong',
        );
    }

    #[Test]
    public function an_invented_clause_reference_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(FindingService::class)->raise(
            FindingSource::GapAnalysis,
            FindingClassification::Observation,
            'Something observed',
            null,
            ['iso_clause_ref' => 'iso22301.99.9'],
        );
    }

    #[Test]
    public function raising_the_same_finding_from_the_same_source_twice_does_not_duplicate_it(): void
    {
        // A call-tree test re-scored, an AAR regenerated, a maturity run
        // repeated — none may fill the register with duplicates.
        $aar = Aar::factory()->create();
        $findings = app(FindingService::class);

        $first = $findings->raise(FindingSource::Aar, FindingClassification::Observation, 'The bridge was not dialled in', $aar);
        $second = $findings->raise(FindingSource::Aar, FindingClassification::Observation, 'The bridge was not dialled in', $aar);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Finding::query()->count());
    }

    #[Test]
    public function a_finding_from_each_of_the_four_linked_sources_records_its_producer(): void
    {
        $findings = app(FindingService::class);

        $aar = Aar::factory()->create();
        $incident = Incident::factory()->create();
        $callTreeTest = CallTreeTest::factory()->create();
        $drTest = DrTest::factory()->create();
        $review = ManagementReview::factory()->create();

        $cases = [
            [FindingSource::Aar, $aar, 'aar_id'],
            [FindingSource::Incident, $incident, 'incident_id'],
            [FindingSource::CallTreeTest, $callTreeTest, 'call_tree_test_id'],
            [FindingSource::DrTest, $drTest, 'dr_test_id'],
            [FindingSource::ManagementReview, $review, 'management_review_id'],
        ];

        foreach ($cases as [$source, $record, $column]) {
            $finding = $findings->raise($source, FindingClassification::Improvement, "From {$source->value}", $record);

            $this->assertSame((int) $record->getKey(), (int) $finding->{$column});
            $this->assertSame($column, $source->foreignKey());
        }

        // The two with no BCMS table to point at.
        $this->assertNull(FindingSource::Audit->foreignKey());
        $this->assertNull(FindingSource::GapAnalysis->foreignKey());
    }

    #[Test]
    public function a_nonconformity_is_mirrored_into_the_erm_issue_register_and_an_observation_is_not(): void
    {
        $findings = app(FindingService::class);

        $nonconformity = $this->raiseNonconformity();
        $this->assertNotNull($nonconformity->refresh()->erm_issue_id);

        $issue = Issue::query()->find($nonconformity->erm_issue_id);
        $this->assertSame('business_continuity', $issue->issue_category);
        // A nonconformity is at least High: it is a stated requirement the
        // institution is not meeting.
        $this->assertSame('high', $issue->priority);

        // An observation is a fact recorded so the next exercise can look at it
        // again. Mirroring every one would bury the issues somebody has to act
        // on under the ones nobody does.
        $observation = $findings->raise(FindingSource::GapAnalysis, FindingClassification::Observation, 'Worth watching');
        $this->assertNull($observation->refresh()->erm_issue_id);
    }

    #[Test]
    public function closing_a_finding_closes_its_mirrored_issue(): void
    {
        $finding = $this->raiseNonconformity();
        $actions = app(CorrectiveActionService::class);

        $action = $actions->create($finding, 'Fix it', ['owner_id' => $this->doer->id], $this->author->id);
        $actions->complete($action, $this->doer->id);
        $actions->verify($action->refresh(), $this->approver->id);

        app(FindingService::class)->close($finding->refresh());

        $this->assertSame('CLOSED', Issue::query()->find($finding->refresh()->erm_issue_id)->issue_status);
    }

    #[Test]
    public function the_sweep_marks_overdue_actions_and_reopens_lapsed_acceptances(): void
    {
        $finding = $this->raiseNonconformity();
        $actions = app(CorrectiveActionService::class);

        $overdue = $actions->create($finding, 'Late', ['owner_id' => $this->doer->id], $this->author->id);
        $overdue->forceFill(['due_date' => now()->subWeek()->toDateString()])->save();

        $accepted = $actions->create($finding, 'Accepted', ['owner_id' => $this->doer->id], $this->author->id);
        $actions->acceptRisk($accepted, 'Cost outweighs the benefit for now.', $this->approver->id, now()->addDay()->toDateString());
        $accepted->forceFill(['acceptance_expires_on' => now()->subDay()->toDateString()])->save();

        $result = $actions->sweep();

        $this->assertSame(1, $result['overdue']);
        $this->assertSame(1, $result['lapsed']);
        $this->assertSame(CorrectiveActionStatus::Overdue, $overdue->refresh()->status);
        // An acceptance that expired and stayed closed is a nonconformity that
        // quietly went away.
        $this->assertSame(CorrectiveActionStatus::Open, $accepted->refresh()->status);
    }

    #[Test]
    public function carried_to_occurrence_id_is_exposed_and_is_not_populated_by_this_phase(): void
    {
        // Orchestration §5: the column is written EXCLUSIVELY by the exercise
        // engine in Phase 9. Track A exposes it and populates nothing.
        $finding = $this->raiseNonconformity();
        $action = app(CorrectiveActionService::class)->create($finding, 'Fix it', [], $this->author->id);

        $this->assertNull($action->carried_to_occurrence_id);
        $this->assertContains('carried_to_occurrence_id', $action->getFillable());

        // Only the layers that WRITE are searched. A cast on the model and a
        // key in a controller's read shaping are reads; a guard that flagged
        // those would be one somebody weakens until it is decorative.
        $writers = [];

        foreach (['Services', 'Jobs', 'Console', 'Listeners', 'Observers'] as $layer) {
            $dir = app_path($layer);

            if (! is_dir($dir)) {
                continue;
            }

            foreach ($this->phpFilesUnder($dir) as $file) {
                // The ASSIGNMENT form specifically. `whereNotNull(...)` is a
                // read and a docblock is prose; a guard that flagged either is
                // one somebody weakens until it stops meaning anything.
                if (preg_match("/'carried_to_occurrence_id'\s*=>/", file_get_contents($file))) {
                    $writers[] = str_replace(base_path().'/', '', $file);
                }
            }
        }

        $this->assertSame([], $writers, 'Something outside the exercise engine writes carried_to_occurrence_id: '.implode(', ', $writers));
    }

    private function raiseNonconformity(): Finding
    {
        return app(FindingService::class)->raise(
            FindingSource::GapAnalysis,
            FindingClassification::Nonconformity,
            'The documented network diversity does not exist.',
            null,
            ['iso_clause_ref' => IsoClauseRef::Iso22301_8_3->value, 'severity' => 'high'],
            $this->author->id,
        );
    }
}

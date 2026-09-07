<?php

namespace Tests\Feature\Rcsa;

use App\Jobs\GenerateRcsaExportJob;
use App\Models\Rcsa\RcsaExportJob;
use App\Services\Rcsa\RcsaExportService;
use App\Services\Rcsa\RcsaWorkbookWriter;
use App\Services\SpreadsheetReader;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;

/**
 * §10.1 and §10.2 — the mandated export, and the log that makes it a control.
 *
 * THE FILE IS ALWAYS READ BACK THROUGH A PARSER, never asserted on byte length.
 * P2's lesson was that a workbook the product itself produced was rejected by
 * its own importer, and the only test that would have caught it is one that
 * opens the file.
 */
class ExportTest extends ReviewTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->grant(['rcsa_export.bulk']);
    }

    /** @return list<list<mixed>> */
    private function sheetOf(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'rcsa').'.xlsx';
        file_put_contents($path, $contents);

        $rows = app(SpreadsheetReader::class)->rows($path, RcsaWorkbookWriter::SHEET_RCSA);

        unlink($path);

        return $rows;
    }

    /* ------------------------------------------------------------------ */
    /*  The mandated layout (§10.1) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_export_is_the_workbooks_own_layout_plus_the_eight_system_columns(): void
    {
        $assessment = $this->submittedAssessment(risks: 2, aboveAppetite: true);

        $lines = app(RcsaExportService::class)->lines(['cycle' => $assessment->cycle_id], $this->actor);

        $rows = $this->sheetOf(app(RcsaWorkbookWriter::class)->bulkExport($lines, [], 'Tester'));

        // Two header rows, then the data — the source workbook has two, and the
        // merged banner is the first of them.
        $group = $rows[0];
        $header = $rows[1];

        // THE COLUMN EACH BANNER STARTS AT, not merely that it appears
        // somewhere. This assertion used to be `assertContains`, which passed
        // happily while three of the five spans were wrong — P6 derived them
        // from the plan's prose because the workbook was unavailable, and only
        // opening the file showed that `Control assessment` ends at O rather
        // than P, that `Residual Risk` is a single unmerged cell over R, and
        // that `RISK TREATMENT PLAN` starts at S rather than U.
        //
        // Verified against plans/SB _RCSA Template 2026 - Template.xlsx, whose
        // own merges are A1:I1, J1:M1, N1:O1 and S1:W1.
        $this->assertSame('Process', $group[0]);                    // A
        $this->assertSame('INHERENT RISK', $group[9]);              // J
        $this->assertSame('Control assessment', $group[13]);        // N
        $this->assertSame('Residual Risk', $group[17]);             // R
        $this->assertSame('RISK TREATMENT PLAN', $group[18]);       // S
        $this->assertSame('ASSESSMENT RECORD', $group[23]);         // X — ours

        // P and Q carry no banner in the workbook, and reproducing that is the
        // point: the document is meant to look like the one the bank uses.
        $this->assertEmpty($group[15] ?? null, 'C.E modifier (P) must sit under no group header.');
        $this->assertEmpty($group[16] ?? null, 'Residual risk (Q) must sit under no group header.');

        // 23 workbook columns + 8 system columns.
        $this->assertCount(31, array_filter($header, fn ($cell) => filled($cell)));

        $this->assertSame('Risk No.', $header[0]);
        $this->assertSame('Business Unit', $header[1]);
        // W is the last workbook column, and X the first appended one.
        $this->assertSame('Implementation Date', $header[22]);
        $this->assertSame('Assessment Cycle', $header[23]);

        foreach (['Assessor', 'Submitted Date', 'ORM Status', 'Reviewer', 'Action Plan Status', 'Days Overdue', 'Last Review Date'] as $expected) {
            $this->assertContains($expected, $header);
        }

        $this->assertCount(2 + 2, $rows);
    }

    #[Test]
    public function an_above_appetite_row_carries_its_control_owner_and_date(): void
    {
        $assessment = $this->submittedAssessment(risks: 1, aboveAppetite: true);

        $lines = app(RcsaExportService::class)->lines([], $this->actor);
        $rows = $this->sheetOf(app(RcsaWorkbookWriter::class)->bulkExport($lines, [], 'Tester'));

        $data = $rows[2];

        // U, V, W — the plan §10.1 requires on an above-appetite row.
        $this->assertStringContainsString('four-eyes', (string) $data[20]);
        $this->assertSame($this->actor->name, $data[21]);
        $this->assertNotEmpty($data[22]);

        // And the appetite column says so in words.
        $this->assertStringStartsWith('Above risk appetite', (string) $data[19]);
    }

    /**
     * `very_high` is a key. A regulator's copy must not contain it.
     */
    #[Test]
    public function band_levels_are_printed_as_words(): void
    {
        $this->submittedAssessment(risks: 1, aboveAppetite: true);

        $lines = app(RcsaExportService::class)->lines([], $this->actor);
        $rows = $this->sheetOf(app(RcsaWorkbookWriter::class)->bulkExport($lines, [], 'Tester'));

        $this->assertSame('Very high', $rows[2][12]);   // M — inherent level
        $this->assertSame('Very high', $rows[2][17]);   // R — residual level
    }

    /**
     * Many plans per line, one cell per column (defect D5).
     */
    #[Test]
    public function several_action_plans_flatten_into_the_workbooks_single_column(): void
    {
        $assessment = $this->submittedAssessment(risks: 1, aboveAppetite: true);
        $line = $this->linesOf($assessment)->sole();

        $line->actionPlans()->create([
            'organization_id' => $this->organization->id,
            'control_to_implement' => 'A second control, added because one was not enough.',
            'owner_id' => $this->reviewer->id,
            'target_date' => now()->addMonths(6)->toDateString(),
            'status' => \App\Models\Rcsa\RcsaActionPlan::OPEN,
        ]);

        $lines = app(RcsaExportService::class)->lines([], $this->actor);
        $rows = $this->sheetOf(app(RcsaWorkbookWriter::class)->bulkExport($lines, [], 'Tester'));

        // One ROW still, with both plans newline-joined inside U, V and W.
        $this->assertCount(3, $rows);
        $this->assertStringContainsString("\n", (string) $rows[2][20]);
        $this->assertStringContainsString($this->actor->name, (string) $rows[2][21]);
        $this->assertStringContainsString($this->reviewer->name, (string) $rows[2][21]);
    }

    /* ------------------------------------------------------------------ */
    /*  Filters */
    /* ------------------------------------------------------------------ */

    /**
     * Appetite is a comparison against the methodology's ceiling, not a stored
     * column, so the filter resolves it to the band NAMES above the ceiling and
     * asks SQL. This scores three risks above it and one within, and checks the
     * filter splits them the same way `isAboveAppetite()` would.
     *
     * Deliberately NOT submitted: submission locks every line (P5), so
     * re-scoring one afterwards is correctly refused — which is a different
     * rule than the one under test here.
     */
    #[Test]
    public function the_appetite_filter_reads_the_methodologys_ceiling_not_a_column(): void
    {
        foreach (range(1, 4) as $i) {
            $this->publishedRisk(['risk_no' => "RETAIL-R{$i}"]);
        }

        $cycle = $this->makeCycle();
        app(\App\Services\Rcsa\RcsaCycleService::class)->open($cycle, $this->actor);

        $assessment = \App\Models\Rcsa\RcsaAssessment::query()
            ->where('business_unit_id', $this->retail->id)
            ->sole();

        $service = app(\App\Services\Rcsa\RcsaAssessmentService::class);
        $lines = $assessment->lines()->get();

        // 5 × 5 Not Achieved → 18.75, VERY HIGH, above the seeded `low` ceiling.
        foreach ($lines->take(3) as $line) {
            $service->apply($line, ['inherent_likelihood' => 5, 'inherent_impact' => 5, 'control_effectiveness' => 'Not Achieved'], $this->actor);
        }

        // 1 × 1 Fully Achieved → 0.00, VERY LOW, within it.
        $service->apply($lines->last(), ['inherent_likelihood' => 1, 'inherent_impact' => 1, 'control_effectiveness' => 'Fully Achieved'], $this->actor);

        $exports = app(RcsaExportService::class);

        $this->assertSame(3, $exports->count(['appetite' => 'above'], $this->actor));
        $this->assertSame(1, $exports->count(['appetite' => 'within'], $this->actor));

        // The unfiltered export carries all four, so the two halves add up.
        $this->assertSame(4, $exports->count([], $this->actor));
    }

    #[Test]
    public function a_foreign_cycle_is_refused_rather_than_quietly_returning_nothing(): void
    {
        $this->submittedAssessment(risks: 1);

        $foreign = \App\Models\Rcsa\RcsaCycle::create([
            'organization_id' => $this->otherOrg->id,
            'name' => 'Somebody else’s cycle',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
            'methodology_id' => $this->methodology()->id,
            'status' => \App\Models\Rcsa\RcsaCycle::DRAFT,
        ]);

        $this->actingAs($this->actor)
            ->post(route('rcsa.exports.store'), ['cycle' => $foreign->id])
            ->assertSessionHasErrors('cycle');

        // Refused, and therefore NOT logged as an innocent empty export.
        $this->assertSame(0, RcsaExportJob::count());
    }

    /* ------------------------------------------------------------------ */
    /*  The log (§10.2) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_export_is_logged_with_who_what_and_from_where(): void
    {
        $assessment = $this->submittedAssessment(risks: 2);

        $this->actingAs($this->actor)
            ->post(route('rcsa.exports.store'), ['cycle' => $assessment->cycle_id, 'appetite' => 'within'])
            ->assertOk();

        $log = RcsaExportJob::sole();

        $this->assertSame($this->actor->id, $log->user_id);
        $this->assertSame(2, $log->row_count);
        $this->assertSame(RcsaExportJob::READY, $log->status);
        $this->assertSame((string) $assessment->cycle_id, (string) $log->filters['cycle']);
        $this->assertSame('within', $log->filters['appetite']);
        $this->assertNotNull($log->ip_address);
        $this->assertNotNull($log->downloaded_at);
    }

    #[Test]
    public function an_export_that_selects_nothing_is_refused_and_not_logged(): void
    {
        $this->submittedAssessment(risks: 1);

        $this->actingAs($this->actor)
            ->post(route('rcsa.exports.store'), ['residual_level' => 'no_such_level'])
            ->assertSessionHas('error');

        $this->assertSame(0, RcsaExportJob::count());
    }

    #[Test]
    public function a_large_export_is_queued_rather_than_built_in_the_request(): void
    {
        Queue::fake();

        $this->submittedAssessment(risks: 2);

        // Rather than provisioning two thousand risks, drop the threshold to
        // the smallest number that still exercises the branch. What is under
        // test is the DECISION, not the arithmetic.
        $this->app->bind(RcsaExportService::class, fn ($app) => new class($app->make(\App\Services\Rcsa\RcsaCalculationService::class), $app->make(\App\Services\Rcsa\RcsaAuditRecorder::class)) extends RcsaExportService
        {
            public function isSynchronous(int $rowCount): bool
            {
                return false;
            }
        });

        $this->actingAs($this->actor)
            ->post(route('rcsa.exports.store'), [])
            ->assertRedirect();

        Queue::assertPushed(GenerateRcsaExportJob::class);

        $log = RcsaExportJob::sole();
        $this->assertSame(RcsaExportJob::QUEUED, $log->status);
        // Logged BEFORE the file exists — an export that failed and one nobody
        // recorded must be distinguishable.
        $this->assertNull($log->file_path);
    }

    #[Test]
    public function the_queued_job_writes_the_file_and_sends_a_link(): void
    {
        Storage::fake('local');

        $assessment = $this->submittedAssessment(risks: 2);

        $export = app(RcsaExportService::class)->log($this->actor, ['cycle' => $assessment->cycle_id], 2);

        (new GenerateRcsaExportJob($export->id))->handle(
            app(RcsaExportService::class),
            app(RcsaWorkbookWriter::class),
        );

        $export->refresh();

        $this->assertSame(RcsaExportJob::READY, $export->status);
        $this->assertNotNull($export->file_path);
        Storage::disk('local')->assertExists($export->file_path);

        $this->assertSame(
            1,
            \Illuminate\Support\Facades\DB::table('notifications_log')
                ->where('type', 'rcsa.export.ready')
                ->where('user_id', $this->actor->id)
                ->count(),
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Who may collect it */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_signed_link_is_still_checked_against_the_permission_and_the_owner(): void
    {
        Storage::fake('local');

        $assessment = $this->submittedAssessment(risks: 1);
        $export = app(RcsaExportService::class)->log($this->actor, [], 1);

        (new GenerateRcsaExportJob($export->id))->handle(
            app(RcsaExportService::class),
            app(RcsaWorkbookWriter::class),
        );

        $url = URL::temporarySignedRoute('rcsa.exports.download', now()->addHour(), ['export' => $export->id]);

        // The person who ran it.
        $this->actingAs($this->actor)->get($url)->assertOk()->assertDownload();

        // Somebody else holding the same signed URL. The signature proves the
        // link was issued, not that this is who it was issued to.
        $stranger = $this->userWith(['rcsa_export.bulk']);
        $this->actingAs($stranger)->get($url)->assertForbidden();

        // And an unsigned URL is refused whoever follows it.
        $this->actingAs($this->actor)
            ->get(route('rcsa.exports.download', $export))
            ->assertForbidden();
    }

    #[Test]
    public function an_expired_link_is_refused_when_it_is_followed(): void
    {
        Storage::fake('local');

        $export = app(RcsaExportService::class)->log($this->actor, [], 0);
        $export->forceFill([
            'status' => RcsaExportJob::READY,
            'file_path' => 'rcsa/exports/whatever.xlsx',
            'expires_at' => now()->subDay(),
        ])->save();

        // Signed for long enough that the SIGNATURE is not what refuses it —
        // the row's own expiry is, which is the behaviour an expiring link is
        // bought for rather than a cleanup job that may not have run.
        $url = URL::temporarySignedRoute('rcsa.exports.download', now()->addHour(), ['export' => $export->id]);

        $this->actingAs($this->actor)->get($url)->assertStatus(410);
    }

    #[Test]
    public function only_an_administrator_sees_everybody_elses_exports(): void
    {
        $this->submittedAssessment(risks: 1);

        $other = $this->userWith(['rcsa_export.bulk']);

        app(RcsaExportService::class)->log($this->actor, [], 1);
        app(RcsaExportService::class)->log($other, [], 1);

        // A plain exporter sees their own.
        $this->actingAs($other)
            ->get(route('rcsa.exports.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('sees_everything', false)->has('log.data', 1));

        // The Head of ORM sees both, with the IP.
        $admin = $this->userWith(['rcsa_export.bulk', 'rcsa_audit.view']);

        $this->actingAs($admin)
            ->get(route('rcsa.exports.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('sees_everything', true)->has('log.data', 2));
    }

    #[Test]
    public function exporting_is_its_own_permission(): void
    {
        $this->submittedAssessment(risks: 1);

        // Every assessment permission there is, and no export permission.
        $reader = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.complete', 'rcsa_assessment.review']);

        $this->actingAs($reader)->get(route('rcsa.exports.index'))->assertForbidden();
        $this->actingAs($reader)->post(route('rcsa.exports.store'), [])->assertForbidden();
    }

    #[Test]
    public function the_export_is_behind_the_feature_flag(): void
    {
        config()->set('features.rcsa_v2', false);

        $this->actingAs($this->actor)->get(route('rcsa.exports.index'))->assertNotFound();
    }
}

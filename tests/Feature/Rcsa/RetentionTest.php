<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaCycle;
use App\Models\Rcsa\RcsaExportJob;
use App\Models\Rcsa\RcsaImportBatch;
use App\Services\Rcsa\RcsaRetentionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * §14 Q10 — the retention policy, and everything it refuses to delete.
 *
 * Most of this file is about the refusals. A retention sweep is the one piece
 * of this module that destroys things, so the tests that matter are the ones
 * asserting it did NOT destroy something: no policy means no deletions, a
 * closed cycle is never removed, an export LOG survives its file, and the
 * hash-chained audit trail is never touched by any of it.
 */
class RetentionTest extends CycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /* ------------------------------------------------------------------ */
    /*  No policy is the default, and it does nothing */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_tenant_with_no_policy_has_every_period_null(): void
    {
        $this->assertSame([
            'export_files_days' => null,
            'import_files_days' => null,
            'closed_cycle_years' => null,
        ], app(RcsaRetentionService::class)->policyFor($this->organization));
    }

    /**
     * The nightly command against a bank that never answered Q10 removes
     * nothing, for ever.
     */
    #[Test]
    public function committing_with_no_policy_purges_nothing(): void
    {
        $job = $this->exportJob(ageDays: 3650);

        $done = app(RcsaRetentionService::class)->purge($this->organization);

        $this->assertSame(['export_files' => 0, 'import_files' => 0, 'import_rows' => 0], $done);
        $this->assertNull($job->refresh()->file_purged_at);
        Storage::disk('local')->assertExists((string) $job->file_path);
    }

    /**
     * A tenant who types 0 into a retention field has not decided to delete
     * every export the moment it is written.
     */
    #[Test]
    public function a_period_of_zero_reads_as_keep_for_ever(): void
    {
        $this->setPolicy(['export_files_days' => 0]);

        $this->assertNull(app(RcsaRetentionService::class)->policyFor($this->organization)['export_files_days']);
    }

    /* ------------------------------------------------------------------ */
    /*  What it does purge */
    /* ------------------------------------------------------------------ */

    /**
     * THE ROW SURVIVES ITS FILE — the distinction the whole design rests on.
     *
     * §10.2 makes the export log a control: it is how the bank knows who took
     * its operational risk profile out of the building. The workbook is a
     * derived artefact and can be made again; the log cannot.
     */
    #[Test]
    public function an_aged_export_loses_its_file_and_keeps_its_log(): void
    {
        $this->setPolicy(['export_files_days' => 30]);

        $job = $this->exportJob(ageDays: 60);
        $path = (string) $job->file_path;

        app(RcsaRetentionService::class)->purge($this->organization);

        Storage::disk('local')->assertMissing($path);

        $job->refresh();

        $this->assertNotNull($job->id, 'The export log row must survive the sweep.');
        $this->assertNotNull($job->file_purged_at);
        $this->assertSame(RcsaExportJob::EXPIRED, $job->status);

        // Who took what, still answerable.
        $this->assertSame($this->actor->id, (int) $job->user_id);
        $this->assertSame(120, (int) $job->row_count);
    }

    #[Test]
    public function an_export_inside_the_period_is_left_alone(): void
    {
        $this->setPolicy(['export_files_days' => 30]);

        $job = $this->exportJob(ageDays: 5);

        app(RcsaRetentionService::class)->purge($this->organization);

        $this->assertNull($job->refresh()->file_purged_at);
        Storage::disk('local')->assertExists((string) $job->file_path);
    }

    #[Test]
    public function a_finished_import_loses_its_workbook_and_its_staged_rows(): void
    {
        $this->setPolicy(['import_files_days' => 30]);

        $batch = $this->importBatch(ageDays: 60, status: RcsaImportBatch::PUBLISHED, rows: 3);
        $path = (string) $batch->file_path;

        $done = app(RcsaRetentionService::class)->purge($this->organization);

        Storage::disk('local')->assertMissing($path);

        $this->assertSame(1, $done['import_files']);
        $this->assertSame(3, $done['import_rows']);
        $this->assertSame(0, $batch->refresh()->rows()->count());

        // The batch record itself stays — it is what says an import happened.
        $this->assertNotNull($batch->file_purged_at);
        $this->assertSame(RcsaImportBatch::PUBLISHED, $batch->status);
    }

    /**
     * Deleting the file underneath a batch that has not finished would break a
     * job that has not run yet.
     */
    #[Test]
    public function an_unfinished_import_is_never_swept_however_old(): void
    {
        $this->setPolicy(['import_files_days' => 1]);

        $batch = $this->importBatch(ageDays: 3650, status: 'validated', rows: 2);

        app(RcsaRetentionService::class)->purge($this->organization);

        $this->assertNull($batch->refresh()->file_purged_at);
        $this->assertSame(2, $batch->rows()->count());
        Storage::disk('local')->assertExists((string) $batch->file_path);
    }

    /* ------------------------------------------------------------------ */
    /*  The refusals */
    /* ------------------------------------------------------------------ */

    /**
     * A closed cycle is the bank's regulatory record. The command names them;
     * nothing deletes one.
     */
    #[Test]
    public function aged_closed_cycles_are_reported_and_never_deleted(): void
    {
        $this->setPolicy(['closed_cycle_years' => 5]);

        $cycle = $this->closedCycle(closedYearsAgo: 7);

        $report = app(RcsaRetentionService::class)->report($this->organization);

        $this->assertCount(1, $report['closed_cycles']);
        $this->assertSame($cycle->name, $report['closed_cycles'][0]['name']);

        app(RcsaRetentionService::class)->purge($this->organization);

        $this->assertNotNull(RcsaCycle::withoutGlobalScopes()->find($cycle->id));
    }

    /** A retention period is housekeeping; a hold is a legal instruction. */
    #[Test]
    public function a_cycle_under_legal_hold_is_excluded_from_the_report(): void
    {
        $this->setPolicy(['closed_cycle_years' => 5]);

        $cycle = $this->closedCycle(closedYearsAgo: 7);
        $cycle->forceFill(['legal_hold_at' => now(), 'legal_hold_reason' => 'Subject to enquiry.'])->save();

        $report = app(RcsaRetentionService::class)->report($this->organization);

        $this->assertSame([], $report['closed_cycles']);
        $this->assertCount(1, $report['held']);
    }

    /** An export about a held cycle is evidence about that cycle. */
    #[Test]
    public function an_export_naming_a_held_cycle_keeps_its_file(): void
    {
        $this->setPolicy(['export_files_days' => 30]);

        $cycle = $this->closedCycle(closedYearsAgo: 7);
        $cycle->forceFill(['legal_hold_at' => now(), 'legal_hold_reason' => 'Subject to enquiry.'])->save();

        $job = $this->exportJob(ageDays: 60, filters: ['cycle' => $cycle->id]);

        app(RcsaRetentionService::class)->purge($this->organization);

        $this->assertNull($job->refresh()->file_purged_at);
        Storage::disk('local')->assertExists((string) $job->file_path);
    }

    /**
     * THE ONE THAT MATTERS MOST IN THIS FILE.
     *
     * A committed sweep must leave every RCSA table's row count untouched
     * except the one it is allowed to delete from, and it must never shorten
     * the hash-chained audit trail — removing a link there does not shorten
     * the chain, it breaks it, and `audit:verify` would report tampering that
     * never happened.
     *
     * Counting every table rather than asserting on a list keeps this honest
     * as the schema grows: a future sweep that started deleting assessments
     * would fail here without anybody having to remember to add a case.
     *
     * ITS LIMIT, STATED: it counts ROWS, so a SOFT delete would slip past it —
     * verified, by writing one. That is the right boundary rather than an
     * oversight (a soft-deleted row is still retained and still recoverable,
     * which is what a retention guard is protecting), but a sweep that started
     * soft-deleting cycles would hide them from every screen while passing
     * here, and the next person to touch this should know that.
     */
    #[Test]
    public function a_sweep_deletes_rows_from_nothing_but_the_staging_table(): void
    {
        $this->setPolicy([
            'export_files_days' => 1,
            'import_files_days' => 1,
            'closed_cycle_years' => 1,
        ]);

        $this->closedCycle(closedYearsAgo: 7);
        $this->exportJob(ageDays: 60);
        $this->importBatch(ageDays: 60, status: RcsaImportBatch::PUBLISHED, rows: 4);

        $tables = collect(DB::connection()->getSchemaBuilder()->getTableListing())
            ->map(fn ($t) => is_array($t) ? ($t['name'] ?? '') : (string) $t)
            ->map(fn (string $t) => str_contains($t, '.') ? substr($t, (int) strrpos($t, '.') + 1) : $t)
            ->filter(fn (string $t) => str_starts_with($t, 'rcsa_') || $t === 'risk_audit_trail')
            ->values();

        $this->assertGreaterThan(10, $tables->count(), 'The table sweep found almost nothing — it is not looking.');

        $before = $tables->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()]);

        app(RcsaRetentionService::class)->purge($this->organization, $this->actor);

        foreach ($tables as $table) {
            $after = DB::table($table)->count();

            if (in_array($table, RcsaRetentionService::PURGEABLE_TABLES, true)) {
                $this->assertLessThan($before[$table], $after, "{$table} should have been purged.");

                continue;
            }

            if ($table === 'risk_audit_trail') {
                // It may only GROW — the sweep records itself.
                $this->assertGreaterThanOrEqual($before[$table], $after, 'The audit trail must never shrink.');

                continue;
            }

            $this->assertSame($before[$table], $after, "The sweep deleted rows from {$table}, which it must not.");
        }
    }

    /** The sweep records itself, so an examiner can see the housekeeping ran. */
    #[Test]
    public function a_sweep_that_removed_something_is_itself_audited(): void
    {
        if (! Schema::hasTable('risk_audit_trail')) {
            $this->markTestSkipped('No audit trail table in this schema.');
        }

        $this->setPolicy(['export_files_days' => 30]);
        $this->exportJob(ageDays: 60);

        $before = DB::table('risk_audit_trail')->count();

        app(RcsaRetentionService::class)->purge($this->organization, $this->actor);

        $this->assertGreaterThan($before, DB::table('risk_audit_trail')->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /** @param array<string, int|null> $policy */
    private function setPolicy(array $policy): void
    {
        $this->organization->forceFill([
            'settings' => array_replace_recursive(
                (array) $this->organization->settings,
                ['rcsa' => ['retention' => $policy]],
            ),
        ])->save();
    }

    /** @param array<string, mixed> $filters */
    private function exportJob(int $ageDays, array $filters = []): RcsaExportJob
    {
        $path = 'rcsa-exports/'.uniqid('export-', true).'.xlsx';
        Storage::disk('local')->put($path, 'a generated workbook');

        $job = RcsaExportJob::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->actor->id,
            'filters' => $filters,
            'format' => 'xlsx',
            'status' => RcsaExportJob::READY,
            'row_count' => 120,
            'file_path' => $path,
        ]);

        $job->forceFill(['created_at' => now()->subDays($ageDays)])->save();

        return $job->refresh();
    }

    private function importBatch(int $ageDays, string $status, int $rows): RcsaImportBatch
    {
        $path = 'rcsa-imports/'.uniqid('import-', true).'.xlsx';
        Storage::disk('local')->put($path, 'an uploaded workbook');

        $batch = RcsaImportBatch::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->actor->id,
            'type' => 'universe',
            'file_path' => $path,
            'original_name' => 'universe.xlsx',
            'status' => $status,
        ]);

        for ($i = 1; $i <= $rows; $i++) {
            $batch->rows()->create([
                'organization_id' => $this->organization->id,
                'row_number' => $i,
                'raw' => ['risk_no' => "R{$i}"],
                'status' => 'valid',
            ]);
        }

        $batch->forceFill(['created_at' => now()->subDays($ageDays)])->save();

        return $batch->refresh();
    }

    private function closedCycle(int $closedYearsAgo): RcsaCycle
    {
        $cycle = $this->makeCycle(['name' => 'RCSA '.(2026 - $closedYearsAgo)]);

        $cycle->forceFill([
            'status' => RcsaCycle::CLOSED,
            'closed_at' => now()->subYears($closedYearsAgo),
        ])->save();

        return $cycle->refresh();
    }
}

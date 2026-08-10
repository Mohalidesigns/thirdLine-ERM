<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportJob;
use App\Models\GeneratedReport;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\User;
use App\Services\BoardPackAssembler;
use App\Services\DocumentRenderer;
use App\Services\SpreadsheetReader;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The report pipeline: a real PDF, a real workbook, a stored artifact and a
 * spreadsheet importer that reads spreadsheets.
 *
 * The defects these pin down were all of one kind — the product claimed an
 * output it did not produce. Four formats collapsing to CSV, a "board report"
 * that was a Blade view, an .xlsx import parsed with fgetcsv.
 */
class DocumentOutputTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->org = Organization::create([
            'name' => 'Document Bank PLC',
            'short_name' => 'DOCB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'cbn_institution_code' => '044',
            'is_active' => true,
        ]);

        TenantContext::set($this->org->id);

        $this->user = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Report Author',
            'email' => 'author@document.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->user->assignRole('chief-risk-officer');

        $category = RiskCategory::create([
            'organization_id' => $this->org->id,
            'code' => 'OPS',
            'name' => 'Operational Risk',
        ]);

        Risk::create([
            'organization_id' => $this->org->id,
            'risk_code' => 'RK-DOC-0001',
            'title' => 'Settlement failure in the retail branch network',
            'description' => 'Reconciliation breaks going undetected.',
            'category_id' => $category->id,
            'status' => 'active',
            'inherent_likelihood' => 4,
            'inherent_impact' => 5,
            'inherent_score' => 20,
            'inherent_rating' => 'Critical',
            'residual_likelihood' => 3,
            'residual_impact' => 4,
            'residual_score' => 12,
            'residual_rating' => 'High',
            'created_by' => $this->user->id,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Format honesty */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function each_format_produces_a_genuinely_different_document(): void
    {
        $renderer = app(DocumentRenderer::class);

        $payload = [
            'headers' => ['Risk Code', 'Title'],
            'rows' => [['RK-DOC-0001', 'Settlement failure']],
            'sheet_name' => 'Risks',
        ];

        $csv = $renderer->render('reports.pdf.risk-register', $payload, 'csv');
        $xlsx = $renderer->render('reports.pdf.risk-register', $payload, 'xlsx');

        // The previous implementation returned CSV bytes for every format.
        $this->assertSame('text/csv; charset=UTF-8', $csv['mime']);
        $this->assertStringContainsString('Risk Code', $csv['content']);

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $xlsx['mime']
        );
        // A real xlsx is a ZIP archive.
        $this->assertStringStartsWith("PK\x03\x04", $xlsx['content']);
        $this->assertNotSame($csv['content'], $xlsx['content']);
    }

    #[Test]
    public function excel_is_accepted_as_an_alias_but_html_and_pptx_are_refused(): void
    {
        $renderer = app(DocumentRenderer::class);

        $this->assertSame('xlsx', $renderer->normalise('excel'));

        // Silently downgrading these to CSV is the defect being fixed.
        foreach (['html', 'pptx', 'docx'] as $unsupported) {
            try {
                $renderer->normalise($unsupported);
                $this->fail("Format [{$unsupported}] should be refused, not silently downgraded.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Unsupported document format', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_generated_pdf_is_a_real_paginated_pdf(): void
    {
        $pdf = app(BoardPackAssembler::class)->build($this->org, null, $this->user, 1);

        $this->assertSame('application/pdf', $pdf['mime']);
        $this->assertStringStartsWith('%PDF-', $pdf['content']);
        // A pack with a cover, contents and ten sections is not one page.
        $this->assertGreaterThan(1, substr_count($pdf['content'], '/Type /Page'));
        $this->assertGreaterThan(10_000, strlen($pdf['content']));
    }

    #[Test]
    public function the_custom_report_download_returns_the_requested_format(): void
    {
        Storage::fake('local');

        $response = $this->actingAs($this->user)->post(route('risk.reports.custom.generate'), [
            'report_name' => 'Quarterly register extract',
            'format' => 'xlsx',
        ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('.xlsx"', $response->headers->get('content-disposition'));
        $this->assertStringStartsWith("PK\x03\x04", $response->getContent());
    }

    #[Test]
    public function the_three_standard_reports_download_as_pdf_by_default(): void
    {
        foreach (['risk.reports.executive', 'risk.reports.board', 'risk.reports.regulatory'] as $route) {
            $response = $this->actingAs($this->user)->get(route($route, ['download' => 1]));

            $response->assertOk();
            $response->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent(), "{$route} must return a real PDF.");
        }
    }

    #[Test]
    public function the_standard_reports_still_render_on_screen_without_the_download_flag(): void
    {
        foreach (['risk.reports.executive', 'risk.reports.board', 'risk.reports.regulatory'] as $route) {
            $this->actingAs($this->user)->get(route($route))->assertOk();
        }
    }

    #[Test]
    public function the_executive_report_offers_xlsx_as_an_alternate_format(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('risk.reports.executive', ['download' => 1, 'format' => 'xlsx']));

        $response->assertOk();
        $this->assertStringStartsWith("PK\x03\x04", $response->getContent());
    }

    /* ------------------------------------------------------------------ */
    /*  Board pack */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function board_pack_sections_default_to_the_full_ordered_set(): void
    {
        $sections = app(BoardPackAssembler::class)->sectionsFor($this->org);

        $this->assertSame(BoardPackAssembler::DEFAULT_SECTIONS, $sections);
        $this->assertContains('executive_summary', $sections);
        $this->assertContains('appendices', $sections);
    }

    #[Test]
    public function board_pack_sections_are_configurable_and_reorderable_per_organization(): void
    {
        $assembler = app(BoardPackAssembler::class);

        $assembler->configureSections($this->org, ['appetite_position', 'top_risks', 'executive_summary']);

        $this->assertSame(
            ['appetite_position', 'top_risks', 'executive_summary'],
            $assembler->sectionsFor($this->org->fresh())
        );
    }

    #[Test]
    public function an_unknown_section_key_is_dropped_rather_than_breaking_the_pack(): void
    {
        $assembler = app(BoardPackAssembler::class);

        $assembler->configureSections($this->org, ['top_risks', 'a_section_that_was_removed']);

        $this->assertSame(['top_risks'], $assembler->sectionsFor($this->org->fresh()));
    }

    #[Test]
    public function board_packs_are_versioned_per_organization(): void
    {
        $assembler = app(BoardPackAssembler::class);

        $this->assertSame(1, $assembler->nextVersion($this->org->id));

        GeneratedReport::create([
            'organization_id' => $this->org->id,
            'name' => 'Board Risk Report',
            'report_type' => 'board_pack',
            'scope' => 'board_pack',
            'version' => 1,
            'status' => 'completed',
        ]);

        $this->assertSame(2, $assembler->nextVersion($this->org->id));
    }

    /* ------------------------------------------------------------------ */
    /*  Queue and stored artifact */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function requesting_a_report_queues_a_job_rather_than_rendering_inline(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->post(route('risk.reports.queue'), [
                'report_type' => 'board_pack',
                'as_at' => now()->toDateString(),
            ])
            ->assertRedirect();

        Queue::assertPushed(GenerateReportJob::class);

        $report = GeneratedReport::firstOrFail();
        $this->assertSame('queued', $report->status);
        $this->assertSame(0, $report->progress_pct);
        $this->assertSame($this->org->id, $report->organization_id);
    }

    #[Test]
    public function the_job_renders_the_pack_and_files_the_bytes(): void
    {
        Storage::fake('local');

        $report = GeneratedReport::create([
            'organization_id' => $this->org->id,
            'generated_by' => $this->user->id,
            'name' => 'Board Risk Report',
            'report_type' => 'board_pack',
            'scope' => 'board_pack',
            'status' => 'queued',
            'progress_pct' => 0,
            'version' => 1,
            'parameters' => ['format' => 'pdf', 'as_at' => now()->toDateString()],
        ]);

        (new GenerateReportJob($report->id))->handle(
            app(DocumentRenderer::class),
            app(BoardPackAssembler::class),
            app(\App\Services\ReportDataService::class),
        );

        $report->refresh();

        $this->assertSame('completed', $report->status);
        $this->assertSame(100, $report->progress_pct);
        $this->assertSame('pdf', $report->format);
        $this->assertNotNull($report->file_path);
        $this->assertGreaterThan(0, $report->size_bytes);
        $this->assertTrue($report->hasStoredFile());

        Storage::disk('local')->assertExists($report->file_path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($report->file_path));
    }

    #[Test]
    public function downloading_serves_the_stored_bytes_not_a_fresh_render(): void
    {
        Storage::fake('local');

        $report = GeneratedReport::create([
            'organization_id' => $this->org->id,
            'generated_by' => $this->user->id,
            'name' => 'Board Risk Report',
            'report_type' => 'board_pack',
            'scope' => 'board_pack',
            'status' => 'completed',
            'progress_pct' => 100,
            'format' => 'pdf',
            'disk' => 'local',
            'file_path' => 'reports/'.$this->org->id.'/pinned.pdf',
            'file_name' => 'pinned.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 9,
            'version' => 1,
        ]);

        Storage::disk('local')->put($report->file_path, '%PDF-1.4 ');

        // Move the data underneath the report. The download must be unaffected:
        // the pack the board saw stays the pack the board saw.
        Risk::where('organization_id', $this->org->id)->delete();

        $response = $this->actingAs($this->user)->get(route('risk.reports.download', $report));

        $response->assertOk();
        $this->assertSame('%PDF-1.4 ', $response->streamedContent());
    }

    #[Test]
    public function a_report_belonging_to_another_tenant_cannot_be_downloaded(): void
    {
        Storage::fake('local');

        $other = Organization::create([
            'name' => 'Rival Bank PLC',
            'short_name' => 'RIVL',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $foreignReport = GeneratedReport::withoutGlobalScopes()->create([
            'organization_id' => $other->id,
            'name' => 'Their board pack',
            'report_type' => 'board_pack',
            'scope' => 'board_pack',
            'status' => 'completed',
            'disk' => 'local',
            'file_path' => 'reports/'.$other->id.'/theirs.pdf',
            'file_name' => 'theirs.pdf',
            'mime_type' => 'application/pdf',
            'version' => 1,
        ]);

        Storage::disk('local')->put($foreignReport->file_path, '%PDF-1.4 secret');

        // The tenant scope should make it unfindable; either way it must not
        // be served.
        $response = $this->actingAs($this->user)->get(route('risk.reports.download', $foreignReport));

        $this->assertContains($response->status(), [403, 404]);
    }

    #[Test]
    public function a_failing_job_records_why_instead_of_hanging_at_processing(): void
    {
        $report = GeneratedReport::create([
            'organization_id' => $this->org->id,
            'generated_by' => $this->user->id,
            'name' => 'Broken report',
            // Not a type ReportDataService knows, so the build throws.
            'report_type' => 'not_a_real_report',
            'scope' => 'custom',
            'status' => 'queued',
            'version' => 1,
            'parameters' => ['format' => 'pdf'],
        ]);

        try {
            (new GenerateReportJob($report->id))->handle(
                app(DocumentRenderer::class),
                app(BoardPackAssembler::class),
                app(\App\Services\ReportDataService::class),
            );
            $this->fail('The job should rethrow so the queue can retry.');
        } catch (\Throwable $e) {
            // expected
        }

        $report->refresh();
        $this->assertSame('failed', $report->status);
        $this->assertNotNull($report->error_message);
        $this->assertStringContainsString('not_a_real_report', $report->error_message);
    }

    /* ------------------------------------------------------------------ */
    /*  Spreadsheet import */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_real_xlsx_is_parsed_as_a_spreadsheet_not_as_text(): void
    {
        $path = $this->makeXlsx(
            ['Title', 'Description', 'Category'],
            [
                ['Cash handling exposure', 'Branch till differences', 'Operational'],
                ['Vendor concentration', 'Single supplier for core switch', 'Third party'],
            ]
        );

        $reader = app(SpreadsheetReader::class);

        $this->assertTrue($reader->isSpreadsheet($path), 'An .xlsx must be detected as a spreadsheet.');
        $this->assertSame(['Title', 'Description', 'Category'], $reader->headers($path));

        $rows = $reader->dataRows($path);
        $this->assertCount(2, $rows);
        $this->assertSame('Cash handling exposure', $rows[0][0]);
        $this->assertSame('Single supplier for core switch', $rows[1][1]);

        unlink($path);
    }

    #[Test]
    public function the_old_fgetcsv_path_would_have_produced_garbage_from_the_same_file(): void
    {
        $path = $this->makeXlsx(['Title'], [['Cash handling exposure']]);

        // This is what the importer used to do to every accepted file type.
        $handle = fopen($path, 'r');
        $naiveHeaders = fgetcsv($handle);
        fclose($handle);

        $this->assertNotSame(
            ['Title'],
            $naiveHeaders,
            'This assertion documents the bug: fgetcsv cannot read an xlsx. If it ever '
            .'could, the fix would be unnecessary.'
        );

        unlink($path);
    }

    #[Test]
    public function a_csv_still_parses_and_its_bom_is_stripped(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBFTitle,Description\nFX exposure,Naira depreciation\n");

        $reader = app(SpreadsheetReader::class);

        $this->assertFalse($reader->isSpreadsheet($path));
        $this->assertSame(['Title', 'Description'], $reader->headers($path));
        $this->assertSame([['FX exposure', 'Naira depreciation']], $reader->dataRows($path));

        unlink($path);
    }

    #[Test]
    public function entirely_empty_spreadsheet_rows_are_not_imported_as_records(): void
    {
        $path = $this->makeXlsx(
            ['Title'],
            [['Real risk'], ['', ''], ['Another real risk']]
        );

        $rows = app(SpreadsheetReader::class)->dataRows($path);

        $this->assertCount(2, $rows, 'A blank row inside the used range is not a record.');

        unlink($path);
    }

    #[Test]
    public function an_unreadable_upload_reports_the_failure_instead_of_importing_noise(): void
    {
        $this->actingAs($this->user);

        // A file whose magic bytes claim ZIP but which is not a valid archive.
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        file_put_contents($path, "PK\x03\x04not actually a workbook");

        $reader = app(SpreadsheetReader::class);

        $this->expectException(\RuntimeException::class);
        $reader->rows($path);
    }

    /**
     * Build a genuine .xlsx on disk using the same library the renderer writes
     * with, so the reader is exercised against a real workbook.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function makeXlsx(array $headers, array $rows): string
    {
        $content = app(DocumentRenderer::class)->xlsx($headers, $rows, 'Import');

        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        file_put_contents($path, $content);

        return $path;
    }
}

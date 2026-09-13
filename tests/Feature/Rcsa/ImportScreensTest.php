<?php

namespace Tests\Feature\Rcsa;

use App\Jobs\ProcessRcsaImportJob;
use App\Models\Rcsa\RcsaImportBatch;
use App\Models\Rcsa\RcsaImportRow;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Services\Rcsa\RcsaTemplateWriter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;

/**
 * The template download, the upload, the preview screen and who may use them.
 */
class ImportScreensTest extends ImportTestCase
{
    /* ------------------------------------------------------------------ */
    /*  Template */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_template_is_generated_with_this_tenants_own_reference_data(): void
    {
        $response = $this->actingAs($this->actor)->get(route('rcsa.imports.template'));

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $book = IOFactory::load($path);

        $this->assertSame([
            RcsaTemplateWriter::SHEET_INSTRUCTIONS,
            RcsaTemplateWriter::SHEET_UPLOAD,
            RcsaTemplateWriter::SHEET_REFERENCE,
            RcsaTemplateWriter::SHEET_MATRIX,
            RcsaTemplateWriter::SHEET_CE_GRID,
        ], $book->getSheetNames());

        /* --- The upload sheet's header is the file format ------------- */

        $upload = $book->getSheetByName(RcsaTemplateWriter::SHEET_UPLOAD);
        $labels = [];

        foreach (range(1, count(RcsaTemplateWriter::COLUMNS)) as $column) {
            $labels[] = $upload->getCell([$column, 1])->getValue();
        }

        $this->assertSame(
            array_map(fn (array $meta) => $meta['label'], array_values(RcsaTemplateWriter::COLUMNS)),
            $labels
        );

        /* --- The dropdowns list THIS tenant's data -------------------- */

        $reference = $book->getSheetByName(RcsaTemplateWriter::SHEET_REFERENCE);
        $units = [];

        for ($row = 2; $row <= 30; $row++) {
            $value = $reference->getCell('A'.$row)->getValue();

            if ($value === null || $value === '') {
                break;
            }

            $units[] = $value;
        }

        $this->assertContains('Retail Banking', $units);
        $this->assertContains('Treasury', $units);
        $this->assertNotContains('Foreign Operations', $units, "Another tenant's units must not appear.");

        /* --- The marker cells ----------------------------------------- */

        $this->assertSame(RcsaTemplateWriter::VERSION, $reference->getCell('K1')->getValue());
        $this->assertSame((string) $this->organization->id, $reference->getCell('K2')->getValue());

        // Very hidden, so a user who unhides sheets is still not invited to
        // edit the tenant marker by hand.
        $this->assertSame(
            \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VERYHIDDEN,
            $reference->getSheetState()
        );

        /* --- The methodology travels with the file -------------------- */

        $matrix = $book->getSheetByName(RcsaTemplateWriter::SHEET_MATRIX);
        $matrixText = $matrix->toArray();
        $flat = json_encode($matrixText);

        $this->assertStringContainsString('Almost Certain', $flat);
        $this->assertStringContainsString('Very High', $flat);
        // The impact criteria are the point of shipping the matrix at all.
        $this->assertStringContainsString('Health & Safety', $flat);
        $this->assertStringContainsString('Fatality', $flat);

        $grid = json_encode($book->getSheetByName(RcsaTemplateWriter::SHEET_CE_GRID)->toArray());

        $this->assertStringContainsString('Fully Achieved', $grid);
        $this->assertStringContainsString('76% - 100%', $grid);

        $book->disconnectWorksheets();
        @unlink($path);
    }

    #[Test]
    public function the_generated_template_round_trips_through_the_importer(): void
    {
        // The strongest thing that can be said about a generated template: the
        // parser it exists to feed can read it back. A header the writer and
        // the reader disagree about would fail every upload with "missing
        // required columns" on a file the product itself produced.
        $response = $this->actingAs($this->actor)->get(route('rcsa.imports.template'));

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $book = IOFactory::load($path);
        $upload = $book->getSheetByName(RcsaTemplateWriter::SHEET_UPLOAD);

        $row = $this->row();
        $column = 1;

        foreach (array_keys(RcsaTemplateWriter::COLUMNS) as $field) {
            $upload->setCellValueExplicit(
                [$column, 2],
                (string) ($row[$field] ?? ''),
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
            $column++;
        }

        $filled = tempnam(sys_get_temp_dir(), 'filled').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($filled);
        $book->disconnectWorksheets();

        $stored = Storage::disk('local')->putFileAs(
            'rcsa/imports',
            new \Illuminate\Http\File($filled),
            'roundtrip.xlsx'
        );

        $batch = RcsaImportBatch::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->actor->id,
            'type' => 'universe',
            'file_path' => $stored,
            'original_name' => 'roundtrip.xlsx',
            'status' => RcsaImportBatch::QUEUED,
        ]);

        app(\App\Services\Rcsa\RcsaImportProcessor::class)->process($batch);

        $this->assertSame(1, $batch->fresh()->total_rows);
        $this->assertSame(1, $batch->fresh()->valid_rows, json_encode($batch->rows()->first()?->errors));

        @unlink($path);
        @unlink($filled);
    }

    /* ------------------------------------------------------------------ */
    /*  Upload */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_upload_creates_a_batch_and_queues_the_parse_without_writing_anything(): void
    {
        Queue::fake();

        $this->actingAs($this->actor)
            ->post(route('rcsa.imports.store'), ['file' => $this->upload([$this->row()])])
            ->assertRedirect();

        $batch = RcsaImportBatch::sole();

        $this->assertSame(RcsaImportBatch::QUEUED, $batch->status);
        $this->assertSame('universe.xlsx', $batch->original_name);
        $this->assertSame($this->actor->id, $batch->user_id);
        $this->assertSame(0, RcsaRegisterRisk::count());

        Storage::disk('local')->assertExists($batch->file_path);

        Queue::assertPushed(ProcessRcsaImportJob::class, fn ($job) => $job->batchId === $batch->id);
    }

    #[Test]
    public function the_upload_refuses_something_that_is_not_a_spreadsheet(): void
    {
        $this->actingAs($this->actor)
            ->post(route('rcsa.imports.store'), [
                'file' => \Illuminate\Http\UploadedFile::fake()->create('payload.exe', 12, 'application/octet-stream'),
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, RcsaImportBatch::count());
    }

    #[Test]
    public function the_job_records_the_reason_on_the_batch_when_a_file_cannot_be_read(): void
    {
        $header = array_map(fn (array $meta) => $meta['label'], array_values(RcsaTemplateWriter::COLUMNS));
        $header[1] = 'Not The Business Unit Column';

        $file = $this->upload([$this->row()], $header);
        $path = Storage::disk('local')->putFile('rcsa/imports', $file);

        $batch = RcsaImportBatch::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->actor->id,
            'type' => 'universe',
            'file_path' => $path,
            'original_name' => 'broken.xlsx',
            'status' => RcsaImportBatch::QUEUED,
        ]);

        try {
            (new ProcessRcsaImportJob($batch->id))->handle(app(\App\Services\Rcsa\RcsaImportProcessor::class));
        } catch (\Throwable) {
            // The job rethrows so the queue records a failure; the batch is
            // what the user is looking at.
        }

        $batch->refresh();

        $this->assertSame(RcsaImportBatch::FAILED, $batch->status);
        $this->assertStringContainsString('Business Unit', (string) $batch->failure_reason);
    }

    /* ------------------------------------------------------------------ */
    /*  Preview */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_preview_shows_the_tiles_and_the_rows(): void
    {
        $batch = $this->stage([
            $this->row(),
            $this->row(['potential_risk' => 'Too short']),
        ]);

        $this->actingAs($this->actor)
            ->get(route('rcsa.imports.show', $batch))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('RcsaUniverse/ImportPreview')
                ->where('batch.total_rows', 2)
                ->where('batch.valid_rows', 1)
                ->where('batch.error_rows', 1)
                ->where('batch.is_publishable', true)
                ->has('rows.data', 2)
                ->has('columns', count(RcsaTemplateWriter::COLUMNS))
            );
    }

    #[Test]
    public function the_preview_filters_by_status(): void
    {
        $batch = $this->stage([
            $this->row(),
            $this->row(['potential_risk' => 'Too short']),
        ]);

        $this->actingAs($this->actor)
            ->get(route('rcsa.imports.show', ['batch' => $batch, 'status' => 'error']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows.data', 1)
                ->where('rows.data.0.status', RcsaImportRow::ERROR)
            );
    }

    #[Test]
    public function correcting_a_cell_re_checks_the_row_and_updates_the_tiles(): void
    {
        $batch = $this->stage([$this->row(['potential_risk' => 'Too short'])]);

        $this->assertSame(1, $batch->error_rows);

        $row = $batch->rows()->sole();

        $this->actingAs($this->actor)
            ->patch(route('rcsa.imports.rows.update', [$batch, $row]), [
                'values' => ['potential_risk' => 'A statement long enough to be assessed by somebody else.'],
            ])
            ->assertSessionHasNoErrors();

        $row->refresh();
        $batch->refresh();

        $this->assertSame(RcsaImportRow::VALID, $row->status);
        // The tiles and the grid are the same numbers and must not disagree.
        $this->assertSame(0, $batch->error_rows);
        $this->assertSame(1, $batch->valid_rows);
    }

    #[Test]
    public function a_correction_is_held_to_the_same_rules_the_file_was(): void
    {
        $batch = $this->stage([$this->row()]);
        $row = $batch->rows()->sole();

        $this->actingAs($this->actor)
            ->patch(route('rcsa.imports.rows.update', [$batch, $row]), [
                'values' => ['business_unit' => 'A Unit That Does Not Exist'],
            ]);

        $this->assertSame(RcsaImportRow::ERROR, $row->fresh()->status);
    }

    #[Test]
    public function a_row_from_another_batch_is_not_found(): void
    {
        $mine = $this->stage([$this->row()]);
        $other = $this->stage([$this->row(['potential_risk' => 'An entirely separate risk in a separate batch.'])]);

        $this->actingAs($this->actor)
            ->patch(route('rcsa.imports.rows.update', [$mine, $other->rows()->sole()]), [
                'values' => ['risk_driver' => 'Tampering with a row from another batch.'],
            ])
            ->assertNotFound();
    }

    #[Test]
    public function the_annotated_workbook_gives_the_user_back_their_own_spelling(): void
    {
        $batch = $this->stage([
            $this->row(['business_unit' => 'Zzzz Department', 'potential_risk' => 'A row that will not resolve to any unit.']),
        ]);

        $response = $this->actingAs($this->actor)->get(route('rcsa.imports.errors', $batch));

        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'err').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $sheet = IOFactory::load($path)->getActiveSheet();
        $contents = json_encode($sheet->toArray());

        // Their spelling, not the normalised value — telling somebody row 2 is
        // wrong while showing a value they never typed is how a bulk upload
        // loses whatever trust it had.
        $this->assertStringContainsString('Zzzz Department', $contents);
        $this->assertStringContainsString('Errors', $contents);
        $this->assertStringContainsString('No business unit called', $contents);

        @unlink($path);
    }

    /* ------------------------------------------------------------------ */
    /*  Publishing over HTTP, and who may */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function publishing_from_the_preview_creates_the_risks(): void
    {
        $batch = $this->stage([$this->row()]);

        $this->actingAs($this->actor)
            ->post(route('rcsa.imports.publish', $batch), ['mode' => 'create'])
            ->assertRedirect(route('rcsa.universe.index'));

        $this->assertSame(1, RcsaRegisterRisk::count());
        $this->assertSame(RcsaImportBatch::PUBLISHED, $batch->fresh()->status);
        $this->assertSame($this->actor->id, $batch->fresh()->published_by);
    }

    #[Test]
    public function publishing_a_batch_with_errors_is_refused_with_an_explanation(): void
    {
        $batch = $this->stage([$this->row(['potential_risk' => 'Too short'])]);

        $this->actingAs($this->actor)
            ->post(route('rcsa.imports.publish', $batch))
            ->assertSessionHas('error');

        $this->assertSame(0, RcsaRegisterRisk::count());
        $this->assertSame(RcsaImportBatch::VALIDATED, $batch->fresh()->status);
    }

    #[Test]
    public function uploading_and_publishing_are_different_authorities(): void
    {
        $batch = $this->stage([$this->row()]);

        // An importer who cannot publish: they may prepare the file and see
        // the preview, and someone else approves what it does to the master
        // data. §14 Q8 asks the bank the same question about the screen.
        $importer = $this->userWith(['rcsa_universe.view', 'rcsa_universe.import', 'rcsa_universe.create']);

        $this->actingAs($importer)->get(route('rcsa.imports.show', $batch))->assertOk();
        $this->actingAs($importer)->post(route('rcsa.imports.publish', $batch))->assertForbidden();

        $this->assertSame(0, RcsaRegisterRisk::count());
    }

    #[Test]
    public function a_user_without_the_import_permission_cannot_upload_or_preview(): void
    {
        $batch = $this->stage([$this->row()]);
        $viewer = $this->userWith(['rcsa_universe.view']);

        $this->actingAs($viewer)->post(route('rcsa.imports.store'), ['file' => $this->upload([$this->row()])])->assertForbidden();
        $this->actingAs($viewer)->get(route('rcsa.imports.show', $batch))->assertForbidden();
        $this->actingAs($viewer)->delete(route('rcsa.imports.destroy', $batch))->assertForbidden();

        // The template itself is readable by anyone who can see the universe:
        // it contains no risk data, and refusing it would stop people
        // preparing a file for somebody else to upload.
        $this->actingAs($viewer)->get(route('rcsa.imports.template'))->assertOk();
    }

    #[Test]
    public function another_tenants_batch_is_not_found(): void
    {
        $batch = \ThirdLine\Platform\Tenancy\TenantContext::bypass(fn () => RcsaImportBatch::create([
            'organization_id' => $this->otherOrg->id,
            'user_id' => $this->actor->id,
            'type' => 'universe',
            'file_path' => 'rcsa/imports/foreign.xlsx',
            'original_name' => 'foreign.xlsx',
            'status' => RcsaImportBatch::VALIDATED,
        ]));

        $this->actingAs($this->actor)->get(route('rcsa.imports.show', $batch->id))->assertNotFound();
        $this->actingAs($this->actor)->post(route('rcsa.imports.publish', $batch->id))->assertNotFound();
    }

    #[Test]
    public function discarding_removes_the_file_and_writes_nothing(): void
    {
        $batch = $this->stage([$this->row()]);

        Storage::disk('local')->assertExists($batch->file_path);

        $this->actingAs($this->actor)
            ->delete(route('rcsa.imports.destroy', $batch))
            ->assertRedirect(route('rcsa.universe.index'));

        // The file is the bank's risk profile sitting on disk; a discarded
        // upload has no further use.
        Storage::disk('local')->assertMissing($batch->file_path);
        $this->assertSoftDeleted($batch);
        $this->assertSame(0, RcsaRegisterRisk::count());
    }

    #[Test]
    public function the_import_routes_do_not_exist_when_the_flag_is_off(): void
    {
        $batch = $this->stage([$this->row()]);

        config()->set('features.rcsa_v2', false);

        $this->actingAs($this->actor)->get(route('rcsa.imports.template'))->assertNotFound();
        $this->actingAs($this->actor)->get(route('rcsa.imports.show', $batch))->assertNotFound();
        $this->actingAs($this->actor)->post(route('rcsa.imports.publish', $batch))->assertNotFound();
    }
}

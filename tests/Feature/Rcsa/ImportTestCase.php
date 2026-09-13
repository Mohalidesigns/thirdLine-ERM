<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaImportBatch;
use App\Services\Rcsa\RcsaTemplateWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Fixtures for the import pipeline: a helper that builds a real .xlsx in the
 * template's own column order, and one that stages it through the processor.
 *
 * THE FIXTURE IS A REAL WORKBOOK, not an array handed straight to the
 * validator. The header mapping, the blank-row skip, the row numbering and
 * PhpSpreadsheet's own type coercion are all things that go wrong, and a test
 * that skips the file has skipped the half of the pipeline most likely to be
 * broken.
 */
abstract class ImportTestCase extends UniverseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * A row keyed by the template's field names, with sensible defaults.
     *
     * @param  array<string, string|null>  $overrides
     * @return array<string, string|null>
     */
    protected function row(array $overrides = []): array
    {
        return array_merge([
            'risk_no' => '',
            'business_unit' => 'Retail Banking',
            'process' => 'Customer Onboarding',
            'sub_process' => 'KYC Verification',
            'system' => '',
            'potential_risk' => 'Customer accounts are opened without complete KYC documentation.',
            'risk_driver' => 'Manual document checks under branch queue pressure.',
            'risk_category' => 'Compliance/Regulatory',
            'secondary_categories' => '',
            'existing_control' => 'Dual review of account opening packs before activation.',
            'control_type' => 'detective',
            'control_frequency' => 'daily',
            'control_owner' => '',
        ], $overrides);
    }

    /**
     * Build a workbook in the template's column order and return its bytes.
     *
     * @param  list<array<string, string|null>>  $rows
     * @param  list<string>|null  $header  Override the header row (to test a broken file).
     */
    protected function workbook(array $rows, ?array $header = null): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(RcsaTemplateWriter::SHEET_UPLOAD);

        $fields = array_keys(RcsaTemplateWriter::COLUMNS);
        $labels = $header ?? array_map(
            fn (array $meta) => $meta['label'],
            array_values(RcsaTemplateWriter::COLUMNS)
        );

        foreach ($labels as $index => $label) {
            $sheet->setCellValue([$index + 1, 1], $label);
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($fields as $columnIndex => $field) {
                $sheet->setCellValueExplicit(
                    [$columnIndex + 1, $rowIndex + 2],
                    (string) ($row[$field] ?? ''),
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
            }
        }

        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $contents = (string) ob_get_clean();

        $spreadsheet->disconnectWorksheets();

        return $contents;
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     */
    protected function upload(array $rows, ?array $header = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rcsa').'.xlsx';
        file_put_contents($path, $this->workbook($rows, $header));

        return new UploadedFile($path, 'universe.xlsx', null, null, true);
    }

    /**
     * Create a batch from rows and run the processor synchronously.
     *
     * @param  list<array<string, string|null>>  $rows
     */
    protected function stage(array $rows, ?array $header = null): RcsaImportBatch
    {
        $file = $this->upload($rows, $header);
        $path = Storage::disk('local')->putFile('rcsa/imports', $file);

        $batch = RcsaImportBatch::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->actor->id,
            'type' => 'universe',
            'file_path' => $path,
            'original_name' => 'universe.xlsx',
            'template_version' => RcsaTemplateWriter::VERSION,
            'status' => RcsaImportBatch::QUEUED,
        ]);

        app(\App\Services\Rcsa\RcsaImportProcessor::class)->process($batch);

        return $batch->refresh();
    }
}

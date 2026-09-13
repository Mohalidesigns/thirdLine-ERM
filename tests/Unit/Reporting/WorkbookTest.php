<?php

namespace Tests\Unit\Reporting;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * Multi-sheet workbooks — the shape a Register of Information has to be.
 *
 * `packages/reporting/tests` is a Testbench suite that this application's
 * phpunit.xml does not include, so a test written there would never run in CI.
 * These live in the app tree, where they do.
 *
 * THE ASSERTIONS READ THE FILE BACK. Asserting the bytes start with "PK" only
 * proves a zip was produced; a workbook that silently dropped fourteen of its
 * fifteen sheets passes that and fails a regulator.
 */
class WorkbookTest extends TestCase
{
    #[Test]
    public function every_sheet_reaches_the_file_with_its_rows(): void
    {
        $bytes = app(DocumentRenderer::class)->workbook([
            ['name' => 'RT.01.01', 'headers' => ['LEI', 'Name'], 'rows' => [['5493001', 'Abuja Mercantile Bank']]],
            ['name' => 'RT.02.01', 'headers' => ['Reference', 'Provider'], 'rows' => [
                ['CTR-1', 'Cloudspan'],
                ['CTR-2', 'Switchpoint'],
            ]],
        ]);

        $sheets = $this->read($bytes);

        $this->assertSame(['RT.01.01', 'RT.02.01'], array_keys($sheets));
        $this->assertSame('Abuja Mercantile Bank', $sheets['RT.01.01'][1][1]);
        $this->assertCount(3, $sheets['RT.02.01']); // header plus two rows
    }

    #[Test]
    public function two_long_titles_that_truncate_to_the_same_31_characters_stay_distinct(): void
    {
        // Excel refuses a workbook with two sheets of one name, and fifteen
        // regulatory table titles truncated to 31 characters is precisely how
        // that collision arrives.
        $bytes = app(DocumentRenderer::class)->workbook([
            ['name' => 'Contractual arrangements — general', 'headers' => ['A'], 'rows' => [['1']]],
            ['name' => 'Contractual arrangements — specific', 'headers' => ['A'], 'rows' => [['2']]],
        ]);

        $names = array_keys($this->read($bytes));

        $this->assertCount(2, $names);
        $this->assertNotSame($names[0], $names[1]);
        foreach ($names as $name) {
            $this->assertLessThanOrEqual(31, mb_strlen($name));
        }
    }

    #[Test]
    public function a_sheet_without_headers_is_written_as_prose_not_as_a_table(): void
    {
        // The DORA cover sheet: a caveat to read, not a grid to sort.
        $bytes = app(DocumentRenderer::class)->workbook([
            ['name' => 'Cover', 'rows' => [
                ['Register of Information'],
                ['Verify field cardinality against Commission Implementing Regulation (EU) 2024/2956.'],
            ]],
        ]);

        $sheets = $this->read($bytes, keepObjects: true);

        $this->assertNull($sheets['Cover']->getAutoFilter()->getRange() ?: null);
        $this->assertSame('Register of Information', $sheets['Cover']->getCell('A1')->getValue());
    }

    #[Test]
    public function a_workbook_with_no_sheets_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(DocumentRenderer::class)->workbook([]);
    }

    #[Test]
    public function the_csv_path_carries_its_provenance_stamp_above_the_table(): void
    {
        $csv = app(DocumentRenderer::class)->csv(
            ['Provider', 'Tier'],
            [['Cloudspan', 'Critical']],
            ['Prepared by' => 'Chidinma Okafor', 'Reviewed by' => 'Not reviewed'],
        );

        $lines = array_values(array_filter(explode("\n", $csv), fn ($line) => trim($line) !== ''));

        $this->assertStringContainsString('Prepared by', $lines[0]);
        $this->assertStringContainsString('Not reviewed', $lines[1]);
        $this->assertStringContainsString('Provider,Tier', $lines[2]);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $bytes, bool $keepObjects = false): array
    {
        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        file_put_contents($path, $bytes);

        $spreadsheet = (new XlsxReader)->load($path);
        unlink($path);

        $result = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $result[$sheet->getTitle()] = $keepObjects ? $sheet : $sheet->toArray();
        }

        return $result;
    }
}

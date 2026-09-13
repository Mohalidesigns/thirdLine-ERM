<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaImportBatch;
use App\Models\Rcsa\RcsaImportRow;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The user's own file back, with an Errors column and the offending cells
 * shaded (§7.2).
 *
 * IT WRITES THE RAW VALUES, NOT THE NORMALISED ONES. Telling somebody "row 42
 * is wrong" while showing them a value they never typed is how a bulk upload
 * loses whatever trust it had — the file has to look like the file they
 * uploaded, with their spelling in it, so they can find the cell.
 *
 * Every row is included, not only the failures. A workbook containing just the
 * six bad rows out of two hundred cannot be corrected and re-uploaded; one
 * containing all two hundred can, which is the point of handing it back.
 */
class RcsaErrorWorkbookWriter
{
    private const ERROR_FILL = 'F8CBAD';

    private const WARNING_FILL = 'FFE699';

    private const DUPLICATE_FILL = 'D9E1F2';

    public function write(RcsaImportBatch $batch): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(RcsaTemplateWriter::SHEET_UPLOAD);

        $fields = array_keys(RcsaTemplateWriter::COLUMNS);

        /* --- Header ---------------------------------------------------- */

        $column = 1;

        foreach (RcsaTemplateWriter::COLUMNS as $meta) {
            $sheet->setCellValue($this->letter($column).'1', $meta['label']);
            $sheet->getColumnDimension($this->letter($column))->setWidth(28);
            $column++;
        }

        $statusColumn = $this->letter($column);
        $errorsColumn = $this->letter($column + 1);

        $sheet->setCellValue($statusColumn.'1', 'Status');
        $sheet->setCellValue($errorsColumn.'1', 'Errors');
        $sheet->getColumnDimension($statusColumn)->setWidth(14);
        $sheet->getColumnDimension($errorsColumn)->setWidth(90);

        $sheet->getStyle(sprintf('A1:%s1', $errorsColumn))->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F3864']],
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->freezePane('A2');

        /* --- Rows ------------------------------------------------------- */

        $row = 2;

        foreach ($batch->rows()->orderBy('row_number')->cursor() as $staged) {
            $raw = $staged->raw ?? [];
            $failedFields = array_column($staged->errors ?? [], 'field');

            $column = 1;

            foreach ($fields as $field) {
                $cell = $this->letter($column).$row;

                $sheet->setCellValueExplicit(
                    $cell,
                    (string) ($raw[$field] ?? ''),
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );

                if (in_array($field, $failedFields, true)) {
                    $sheet->getStyle($cell)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB($this->fillFor($staged->status));
                }

                $column++;
            }

            $sheet->setCellValue($statusColumn.$row, ucfirst($staged->status));
            $sheet->setCellValue($errorsColumn.$row, implode("\n", $staged->messages()));
            $sheet->getStyle($errorsColumn.$row)->getAlignment()->setWrapText(true);

            if ($staged->status !== RcsaImportRow::VALID) {
                $sheet->getStyle($statusColumn.$row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($this->fillFor($staged->status));
            }

            $row++;
        }

        /* --- A legend, because a shaded cell means nothing on its own --- */

        $legendRow = $row + 2;
        $sheet->setCellValue('A'.$legendRow, 'How to read this file');
        $sheet->getStyle('A'.$legendRow)->getFont()->setBold(true);

        foreach ([
            'Error — this row was not published. Fix the shaded cells and upload this file again.',
            'Warning — this row WAS published, but with less than you asked for. See the Errors column.',
            'Duplicate — this risk is already in the universe. It was skipped unless you chose to update.',
            'Delete the Status and Errors columns before re-uploading, or leave them; they are ignored.',
        ] as $offset => $line) {
            $sheet->setCellValue('A'.($legendRow + 1 + $offset), $line);
        }

        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $contents = (string) ob_get_clean();

        $spreadsheet->disconnectWorksheets();

        return $contents;
    }

    private function fillFor(string $status): string
    {
        return match ($status) {
            RcsaImportRow::ERROR => self::ERROR_FILL,
            RcsaImportRow::DUPLICATE => self::DUPLICATE_FILL,
            default => self::WARNING_FILL,
        };
    }

    private function letter(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }
}

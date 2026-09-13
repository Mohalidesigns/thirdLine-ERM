<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use RuntimeException;

/**
 * Reads an uploaded data file into rows, choosing the parser by what the file
 * actually is.
 *
 * The import screen accepted csv, xlsx and xls and then parsed all three with
 * fgetcsv(). An .xlsx is a ZIP archive; fed to fgetcsv it yields binary noise,
 * which the importer then dutifully wrote into the risk register as records —
 * a silent data-corruption path with a success message on the end of it.
 *
 * The format is decided by sniffing the file, not by trusting the extension:
 * a spreadsheet saved as "risks.csv" out of Excel is a real and common thing.
 */
class SpreadsheetReader
{
    /**
     * Rows returned before a file is considered too large to hold in memory.
     * The importer is a synchronous screen; a file bigger than this belongs in
     * a queued import rather than a request.
     */
    private const MAX_ROWS = 50_000;

    /**
     * Read a file into a list of rows, each row a list of cell values.
     *
     * The header row is included as the first element, matching what the
     * mapping screen expects.
     *
     * @return list<list<string|null>>
     */
    public function rows(string $path, ?string $sheetName = null): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException('The uploaded file could not be read from storage.');
        }

        return $this->isSpreadsheet($path)
            ? $this->readSpreadsheet($path, $sheetName)
            : $this->readDelimited($path);
    }

    /**
     * Just the header row, for the column-mapping screen.
     *
     * @return list<string>
     */
    public function headers(string $path): array
    {
        $rows = $this->rows($path);

        return array_map(
            fn ($value) => trim((string) $value),
            $rows[0] ?? []
        );
    }

    /**
     * Rows below the header.
     *
     * @return list<list<string|null>>
     */
    public function dataRows(string $path): array
    {
        $rows = $this->rows($path);
        array_shift($rows);

        // Drop rows that are entirely empty. A spreadsheet's "used range"
        // routinely extends past the last real record, and an empty row
        // imported as a record becomes a blank risk in the register.
        return array_values(array_filter(
            $rows,
            fn (array $row) => collect($row)->contains(fn ($cell) => $cell !== null && trim((string) $cell) !== '')
        ));
    }

    /**
     * Whether the file is a real spreadsheet rather than delimited text.
     *
     * xlsx/ods are ZIP archives (PK\x03\x04); legacy xls is an OLE2 compound
     * file (\xD0\xCF\x11\xE0). Anything else is treated as delimited text.
     */
    public function isSpreadsheet(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $magic = fread($handle, 8);
        fclose($handle);

        if ($magic === false || strlen($magic) < 4) {
            return false;
        }

        return str_starts_with($magic, "PK\x03\x04")
            || str_starts_with($magic, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
    }

    /**
     * @return list<list<string|null>>
     */
    /**
     * @param  string|null  $sheetName  Read this sheet when the workbook has it.
     */
    private function readSpreadsheet(string $path, ?string $sheetName = null): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            // Formatting and formulas are irrelevant to an import and cost a
            // great deal of memory on a large workbook.
            $reader->setReadDataOnly(true);

            $spreadsheet = $reader->load($path);
        } catch (ReaderException $e) {
            throw new RuntimeException(
                'The spreadsheet could not be read: '.$e->getMessage()
            );
        }

        // NAMED SHEET FIRST, active sheet second.
        //
        // A multi-sheet template is saved with whichever tab the user last
        // clicked left active — the RCSA template opens on its Instructions
        // sheet, so reading the active sheet parsed the instructions as a
        // header row and rejected the product's own template on every upload.
        // A caller that knows which sheet carries the data says so; callers
        // that do not are unaffected.
        $sheet = $sheetName !== null && $spreadsheet->sheetNameExists($sheetName)
            ? $spreadsheet->getSheetByName($sheetName)
            : $spreadsheet->getActiveSheet();

        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }

            $cells = $row->getCellIterator();
            // Blanks inside the used range must still occupy their column,
            // otherwise every cell after a gap shifts left and the column
            // mapping silently addresses the wrong field.
            $cells->setIterateOnlyExistingCells(false);

            $values = [];
            foreach ($cells as $cell) {
                $value = $cell->getValue();
                $values[] = is_scalar($value) || $value === null
                    ? ($value === null ? null : (string) $value)
                    : (string) $cell->getFormattedValue();
            }

            $rows[] = $values;
        }

        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    /**
     * @return list<list<string|null>>
     */
    private function readDelimited(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be opened.');
        }

        $rows = [];
        $first = true;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }

            // fgetcsv returns [null] for a blank line.
            if ($row === [null]) {
                continue;
            }

            if ($first) {
                // Strip a UTF-8 BOM from the first header cell, otherwise the
                // first column never matches its mapping.
                if (isset($row[0]) && str_starts_with($row[0], "\xEF\xBB\xBF")) {
                    $row[0] = substr($row[0], 3);
                }
                $first = false;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}

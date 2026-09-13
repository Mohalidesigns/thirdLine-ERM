<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaImportBatch;
use App\Models\Rcsa\RcsaImportRow;
use App\Services\SpreadsheetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Parses an uploaded template into `rcsa_import_rows` and nowhere else.
 *
 * NOTHING HERE TOUCHES THE UNIVERSE. That is the whole design: the process-flow
 * specification requires errors, duplicates and incomplete information to be
 * flagged BEFORE the data is published, and a parser that writes as it reads
 * cannot offer that. RcsaImportPublisher is the only class that writes to
 * `rcsa_register_risks`, and it runs after a human has seen the preview.
 *
 * ROWS ARE STAGED IN CHUNKS OF 500 (§7.2). The reason is memory, not speed: a
 * 5,000-row file with a 2,000-character risk statement per row is enough to
 * push a worker past its memory limit if every staged row is held until the
 * end, and a job that dies at row 4,000 with nothing written is a file the user
 * has to upload again with no idea why.
 */
class RcsaImportProcessor
{
    private const CHUNK = 500;

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly RcsaImportNormaliser $normaliser,
        private readonly RcsaImportValidator $validator,
    ) {}

    /**
     * Parse, normalise, validate and stage the batch's file.
     *
     * @param  callable(int, int): void|null  $onProgress
     * @param  list<int>|null  $authorisedUnitIds
     * @return array{total: int, valid: int, warning: int, error: int, duplicate: int}
     */
    public function process(RcsaImportBatch $batch, ?callable $onProgress = null, ?array $authorisedUnitIds = null): array
    {
        $batch->update(['status' => RcsaImportBatch::PARSING]);

        $path = Storage::disk('local')->path($batch->file_path);

        if (! is_readable($path)) {
            throw new RuntimeException('The uploaded file could not be read from storage.');
        }

        // Named, not the active sheet: the template opens on Instructions,
        // and a user saving with any other tab selected must still import.
        $rows = $this->reader->rows($path, RcsaTemplateWriter::SHEET_UPLOAD);

        if ($rows === []) {
            throw new RuntimeException('The file is empty.');
        }

        $map = $this->mapHeader(array_shift($rows));

        // Clear any previous staging for this batch, so a re-parse (after the
        // user corrected the file and the job was re-dispatched) does not leave
        // the first attempt's rows behind alongside the second's.
        $batch->rows()->delete();

        $total = count($rows);
        $buffer = [];
        $processed = 0;

        foreach ($rows as $index => $cells) {
            // +2: the header was shifted off, and spreadsheet rows are 1-based.
            // The number in an error message must be the row the user sees in
            // Excel, not an array index only the parser understands.
            $rowNumber = $index + 2;

            if ($this->isBlankRow($cells)) {
                $processed++;

                continue;
            }

            $raw = $this->extract($cells, $map);
            ['values' => $values, 'notes' => $notes] = $this->normaliser->normalise($raw);
            $verdict = $this->validator->check($values, $notes, $authorisedUnitIds);

            $buffer[] = [
                'batch_id' => $batch->id,
                'row_number' => $rowNumber,
                'raw' => json_encode($raw),
                'normalised' => json_encode($values),
                'status' => $verdict['status'],
                'errors' => json_encode($verdict['errors']),
                'target_id' => $verdict['target_id'],
                'action' => $verdict['action'],
                'row_hash' => $this->hashOf($values),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $processed++;

            if (count($buffer) >= self::CHUNK) {
                RcsaImportRow::insert($buffer);
                $buffer = [];

                if ($onProgress !== null) {
                    $onProgress($processed, $total);
                }
            }
        }

        if ($buffer !== []) {
            RcsaImportRow::insert($buffer);
        }

        if ($onProgress !== null) {
            $onProgress($processed, $total);
        }

        $counts = $this->recount($batch);

        $batch->update(['status' => RcsaImportBatch::VALIDATED]);

        return $counts;
    }

    /**
     * Recompute and store the batch's summary tiles.
     *
     * Called after staging and again after any inline correction, because the
     * preview's tiles and its tab counts are the same numbers and must not
     * disagree with the grid beneath them.
     *
     * @return array{total: int, valid: int, warning: int, error: int, duplicate: int}
     */
    public function recount(RcsaImportBatch $batch): array
    {
        $byStatus = $batch->rows()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $counts = [
            'total' => array_sum($byStatus),
            'valid' => (int) ($byStatus[RcsaImportRow::VALID] ?? 0),
            'warning' => (int) ($byStatus[RcsaImportRow::WARNING] ?? 0),
            'error' => (int) ($byStatus[RcsaImportRow::ERROR] ?? 0),
            'duplicate' => (int) ($byStatus[RcsaImportRow::DUPLICATE] ?? 0),
        ];

        $batch->update([
            'total_rows' => $counts['total'],
            'valid_rows' => $counts['valid'],
            'warning_rows' => $counts['warning'],
            'error_rows' => $counts['error'],
            'duplicate_rows' => $counts['duplicate'],
        ]);

        return $counts;
    }

    /**
     * Re-check one staged row after the user corrected a cell on the preview.
     *
     * The row is normalised and validated again from its raw values, exactly as
     * it would have been on upload — so an inline correction cannot produce a
     * row that would have failed had it been in the file.
     *
     * @param  array<string, string|null>  $raw
     */
    public function revalidate(RcsaImportRow $row, array $raw, ?array $authorisedUnitIds = null): RcsaImportRow
    {
        ['values' => $values, 'notes' => $notes] = $this->normaliser->normalise($raw);
        $verdict = $this->validator->check($values, $notes, $authorisedUnitIds);

        $row->update([
            'raw' => $raw,
            'normalised' => $values,
            'status' => $verdict['status'],
            'errors' => $verdict['errors'],
            'target_id' => $verdict['target_id'],
            // Keep an explicit `update` the user chose; otherwise take the
            // verdict's action. Re-validating a row must not silently undo the
            // decision the user made about it on the preview screen.
            'action' => $row->action === RcsaImportRow::UPDATE && $verdict['target_id'] !== null
                ? RcsaImportRow::UPDATE
                : $verdict['action'],
            'row_hash' => $this->hashOf($values),
        ]);

        return $row->refresh();
    }

    /* ------------------------------------------------------------------ */
    /*  Header mapping */
    /* ------------------------------------------------------------------ */

    /**
     * Map the file's header row onto the template's field names.
     *
     * Matched on the LABEL, loosely: lower-cased with punctuation and spacing
     * stripped, so "Risk No." and "risk no" and "RISK NO" are one column. A
     * column the template does not define is ignored rather than rejected —
     * users add notes columns, and refusing the file over one would be
     * gratuitous.
     *
     * @param  list<string|null>  $header
     * @return array<int, string> Column index => field name.
     */
    private function mapHeader(array $header): array
    {
        $wanted = [];

        foreach (RcsaTemplateWriter::COLUMNS as $field => $meta) {
            $wanted[$this->slug($meta['label'])] = $field;
        }

        $map = [];

        foreach ($header as $index => $label) {
            $slug = $this->slug((string) $label);

            if (isset($wanted[$slug])) {
                $map[$index] = $wanted[$slug];
            }
        }

        $missing = array_diff(
            array_keys(array_filter(RcsaTemplateWriter::COLUMNS, fn (array $meta) => $meta['required'])),
            array_values($map)
        );

        if ($missing !== []) {
            throw new RuntimeException(
                'This file is missing required columns: '.implode(', ', array_map(
                    fn (string $field) => RcsaTemplateWriter::COLUMNS[$field]['label'],
                    $missing
                )).'. Download a fresh template and copy your data into it.'
            );
        }

        return $map;
    }

    private function slug(string $label): string
    {
        return Str::lower(preg_replace('/[^a-z0-9]+/i', '', $label) ?? '');
    }

    /**
     * @param  list<string|null>  $cells
     * @param  array<int, string>  $map
     * @return array<string, string|null>
     */
    private function extract(array $cells, array $map): array
    {
        $raw = [];

        foreach (RcsaTemplateWriter::COLUMNS as $field => $meta) {
            $raw[$field] = null;
        }

        foreach ($map as $index => $field) {
            $value = $cells[$index] ?? null;
            $raw[$field] = $value === null ? null : (string) $value;
        }

        return $raw;
    }

    /**
     * @param  list<string|null>  $cells
     */
    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function hashOf(array $values): ?string
    {
        if (($values['business_unit_id'] ?? null) === null || blank($values['potential_risk'] ?? null)) {
            return null;
        }

        return \App\Models\Rcsa\RcsaRegisterRisk::hashFor(
            (int) $values['business_unit_id'],
            $values['process_id'] ?? null,
            $values['sub_process_id'] ?? null,
            (string) $values['potential_risk'],
        );
    }
}

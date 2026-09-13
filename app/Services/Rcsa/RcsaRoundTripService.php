<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaImportBatch;
use App\Models\Rcsa\RcsaImportRow;
use App\Models\Rcsa\RcsaMethodology;
use App\Models\Rcsa\RcsaScaleItem;
use App\Models\User;
use App\Services\SpreadsheetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * §10.4 — take a working copy away on a laptop, fill it in with no
 * connectivity, upload it back.
 *
 * "A genuine differentiator in the Nigerian market where branch connectivity is
 * unreliable." What makes it safe rather than merely possible is three rules,
 * and all three are here:
 *
 *   1. **It matches on the hidden line id, never on the risk number.** Two
 *      units can share a numbering scheme, and a risk statement can be
 *      corrected between the download and the upload. The id is the only thing
 *      about a row that does not change.
 *
 *   2. **It reads back five columns and discards the other eighteen.**
 *      `ROUND_TRIP_FIELDS` is the whitelist. Everything else in the file is
 *      either computed by the engine or snapshotted master data, and accepting
 *      it would let a spreadsheet rewrite a business unit, a risk statement or
 *      a residual score. The greyed columns in the file are a courtesy; this is
 *      the enforcement.
 *
 *   3. **A line somebody else changed since the export is NOT overwritten.**
 *      The file carries the `version` each row was exported at — the same
 *      optimistic-locking counter the grid sends — so a line whose version has
 *      moved was written by somebody in between. Those rows are staged as
 *      conflicts, default to KEEPING THE SERVER'S ANSWER, and the user resolves
 *      them one at a time. Silently applying a fortnight-old offline file over
 *      a colleague's morning is the single worst thing this feature could do.
 *
 * NOTHING IS APPLIED UNTIL THE USER CONFIRMS, exactly as the universe import
 * works: parse and stage here, write in `apply()`, and the preview in between
 * is the whole point.
 *
 * THE WRITE GOES THROUGH RcsaAssessmentService::apply(), never straight to the
 * line. That is what keeps the round trip inside every rule the module has —
 * the engine recomputes, the revision trail records, the version bumps, and a
 * line the ORM did not reopen is refused with `LOCKED`.
 */
class RcsaRoundTripService
{
    /** A row whose line was changed by somebody else since the export. */
    public const CONFLICT = 'conflict';

    /** A row whose line is frozen — submitted, or returned without being flagged. */
    public const LOCKED = 'locked';

    /** Keep the value in my file. */
    public const ACTION_MINE = 'update';

    /** Keep what is on the server. */
    public const ACTION_THEIRS = 'skip';

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly RcsaAssessmentService $assessments,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Parse and stage */
    /* ------------------------------------------------------------------ */

    /**
     * Read the uploaded working copy into `rcsa_import_rows`.
     *
     * @return array{total: int, valid: int, conflict: int, error: int, unchanged: int, locked: int}
     */
    public function stage(RcsaImportBatch $batch): array
    {
        $assessment = $batch->assessment_id === null
            ? null
            : RcsaAssessment::query()->find($batch->assessment_id);

        if ($assessment === null) {
            throw new RuntimeException('This upload is not attached to an assessment.');
        }

        $batch->update(['status' => RcsaImportBatch::PARSING]);

        $path = Storage::disk('local')->path($batch->file_path);

        // The named sheet, not the active one — the working copy opens on
        // "Read me first", and P2's lesson was that reading the ACTIVE sheet
        // makes a file the product itself produced fail its own importer.
        $rows = $this->reader->rows($path, RcsaWorkbookWriter::SHEET_RCSA);

        if ($rows === []) {
            throw new RuntimeException('The file is empty.');
        }

        $map = $this->mapHeader(array_shift($rows));

        if (! isset($map['line_id'])) {
            throw new RuntimeException(
                'This file has no risk identifiers in it. Upload the working copy the system produced — '
                .'the two hidden columns at the far left are what tell the rows apart.'
            );
        }

        $batch->rows()->delete();

        // Keyed by id, loaded once: a lookup per row would be one query per
        // line on a file that can carry several hundred.
        $lines = $assessment->lines()->get()->keyBy('id');
        $methodology = $this->methodologyFor($assessment);

        $counts = ['total' => 0, 'valid' => 0, 'conflict' => 0, 'error' => 0, 'unchanged' => 0, 'locked' => 0];
        $staged = [];

        foreach ($rows as $index => $cells) {
            // +2: the header was shifted off and spreadsheet rows are 1-based,
            // so the number in a message is the row the user sees in Excel.
            $rowNumber = $index + 2;

            if ($this->isBlankRow($cells)) {
                continue;
            }

            $raw = $this->extract($cells, $map);
            $counts['total']++;

            $staged[] = $this->stageRow($batch, $rowNumber, $raw, $lines, $methodology, $counts);
        }

        foreach (array_chunk($staged, 500) as $chunk) {
            RcsaImportRow::insert($chunk);
        }

        $batch->update([
            'status' => RcsaImportBatch::VALIDATED,
            'total_rows' => $counts['total'],
            'valid_rows' => $counts['valid'],
            'warning_rows' => $counts['conflict'] + $counts['locked'],
            'error_rows' => $counts['error'],
            'skipped_count' => $counts['unchanged'],
        ]);

        return $counts;
    }

    /**
     * One row, classified.
     *
     * @param  array<string, mixed>  $raw
     * @param  \Illuminate\Support\Collection<int, RcsaAssessmentLine>  $lines
     * @param  array{total: int, valid: int, conflict: int, error: int, unchanged: int, locked: int}  $counts
     * @return array<string, mixed>
     */
    private function stageRow(
        RcsaImportBatch $batch,
        int $rowNumber,
        array $raw,
        $lines,
        ?RcsaMethodology $methodology,
        array &$counts,
    ): array {
        $lineId = (int) ($raw['line_id'] ?? 0);
        $line = $lines->get($lineId);

        $errors = [];
        $status = 'valid';
        $action = self::ACTION_MINE;

        if ($line === null) {
            $errors[] = [
                'field' => 'line_id',
                'rule' => 'unknown_line',
                'severity' => 'error',
                'message' => 'This risk is not in the assessment you are uploading against. '
                    .'It may belong to a different unit, or the row may have been added by hand.',
            ];
            $status = 'error';
            $counts['error']++;
        }

        $changes = $line === null ? [] : $this->changesFor($line, $raw, $methodology, $errors);

        if ($line !== null && $errors !== []) {
            $status = 'error';
            $action = self::ACTION_THEIRS;
            $counts['error']++;
        } elseif ($line !== null && $line->isLocked()) {
            // P5's rule, surfaced BEFORE the user chooses rather than after
            // they press apply. `apply()` refuses a locked line whatever is
            // chosen here, and a screen that offered "keep mine" on a row it
            // was going to refuse would be lying about what the button does.
            $status = 'warning';
            $action = self::ACTION_THEIRS;
            $counts['locked']++;

            $errors[] = [
                'field' => 'locked_at',
                'rule' => self::LOCKED,
                'severity' => 'warning',
                'message' => $changes === []
                    ? 'This risk is locked as filed. Nothing in your file changes it.'
                    : 'This risk is locked as filed — the ORM did not reopen it — so your changes to it '
                        .'cannot be applied.',
            ];
        } elseif ($line !== null && $changes === []) {
            // Nothing to do — reported rather than hidden, so the preview's
            // arithmetic adds up to the number of rows in the file.
            $status = 'valid';
            $action = self::ACTION_THEIRS;
            $counts['unchanged']++;
        } elseif ($line !== null && (int) $line->version !== (int) ($raw['version'] ?? 0)) {
            // SOMEBODY ELSE GOT THERE FIRST. Staged as a conflict and
            // defaulted to keeping THEIR answer: the person at the keyboard
            // resolves it, and the safe default is the one that loses no
            // work that is already in the system.
            $status = 'warning';
            $action = self::ACTION_THEIRS;
            $counts['conflict']++;

            $errors[] = [
                'field' => 'version',
                'rule' => self::CONFLICT,
                'severity' => 'warning',
                'message' => sprintf(
                    'Somebody changed this risk in the system after you took your copy '
                    .'(it was at version %d, it is now version %d). Choose whose answer to keep.',
                    (int) ($raw['version'] ?? 0),
                    (int) $line->version,
                ),
            ];
        } else {
            $counts['valid']++;
        }

        return [
            'batch_id' => $batch->id,
            'row_number' => $rowNumber,
            'raw' => json_encode($raw),
            'normalised' => json_encode([
                'changes' => $changes,
                // What the server holds right now, so the resolution screen can
                // show both sides without a query per row.
                'theirs' => $line === null ? null : array_intersect_key(
                    $line->only(RcsaWorkbookWriter::ROUND_TRIP_FIELDS),
                    array_flip(array_keys($changes ?: []))
                ),
                'risk_no' => $line === null ? ($raw['risk_no'] ?? null) : $line->risk_no,
                'potential_risk' => $line?->potential_risk,
            ]),
            'status' => $status,
            'errors' => $errors === [] ? null : json_encode($errors),
            'target_id' => $line?->id,
            'action' => $action,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * What the file asks to change on this line, ignoring everything it is not
     * allowed to change.
     *
     * @param  array<string, mixed>  $raw
     * @param  list<array<string, string>>  $errors
     * @return array<string, mixed>
     */
    private function changesFor(RcsaAssessmentLine $line, array $raw, ?RcsaMethodology $methodology, array &$errors): array
    {
        $changes = [];

        foreach (RcsaWorkbookWriter::ROUND_TRIP_FIELDS as $field) {
            if (! array_key_exists($field, $raw)) {
                continue;
            }

            $value = $raw[$field];

            if (is_string($value)) {
                $value = trim($value);
            }

            $value = $value === '' ? null : $value;

            // The three scored fields are integers or a label; casting here
            // rather than at the comparison keeps "3" and 3 from reading as a
            // change on every upload.
            if (in_array($field, ['inherent_likelihood', 'inherent_impact'], true)) {
                $value = $value === null ? null : (int) $value;
            }

            if ($value === null && $line->{$field} === null) {
                continue;
            }

            if ((string) $value === (string) $line->{$field}) {
                continue;
            }

            if (! $this->isPermitted($field, $value, $methodology)) {
                $errors[] = [
                    'field' => $field,
                    'rule' => 'not_in_scale',
                    'severity' => 'error',
                    'message' => sprintf('"%s" is not one of the values this methodology accepts for %s.',
                        (string) $value,
                        str_replace('_', ' ', $field),
                    ),
                ];

                continue;
            }

            $changes[$field] = $value;
        }

        return $changes;
    }

    /**
     * Whether a value is legal for this line's OWN methodology.
     *
     * The line's, not the active one — the same rule
     * `UpdateAssessmentLineRequest` follows, and for the same reason: a line
     * scored under the 2026 methodology must keep accepting 2026's labels after
     * a 2027 one goes live.
     */
    private function isPermitted(string $field, mixed $value, ?RcsaMethodology $methodology): bool
    {
        if ($value === null || $methodology === null) {
            return true;
        }

        return match ($field) {
            'inherent_likelihood' => array_key_exists((int) $value, $methodology->scale(RcsaScaleItem::TYPE_LIKELIHOOD)),
            'inherent_impact' => array_key_exists((int) $value, $methodology->scale(RcsaScaleItem::TYPE_IMPACT)),
            'control_effectiveness' => in_array(
                (string) $value,
                array_map(fn (RcsaScaleItem $i) => $i->label, $methodology->scale(RcsaScaleItem::TYPE_CONTROL_EFFECTIVENESS)),
                true,
            ),
            'treatment_override' => in_array(
                (string) $value,
                $methodology->bands->pluck('treatment')->unique()->all(),
                true,
            ),
            default => true,
        };
    }

    /* ------------------------------------------------------------------ */
    /*  The resolution screen's data */
    /* ------------------------------------------------------------------ */

    /**
     * The staged rows, as the conflict-resolution screen reads them.
     *
     * @return list<array<string, mixed>>
     */
    public function preview(RcsaImportBatch $batch): array
    {
        return $batch->rows()
            ->orderBy('row_number')
            ->get()
            ->map(function (RcsaImportRow $row) {
                $normalised = (array) ($row->normalised ?? []);

                return [
                    'id' => $row->id,
                    'row_number' => $row->row_number,
                    'line_id' => $row->target_id,
                    'risk_no' => $normalised['risk_no'] ?? null,
                    'potential_risk' => $normalised['potential_risk'] ?? null,
                    'status' => $row->status,
                    'is_conflict' => collect((array) $row->errors)
                        ->contains(fn ($e) => ($e['rule'] ?? null) === self::CONFLICT),
                    'is_locked' => collect((array) $row->errors)
                        ->contains(fn ($e) => ($e['rule'] ?? null) === self::LOCKED),
                    'action' => $row->action,
                    'changes' => $normalised['changes'] ?? [],
                    'theirs' => $normalised['theirs'] ?? [],
                    'errors' => $row->errors ?? [],
                ];
            })
            ->all();
    }

    /**
     * The user's choice on one conflicted row.
     */
    public function resolve(RcsaImportRow $row, string $action): RcsaImportRow
    {
        if (! in_array($action, [self::ACTION_MINE, self::ACTION_THEIRS], true)) {
            throw new RuntimeException('Choose whose answer to keep.');
        }

        // An ERRORED row cannot be resolved into an apply. A value outside the
        // methodology's scale is not a disagreement between two people, it is a
        // value the engine cannot score, and offering "keep mine" would offer
        // something the next step would refuse anyway.
        if ($row->status === 'error') {
            throw new RuntimeException('This row cannot be applied — fix it in the file and upload again.');
        }

        // Neither can a locked one, for the same reason: `apply()` refuses it,
        // so accepting the choice here would only defer the refusal.
        if (collect((array) $row->errors)->contains(fn ($e) => ($e['rule'] ?? null) === self::LOCKED)) {
            throw new RuntimeException(
                'This risk is locked as filed. Only the risks the ORM reopened can be changed.'
            );
        }

        $row->update(['action' => $action]);

        return $row->refresh();
    }

    /* ------------------------------------------------------------------ */
    /*  Apply */
    /* ------------------------------------------------------------------ */

    /**
     * Write the rows the user chose to keep.
     *
     * ONE TRANSACTION, and through `apply()` for every row: the engine
     * recomputes, the revision trail records who changed what and the line's
     * version bumps. A round trip that wrote columns directly would be a way to
     * change sixty ratings with no record — which is the same argument P3 made
     * about bulk apply.
     *
     * @return array{applied: int, skipped: int, refused: int}
     */
    public function apply(RcsaImportBatch $batch, User $actor): array
    {
        $assessment = RcsaAssessment::query()->findOrFail($batch->assessment_id);

        if (! $assessment->acceptsEdits()) {
            throw new RuntimeException('This assessment is no longer open for editing.');
        }

        $applied = $skipped = $refused = 0;

        DB::transaction(function () use ($batch, $actor, $assessment, &$applied, &$skipped, &$refused) {
            $lines = $assessment->lines()->get()->keyBy('id');

            foreach ($batch->rows()->orderBy('row_number')->get() as $row) {
                $changes = (array) (($row->normalised ?? [])['changes'] ?? []);

                if ($row->action !== self::ACTION_MINE || $changes === [] || $row->status === 'error') {
                    $skipped++;

                    continue;
                }

                $line = $lines->get($row->target_id);

                if ($line === null) {
                    $skipped++;

                    continue;
                }

                $result = $this->assessments->apply(
                    line: $line,
                    input: $changes,
                    actor: $actor,
                    // No version check: the conflict pass above already
                    // compared versions and the user has answered. Passing the
                    // stale version from the file would refuse the very rows
                    // they just chose to keep.
                    expectedVersion: null,
                );

                // A line the ORM never reopened stays refused, even here. P5's
                // rule holds at every write, and an offline file is a write.
                $result['status'] === RcsaAssessmentService::LOCKED ? $refused++ : $applied++;
            }

            $batch->update([
                'status' => RcsaImportBatch::PUBLISHED,
                'published_by' => $actor->id,
                'published_at' => now(),
                'updated_count' => $applied,
                'skipped_count' => $skipped,
            ]);
        });

        return ['applied' => $applied, 'skipped' => $skipped, 'refused' => $refused];
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /**
     * Header label => field, using the writer's own column list so the two
     * cannot drift.
     *
     * @param  list<mixed>  $header
     * @return array<string, int>
     */
    private function mapHeader(array $header): array
    {
        $labels = array_merge(RcsaWorkbookWriter::HIDDEN_COLUMNS, RcsaWorkbookWriter::COLUMNS);

        $normalise = fn (?string $value) => preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim((string) $value)));

        $wanted = [];

        foreach ($labels as $field => $label) {
            $wanted[$normalise($label)] = $field;
        }

        $map = [];

        foreach ($header as $index => $cell) {
            $key = $normalise(is_scalar($cell) ? (string) $cell : '');

            if ($key !== '' && isset($wanted[$key])) {
                $map[$wanted[$key]] = $index;
            }
        }

        return $map;
    }

    /**
     * @param  list<mixed>  $cells
     * @param  array<string, int>  $map
     * @return array<string, mixed>
     */
    private function extract(array $cells, array $map): array
    {
        $out = [];

        foreach ($map as $field => $index) {
            $out[$field] = $cells[$index] ?? null;
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $cells
     */
    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (filled($cell)) {
                return false;
            }
        }

        return true;
    }

    private function methodologyFor(RcsaAssessment $assessment): ?RcsaMethodology
    {
        $line = $assessment->lines()->first();

        if ($line === null) {
            return null;
        }

        return RcsaMethodology::withoutGlobalScopes()
            ->with(['scaleItems', 'bands'])
            ->find($line->methodology_id);
    }
}

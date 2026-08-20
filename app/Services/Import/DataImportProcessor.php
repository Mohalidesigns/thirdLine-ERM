<?php

namespace App\Services\Import;

use App\Models\Control;
use App\Models\DataImport;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\LossEvent;
use App\Models\Risk;
use App\Services\ReferenceCodeService;
use App\Services\SpreadsheetReader;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * WP-07 TASK 1 — the import loop, lifted out of the controller so a queue can
 * run it.
 *
 * It was a `foreach` inside processImport(), which meant a fifty-thousand-row
 * spreadsheet was a request that timed out with several thousand rows already
 * written and no record of where it stopped. Re-uploading then duplicated
 * everything it had managed before dying.
 *
 * THE ACTOR IS CARRIED, NOT ASSUMED. The old code stamped created_by from
 * auth()->id(); a worker has no authenticated user, so every imported row would
 * have had a null author. data_imports.imported_by is who uploaded it, and that
 * is who the rows belong to.
 */
class DataImportProcessor
{
    public function __construct(private SpreadsheetReader $reader) {}

    /**
     * @param  callable(int, int): void|null  $onProgress
     * @param  callable(): bool|null  $shouldCancel
     * @return array{success:int, errors:int, messages:list<string>, cancelled:bool}
     */
    public function process(DataImport $import, ?callable $onProgress = null, ?callable $shouldCancel = null): array
    {
        // WP-11. Import uploads moved from the web-served `public` disk to the
        // private `local` disk (DataImportController::upload). This line used
        // to hard-code storage_path('app/public/'), which would have made every
        // new import fail with "could not be read from storage" the moment the
        // upload path changed.
        //
        // The `public` branch is a deliberate legacy fallback, not a
        // convenience: between deploying this code and running the
        // 2026_08_20 relocation migration, and for any import queued before the
        // deploy, the file is still under app/public. It is a read-only
        // fallback — nothing writes there any more.
        $filePath = Storage::disk('local')->path($import->file_path);

        if (! is_readable($filePath)) {
            $legacyPath = storage_path('app/public/'.$import->file_path);

            if (! is_readable($legacyPath)) {
                throw new RuntimeException('The uploaded file could not be read from storage.');
            }

            $filePath = $legacyPath;
        }

        $rows = $this->reader->dataRows($filePath);
        $total = count($rows);
        $mapping = (array) $import->column_mapping;

        $import->update(['total_rows' => $total, 'status' => 'processing']);

        $success = 0;
        $failed = 0;
        $messages = [];

        foreach ($rows as $index => $row) {
            // +2 so the number matches what the user sees in their spreadsheet:
            // row 1 is the header, and the index is zero-based.
            $rowNumber = $index + 2;

            try {
                $data = $this->mapRow($row, $mapping);

                if ($data === []) {
                    continue;
                }

                $data['organization_id'] = $import->organization_id;

                $this->createRecord($import->import_type, $data, $import->imported_by);
                $success++;
            } catch (Throwable $e) {
                $failed++;

                // Bounded on purpose. A malformed file produces one error per
                // row, and fifty thousand of them in a JSON column is a payload
                // nothing can render and a row nothing can update.
                if (count($messages) < 500) {
                    $messages[] = "Row {$rowNumber}: ".$e->getMessage();
                } elseif (count($messages) === 500) {
                    $messages[] = 'Further errors were suppressed; fix these first and re-import.';
                }
            }

            if ($onProgress !== null && ($index % 50 === 0 || $index === $total - 1)) {
                $onProgress($index + 1, $total);
            }

            if ($shouldCancel !== null && $index % 100 === 0 && $shouldCancel()) {
                // Rows already written stay written — they are valid records —
                // but the counts say exactly how far it got, so the user can
                // re-import the remainder rather than guess.
                $import->update([
                    'success_count' => $success,
                    'error_count' => $failed,
                    'errors' => $messages,
                    'status' => 'cancelled',
                    'completed_at' => now(),
                ]);

                return ['success' => $success, 'errors' => $failed, 'messages' => $messages, 'cancelled' => true];
            }
        }

        $import->update([
            'success_count' => $success,
            'error_count' => $failed,
            'errors' => $messages,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return ['success' => $success, 'errors' => $failed, 'messages' => $messages, 'cancelled' => false];
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, mixed>  $mapping
     * @return array<string, string>
     */
    private function mapRow(array $row, array $mapping): array
    {
        $data = [];

        foreach ($mapping as $field => $columnIndex) {
            if ($columnIndex === '' || ! isset($row[(int) $columnIndex])) {
                continue;
            }

            $value = trim((string) $row[(int) $columnIndex]);

            // An empty cell is absent, not an empty string: writing '' into a
            // nullable date or integer column is how imports end up with rows
            // nothing downstream can read.
            if ($value !== '') {
                $data[$field] = $value;
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function createRecord(string $type, array $data, ?int $actorId): void
    {
        match ($type) {
            'risks' => Risk::create(array_merge($data, [
                'risk_code' => ReferenceCodeService::generate('risks', 'risk_code', 'RK'),
                'created_by' => $actorId,
            ])),
            'controls' => Control::create(array_merge($data, [
                'control_code' => ReferenceCodeService::generate('controls', 'control_code', 'CTL'),
                'created_by' => $actorId,
            ])),
            'loss_events' => LossEvent::create(array_merge($data, [
                'event_reference' => ReferenceCodeService::generate('loss_events', 'event_reference', 'LE'),
                'current_status' => 'open',
                'date_reported' => now(),
                'created_by' => $actorId,
            ])),
            // The column is issue_reference; there is no issue_code on issues,
            // so every imported row used to fail on an unknown column.
            'issues' => Issue::create(array_merge($data, [
                'issue_reference' => ReferenceCodeService::generate('issues', 'issue_reference', 'ISS'),
                'created_by' => $actorId,
            ])),
            'kris' => KeyRiskIndicator::create(array_merge($data, [
                'kri_code' => ReferenceCodeService::generate('key_risk_indicators', 'kri_code', 'KRI'),
            ])),
            default => throw new RuntimeException("Unknown import type: {$type}"),
        };
    }
}

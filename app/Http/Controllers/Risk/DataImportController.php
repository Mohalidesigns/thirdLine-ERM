<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Control;
use App\Models\DataImport;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\LossEvent;
use App\Models\Risk;
use App\Services\SpreadsheetReader;
use Illuminate\Http\Request;

class DataImportController extends Controller
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
    ) {}

    public function index()
    {
        $orgId = auth()->user()->organization_id;
        $imports = DataImport::where('organization_id', $orgId)
            ->with('importer')
            ->latest()
            ->paginate(20);

        return view('risk.imports.index', compact('imports'));
    }

    public function create()
    {
        return view('risk.imports.create');
    }

    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls|max:10240',
            'import_type' => 'required|in:risks,controls,loss_events,issues,kris',
        ]);

        $file = $request->file('file');
        $path = $file->store('imports/'.auth()->user()->organization_id, 'public');

        $import = DataImport::create([
            'organization_id' => auth()->user()->organization_id,
            'import_type' => $request->import_type,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'status' => 'pending',
            'imported_by' => auth()->id(),
        ]);

        // Read headers with a parser chosen by what the file actually is. This
        // previously used fgetcsv() for every accepted type, so an .xlsx —
        // which is a ZIP archive — was parsed as text and produced garbage
        // headers, then garbage records.
        try {
            $headers = $this->reader->headers($file->getPathname());
            $import->update(['total_rows' => count($this->reader->dataRows($file->getPathname()))]);
        } catch (\RuntimeException $e) {
            $import->update([
                'status' => 'failed',
                'errors' => [$e->getMessage()],
                'completed_at' => now(),
            ]);

            return redirect()->route('risk.imports.create')
                ->with('error', 'That file could not be read: '.$e->getMessage());
        }

        $systemFields = $this->getFieldsForType($request->import_type);

        return view('risk.imports.mapping', compact('import', 'headers', 'systemFields'));
    }

    public function processImport(Request $request, DataImport $import)
    {
        $request->validate([
            'column_mapping' => 'required|array',
        ]);

        $import->update([
            'column_mapping' => $request->column_mapping,
            'status' => 'processing',
        ]);

        $filePath = storage_path('app/public/'.$import->file_path);
        $mapping = $request->column_mapping;

        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        if (! is_readable($filePath)) {
            $import->update([
                'status' => 'failed',
                'errors' => ['Uploaded file could not be read from storage.'],
                'completed_at' => now(),
            ]);

            return redirect()->route('risk.imports.index')
                ->with('error', 'Import failed: the uploaded file could not be read.');
        }

        try {
            $dataRows = $this->reader->dataRows($filePath);
        } catch (\RuntimeException $e) {
            $import->update([
                'status' => 'failed',
                'errors' => [$e->getMessage()],
                'completed_at' => now(),
            ]);

            return redirect()->route('risk.imports.index')
                ->with('error', 'Import failed: '.$e->getMessage());
        }

        foreach ($dataRows as $index => $row) {
            // +2 so the number matches what the user sees in their spreadsheet:
            // row 1 is the header, and the index is zero-based.
            $rowNum = $index + 2;

            try {
                $data = [];
                foreach ($mapping as $systemField => $columnIndex) {
                    if ($columnIndex !== '' && isset($row[(int) $columnIndex])) {
                        $value = trim((string) $row[(int) $columnIndex]);
                        // An empty cell is absent, not an empty string: writing
                        // '' into a nullable date or integer column is how
                        // imports end up with unusable rows.
                        if ($value !== '') {
                            $data[$systemField] = $value;
                        }
                    }
                }

                if ($data === []) {
                    continue;
                }

                $data['organization_id'] = $import->organization_id;

                $this->createRecordForType($import->import_type, $data);
                $successCount++;
            } catch (\Throwable $e) {
                $errorCount++;
                $errors[] = "Row {$rowNum}: ".$e->getMessage();
            }
        }

        $import->update([
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return redirect()->route('risk.imports.index')->with('success', "Import completed: {$successCount} records imported, {$errorCount} errors.");
    }

    private function getFieldsForType(string $type): array
    {
        return match ($type) {
            'risks' => ['title', 'description', 'category_id', 'inherent_likelihood', 'inherent_impact', 'residual_likelihood', 'residual_impact', 'risk_owner_id', 'status'],
            'controls' => ['name', 'description', 'control_type', 'control_nature', 'frequency', 'automation_level', 'effectiveness_rating', 'status'],
            'loss_events' => ['title', 'description', 'date_of_loss', 'gross_loss_amount_kobo', 'basel_l1_category', 'event_severity'],
            'issues' => ['title', 'description', 'issue_source', 'issue_category', 'priority', 'issue_status', 'remediation_due_date'],
            'kris' => ['name', 'description', 'measurement_frequency', 'baseline_value', 'green_threshold', 'amber_threshold', 'red_threshold'],
            default => [],
        };
    }

    private function createRecordForType(string $type, array $data): void
    {
        match ($type) {
            'risks' => Risk::create(array_merge($data, ['risk_code' => \App\Services\ReferenceCodeService::generate('risks', 'risk_code', 'RK'), 'created_by' => auth()->id()])),
            'controls' => Control::create(array_merge($data, ['control_code' => \App\Services\ReferenceCodeService::generate('controls', 'control_code', 'CTL'), 'created_by' => auth()->id()])),
            'loss_events' => LossEvent::create(array_merge($data, ['event_reference' => \App\Services\ReferenceCodeService::generate('loss_events', 'event_reference', 'LE'), 'current_status' => 'open', 'date_reported' => now()])),
            // The column is issue_reference; there is no issue_code on issues, so
            // every imported row used to fail on an unknown column.
            'issues' => Issue::create(array_merge($data, ['issue_reference' => \App\Services\ReferenceCodeService::generate('issues', 'issue_reference', 'ISS'), 'created_by' => auth()->id()])),
            'kris' => KeyRiskIndicator::create(array_merge($data, ['kri_code' => \App\Services\ReferenceCodeService::generate('key_risk_indicators', 'kri_code', 'KRI')])),
            default => throw new \Exception("Unknown import type: {$type}"),
        };
    }
}

<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\DataImport;
use App\Models\Risk;
use App\Models\Control;
use App\Models\LossEvent;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DataImportController extends Controller
{
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
            'file'        => 'required|file|mimes:csv,xlsx,xls|max:10240',
            'import_type' => 'required|in:risks,controls,loss_events,issues,kris',
        ]);

        $file = $request->file('file');
        $path = $file->store('imports/' . auth()->user()->organization_id, 'public');

        $import = DataImport::create([
            'organization_id' => auth()->user()->organization_id,
            'import_type'     => $request->import_type,
            'file_name'       => $file->getClientOriginalName(),
            'file_path'       => $path,
            'status'          => 'pending',
            'imported_by'     => auth()->id(),
        ]);

        // Parse CSV headers for mapping
        $headers = [];
        if (($handle = fopen($file->getPathname(), 'r')) !== false) {
            $headers = fgetcsv($handle);
            $totalRows = 0;
            while (fgetcsv($handle) !== false) $totalRows++;
            fclose($handle);
            $import->update(['total_rows' => $totalRows]);
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
            'status'         => 'processing',
        ]);

        $filePath = storage_path('app/public/' . $import->file_path);
        $mapping  = $request->column_mapping;

        $successCount = 0;
        $errorCount   = 0;
        $errors       = [];

        if (($handle = fopen($filePath, 'r')) !== false) {
            $headers = fgetcsv($handle);
            $rowNum  = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                try {
                    $data = [];
                    foreach ($mapping as $systemField => $csvIndex) {
                        if ($csvIndex !== '' && isset($row[(int)$csvIndex])) {
                            $data[$systemField] = trim($row[(int)$csvIndex]);
                        }
                    }
                    $data['organization_id'] = $import->organization_id;

                    $this->createRecordForType($import->import_type, $data);
                    $successCount++;
                } catch (\Exception $e) {
                    $errorCount++;
                    $errors[] = "Row {$rowNum}: " . $e->getMessage();
                }
            }
            fclose($handle);
        }

        $import->update([
            'success_count' => $successCount,
            'error_count'   => $errorCount,
            'errors'        => $errors,
            'status'        => 'completed',
            'completed_at'  => now(),
        ]);

        return redirect()->route('risk.imports.index')->with('success', "Import completed: {$successCount} records imported, {$errorCount} errors.");
    }

    private function getFieldsForType(string $type): array
    {
        return match($type) {
            'risks'       => ['title', 'description', 'category_id', 'inherent_likelihood', 'inherent_impact', 'residual_likelihood', 'residual_impact', 'risk_owner_id', 'status'],
            'controls'    => ['name', 'description', 'control_type', 'control_nature', 'frequency', 'automation_level', 'effectiveness_rating', 'status'],
            'loss_events' => ['title', 'description', 'date_of_loss', 'gross_loss_amount_kobo', 'basel_l1_category', 'event_severity'],
            'issues'      => ['title', 'description', 'issue_source', 'issue_category', 'priority', 'issue_status', 'remediation_due_date'],
            'kris'        => ['name', 'description', 'measurement_frequency', 'baseline_value', 'green_threshold', 'amber_threshold', 'red_threshold'],
            default       => [],
        };
    }

    private function createRecordForType(string $type, array $data): void
    {
        match($type) {
            'risks'       => Risk::create(array_merge($data, ['risk_code' => \App\Services\ReferenceCodeService::generate('risks', 'risk_code', 'RK'), 'created_by' => auth()->id()])),
            'controls'    => Control::create(array_merge($data, ['control_code' => \App\Services\ReferenceCodeService::generate('controls', 'control_code', 'CTL'), 'created_by' => auth()->id()])),
            'loss_events' => LossEvent::create(array_merge($data, ['event_reference' => \App\Services\ReferenceCodeService::generate('loss_events', 'event_reference', 'LE'), 'current_status' => 'open', 'date_reported' => now()])),
            'issues'      => Issue::create(array_merge($data, ['issue_code' => \App\Services\ReferenceCodeService::generate('issues', 'issue_code', 'ISS'), 'created_by' => auth()->id()])),
            'kris'        => KeyRiskIndicator::create(array_merge($data, ['kri_code' => \App\Services\ReferenceCodeService::generate('key_risk_indicators', 'kri_code', 'KRI')])),
            default       => throw new \Exception("Unknown import type: {$type}"),
        };
    }
}

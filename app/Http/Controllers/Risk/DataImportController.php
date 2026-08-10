<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDataImportJob;
use App\Models\DataImport;
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
        abort_unless($import->organization_id === \App\Support\Tenancy\TenantContext::organizationId(), 403);

        $request->validate([
            'column_mapping' => 'required|array',
        ]);

        $import->update([
            'column_mapping' => $request->column_mapping,
            'status' => 'queued',
        ]);

        // WP-07. This used to loop the whole spreadsheet inside the request. A
        // fifty-thousand-row file was a timeout with several thousand rows
        // already written and no record of where it stopped — and re-uploading
        // then duplicated everything it had managed before dying.
        $jobRun = ProcessDataImportJob::track(
            label: 'Import '.$import->file_name,
            subject: $import,
            organizationId: $import->organization_id,
            creator: $request->user(),
        );

        ProcessDataImportJob::dispatch($import->id, $jobRun->id);

        return redirect()->route('risk.imports.index')
            ->with('success', 'Import queued. Its progress is shown on this page.');
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
}

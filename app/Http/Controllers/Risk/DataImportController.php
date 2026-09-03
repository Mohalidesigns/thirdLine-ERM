<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessDataImportJob;
use App\Models\DataImport;
use App\Presenters\GridPresenter;
use App\Services\FileUploadService;
use App\Services\SpreadsheetReader;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DataImportController extends Controller
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly FileUploadService $uploads,
    ) {}

    /**
     * WP-09: the history table is the shared data grid — see
     * App\Grids\Definitions\DataImportsGrid.
     */
    public function index(Request $request, GridPresenter $presenter)
    {
        $total = DataImport::where('organization_id', TenantContext::organizationId())->count();

        return Inertia::render('Imports/Index', [
            'total' => $total,
            'grid' => fn () => $presenter->present(GridRegistry::resolve('imports'), $request, $request->user()),
        ]);
    }

    public function create()
    {
        return view('risk.imports.create');
    }

    /**
     * Accept a spreadsheet for bulk import.
     *
     * WP-11. `$file->store(..., 'public')` wrote the uploaded workbook into
     * `storage/app/public/imports/{organizationId}/`, and that directory is
     * symlinked to `public/storage`, so the file was served straight off the
     * web server at `GET /storage/imports/{organizationId}/{name}.xlsx` with no
     * session, no `import.create` permission and no tenant check. A bulk risk
     * register import is the customer's ENTIRE register — every risk, owner and
     * rating in one file — and the directory name is the organisation id, so
     * the URL space was trivially enumerable across tenants.
     *
     * The file now goes to the private `local` disk via FileUploadService,
     * which is not web-reachable. Note that nothing in the application serves
     * this file back to a user: there is no download route for a DataImport and
     * no view links to one. It is written here and read once by
     * ProcessDataImportJob. The only reader that ever existed was the web
     * server, which is precisely the problem being fixed.
     *
     * The accepted types (csv/xlsx/xls) and the 10 MB cap are unchanged; they
     * now live in FileUploadService::PROFILE_DATA_IMPORT so that the request
     * rules and the storage-time check come from one definition.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => $this->uploads->rules(FileUploadService::PROFILE_DATA_IMPORT),
            'import_type' => 'required|in:risks,controls,loss_events,issues,kris',
        ]);

        $file = $request->file('file');

        // storeAs() streams the temp file rather than moving it, so
        // $file->getPathname() below still points at a readable upload.
        $stored = $this->uploads->store(
            $file,
            'imports/'.auth()->user()->organization_id,
            FileUploadService::PROFILE_DATA_IMPORT,
        );

        $import = DataImport::create([
            'organization_id' => auth()->user()->organization_id,
            'import_type' => $request->import_type,
            // Sanitised server-side: this string is rendered in the import
            // history and used as a job label, and it arrives from the client.
            'file_name' => $stored['file_name'],
            'file_path' => $stored['storage_path'],
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

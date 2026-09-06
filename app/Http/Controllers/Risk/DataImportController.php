<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\ProcessImportRequest;
use App\Http\Requests\Imports\UploadImportFileRequest;
use App\Jobs\ProcessDataImportJob;
use App\Models\DataImport;
use App\Presenters\GridPresenter;
use App\Services\FileUploadService;
use App\Services\SpreadsheetReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

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
        Gate::authorize('create', DataImport::class);

        return Inertia::render('Imports/Create', [
            'types' => DataImport::TYPE_LABELS,
            'accepts' => FileUploadService::PROFILE_DATA_IMPORT,
        ]);
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
    public function upload(UploadImportFileRequest $request)
    {

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

        return Inertia::render('Imports/Mapping', [
            'import' => $import->only(['id', 'import_type', 'file_name', 'total_rows']),
            'headers' => $headers,
            // The same list ProcessImportRequest accepts. It used to live on
            // this controller alone, with nothing checking the mapping against
            // it on the way back in.
            'systemFields' => DataImport::fieldsFor($import->import_type),
            'typeLabel' => DataImport::TYPE_LABELS[$import->import_type] ?? $import->import_type,
        ]);
    }

    /**
     * Start the import with the column mapping the user chose.
     *
     * TWO THINGS HAD TO BE FIXED BEFORE THIS COULD RUN AT ALL, both introduced
     * by WP-07 when the row loop moved onto a queue and neither caught because
     * this route had no test:
     *
     *   - `data_imports.status` was an enum of four and this writes `queued`,
     *     so MySQL answered every call with "Data truncated for column
     *     'status'" (migration 2026_09_05_140000);
     *   - `DataImport` had no morph alias, and ProcessDataImportJob::track()
     *     stores its subject as a morph, so getMorphClass() threw.
     *
     * The mapping's KEYS are validated by ProcessImportRequest — see the note
     * there for what an unvalidated key could reach.
     */
    public function processImport(ProcessImportRequest $request, DataImport $import)
    {
        $import->update([
            'column_mapping' => $request->validated('column_mapping'),
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
}

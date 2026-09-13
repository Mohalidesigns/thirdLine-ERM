<?php

namespace App\Http\Controllers\Rcsa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rcsa\PublishImportBatchRequest;
use App\Http\Requests\Rcsa\StoreImportBatchRequest;
use App\Http\Requests\Rcsa\UpdateImportRowRequest;
use App\Jobs\ProcessRcsaImportJob;
use App\Models\Rcsa\RcsaImportBatch;
use App\Models\Rcsa\RcsaImportRow;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Services\Rcsa\RcsaErrorWorkbookWriter;
use App\Services\Rcsa\RcsaImportProcessor;
use App\Services\Rcsa\RcsaImportPublisher;
use App\Services\Rcsa\RcsaTemplateWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Template download, upload, preview and publish (plan §7).
 *
 * THE PREVIEW IS THE FEATURE. Uploading stages a file and shows the user what
 * would happen; publishing is a second, deliberate act. A controller that
 * imported on upload would be half the code and none of the value — a bank
 * will not run a bulk upload it cannot inspect first.
 */
class ImportController extends Controller
{
    public function __construct(
        private readonly RcsaTemplateWriter $templates,
        private readonly RcsaImportProcessor $processor,
        private readonly RcsaImportPublisher $publisher,
    ) {}

    /**
     * Stream a freshly generated universe template.
     *
     * Generated per request, never cached: the dropdowns list this tenant's
     * units, processes and users, and a cached file goes stale the first time
     * anybody adds a branch.
     */
    public function template(Request $request): StreamedResponse
    {
        Gate::authorize('viewAny', RcsaRegisterRisk::class);

        $contents = $this->templates->universeTemplate();
        $filename = 'RCSA-Universe-Template-'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(
            fn () => print ($contents),
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ],
        );
    }

    /**
     * Accept an upload, create the batch, and queue the parse.
     */
    public function store(StoreImportBatchRequest $request)
    {
        $file = $request->file('file');

        // The private disk, not `public`. An uploaded RCSA is the bank's
        // operational risk profile; WP-11 moved every other upload off the
        // web-served disk for exactly this reason.
        $path = $file->store('rcsa/imports', 'local');

        $batch = RcsaImportBatch::create([
            'organization_id' => TenantContext::organizationId(),
            'user_id' => $request->user()->id,
            'type' => 'universe',
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'template_version' => RcsaTemplateWriter::VERSION,
            'status' => RcsaImportBatch::QUEUED,
        ]);

        ProcessRcsaImportJob::dispatch($batch->id);

        return redirect()
            ->route('rcsa.imports.show', $batch)
            ->with('success', 'File received. Checking it now — nothing is added to the universe until you publish.');
    }

    /**
     * The preview screen: summary tiles and a tabbed grid of staged rows.
     */
    public function show(Request $request, RcsaImportBatch $batch)
    {
        Gate::authorize('import', RcsaRegisterRisk::class);

        $status = $request->input('status');

        $rows = $batch->rows()
            ->when(in_array($status, [RcsaImportRow::VALID, RcsaImportRow::WARNING, RcsaImportRow::ERROR, RcsaImportRow::DUPLICATE], true),
                fn ($query) => $query->where('status', $status))
            ->paginate(50)
            ->withQueryString();

        $rows->through(fn (RcsaImportRow $row) => [
            'id' => $row->id,
            'row_number' => $row->row_number,
            'status' => $row->status,
            'action' => $row->action,
            'raw' => $row->raw ?? [],
            'errors' => $row->errors ?? [],
            'target_id' => $row->target_id,
        ]);

        return Inertia::render('RcsaUniverse/ImportPreview', [
            'batch' => [
                'id' => $batch->id,
                'original_name' => $batch->original_name,
                'status' => $batch->status,
                'failure_reason' => $batch->failure_reason,
                'total_rows' => $batch->total_rows,
                'valid_rows' => $batch->valid_rows,
                'warning_rows' => $batch->warning_rows,
                'error_rows' => $batch->error_rows,
                'duplicate_rows' => $batch->duplicate_rows,
                'created_count' => $batch->created_count,
                'updated_count' => $batch->updated_count,
                'skipped_count' => $batch->skipped_count,
                'is_publishable' => $batch->isPublishable(),
                'uploaded_at' => $batch->created_at?->toDateTimeString(),
            ],
            'rows' => $rows,
            'filters' => ['status' => $status],
            'columns' => collect(RcsaTemplateWriter::COLUMNS)
                ->map(fn (array $meta, string $field) => ['field' => $field, 'label' => $meta['label'], 'required' => $meta['required']])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Correct one cell on the preview and re-check the row.
     */
    public function updateRow(UpdateImportRowRequest $request, RcsaImportBatch $batch, RcsaImportRow $row)
    {
        abort_unless($row->batch_id === $batch->id, 404);
        abort_unless($batch->isPublishable(), 422, 'This batch can no longer be edited.');

        $raw = array_merge($row->raw ?? [], $request->validated('values'));

        $this->processor->revalidate($row, $raw);
        $this->processor->recount($batch);

        return back()->with('success', "Row {$row->row_number} re-checked.");
    }

    /**
     * The user's own file back, with an Errors column and shaded cells.
     */
    public function errorWorkbook(Request $request, RcsaImportBatch $batch, RcsaErrorWorkbookWriter $writer): StreamedResponse
    {
        Gate::authorize('import', RcsaRegisterRisk::class);

        $contents = $writer->write($batch);
        $name = pathinfo($batch->original_name, PATHINFO_FILENAME);

        return response()->streamDownload(
            fn () => print ($contents),
            $name.'-errors.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * Write the batch into the universe.
     */
    public function publish(PublishImportBatchRequest $request, RcsaImportBatch $batch)
    {
        try {
            $result = $this->publisher->publish(
                batch: $batch,
                actor: $request->user(),
                mode: $request->validated('mode') ?? RcsaImportPublisher::MODE_CREATE,
                validOnly: (bool) $request->validated('valid_only'),
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('rcsa.universe.index')
            ->with('success', sprintf(
                '%d risks created, %d updated, %d skipped. They are drafts until you publish them to the universe.',
                $result['created'],
                $result['updated'],
                $result['skipped'],
            ));
    }

    /**
     * Throw the staged batch away.
     *
     * The FILE goes with it. A discarded upload has no further use and it is
     * the bank's risk profile sitting on disk; keeping it because deleting is
     * slightly more code is not a good trade.
     */
    public function destroy(Request $request, RcsaImportBatch $batch)
    {
        Gate::authorize('import', RcsaRegisterRisk::class);

        abort_if($batch->status === RcsaImportBatch::PUBLISHED, 422, 'A published batch is the record of what was imported and is kept.');

        Storage::disk('local')->delete($batch->file_path);

        $batch->update(['status' => RcsaImportBatch::DISCARDED]);
        $batch->delete();

        return redirect()
            ->route('rcsa.universe.index')
            ->with('success', 'Upload discarded. Nothing was added to the universe.');
    }
}

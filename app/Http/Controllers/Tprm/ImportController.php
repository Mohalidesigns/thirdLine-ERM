<?php

namespace App\Http\Controllers\Tprm;

use App\Http\Controllers\Controller;
use App\Models\Tprm\ImportBatch;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\ThirdPartyImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Bulk import of the third-party register — FR-TPR-09.
 *
 * Four steps, four routes, one batch record threaded through them: upload →
 * map → dry run → commit, with rollback available afterwards.
 *
 * The file goes to the LOCAL disk, never the public one. A vendor list is
 * commercially sensitive and the product has been here before — migration
 * 2026_08_20_090000 exists because attachments were on the public disk.
 */
class ImportController extends Controller
{
    public function __construct(private readonly ThirdPartyImporter $importer) {}

    public function index(Request $request)
    {
        Gate::authorize('create', ThirdParty::class);

        $batches = ImportBatch::query()
            ->with('creator:id,name')
            ->where('target', ImportBatch::TARGET_THIRD_PARTIES)
            ->latest()
            ->paginate(15);

        $batches->through(fn (ImportBatch $batch) => [
            'id' => $batch->getKey(),
            'filename' => $batch->original_filename,
            'status' => $batch->status,
            'rows_total' => $batch->rows_total,
            'rows_valid' => $batch->rows_valid,
            'rows_failed' => $batch->rows_failed,
            'created_count' => count((array) $batch->created_ids),
            'created_by' => $batch->creator?->name,
            'created_at' => $batch->created_at?->toDayDateTimeString(),
            'committed_at' => $batch->committed_at?->toDayDateTimeString(),
            'rolled_back_at' => $batch->rolled_back_at?->toDayDateTimeString(),
            'can_roll_back' => $batch->canRollBack(),
            'url' => route('tprm.imports.show', $batch),
        ]);

        return Inertia::render('Tprm/Imports/Index', ['batches' => $batches]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', ThirdParty::class);

        $request->validate([
            // 10 MB. The reader caps at 50,000 rows and holds them in memory;
            // anything bigger belongs in a queued import rather than a request.
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ], [
            'file.mimes' => 'Upload a CSV or an Excel workbook.',
        ]);

        $file = $request->file('file');

        $batch = ImportBatch::create([
            'target' => ImportBatch::TARGET_THIRD_PARTIES,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $file->store('tprm/imports', 'local'),
            'status' => ImportBatch::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('tprm.imports.show', $batch);
    }

    public function show(Request $request, ImportBatch $batch)
    {
        Gate::authorize('create', ThirdParty::class);

        // Only inspect the file while a mapping is still being chosen; once
        // committed the file may be gone and the batch's own record is the
        // history.
        $inspection = $batch->status === ImportBatch::STATUS_DRAFT || $batch->status === ImportBatch::STATUS_VALIDATED
            ? $this->safeInspect($batch)
            : null;

        return Inertia::render('Tprm/Imports/Show', [
            'batch' => [
                'id' => $batch->getKey(),
                'filename' => $batch->original_filename,
                'status' => $batch->status,
                'mapping' => $batch->column_mapping,
                'rows_total' => $batch->rows_total,
                'rows_valid' => $batch->rows_valid,
                'rows_failed' => $batch->rows_failed,
                'errors' => $batch->errors ?? [],
                'created_count' => count((array) $batch->created_ids),
                'can_commit' => $batch->canCommit(),
                'can_roll_back' => $batch->canRollBack(),
                'committed_at' => $batch->committed_at?->toDayDateTimeString(),
                'rolled_back_at' => $batch->rolled_back_at?->toDayDateTimeString(),
            ],
            'inspection' => $inspection,
            'columns' => collect(ThirdPartyImporter::COLUMNS)
                ->map(fn (array $meta, string $key) => $meta + ['key' => $key])
                ->values(),
        ]);
    }

    public function dryRun(Request $request, ImportBatch $batch)
    {
        Gate::authorize('create', ThirdParty::class);

        $validated = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string'],
        ]);

        $mapping = array_intersect_key($validated['mapping'], ThirdPartyImporter::COLUMNS);

        if (($mapping['legal_name'] ?? null) === null) {
            return back()->with('error', 'Map a column to the legal name — it is the one field every row needs.');
        }

        $this->importer->dryRun($batch, $mapping);

        return back()->with('success', 'Validation complete. Review the results before committing.');
    }

    public function commit(Request $request, ImportBatch $batch)
    {
        Gate::authorize('create', ThirdParty::class);

        if (! $batch->canCommit()) {
            return back()->with('error', 'Run the validation first, and check that at least one row is valid.');
        }

        $this->importer->commit($batch, $request->user()->id);

        return back()->with('success', count((array) $batch->fresh()->created_ids).' third parties were imported.');
    }

    public function rollBack(Request $request, ImportBatch $batch)
    {
        Gate::authorize('create', ThirdParty::class);

        if (! $batch->canRollBack()) {
            return back()->with('error', 'This batch cannot be rolled back.');
        }

        $result = $this->importer->rollBack($batch);

        $message = "{$result['deleted']} imported third parties were removed.";

        if ($result['kept'] !== []) {
            // Reported rather than silently skipped: a user who rolls back and
            // finds three vendors still there needs to know it was deliberate.
            $message .= ' '.count($result['kept']).' were kept because engagements have since been raised against '
                .'them: '.implode(', ', array_slice($result['kept'], 0, 5)).'.';
        }

        return back()->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function safeInspect(ImportBatch $batch): array
    {
        try {
            return $this->importer->inspect($batch);
        } catch (\Throwable $exception) {
            // An unreadable file is a message on the screen, not a stack trace:
            // the commonest cause is a workbook saved in a format the reader
            // does not handle, and the user can simply upload it again as CSV.
            return ['error' => $exception->getMessage()];
        }
    }
}

<?php

namespace App\Http\Controllers\Rcsa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rcsa\StoreRcsaExportRequest;
use App\Jobs\GenerateRcsaExportJob;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaCycle;
use App\Models\Rcsa\RcsaExportJob;
use App\Services\Rcsa\RcsaExportService;
use App\Services\Rcsa\RcsaWorkbookWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The authorised bulk download and its log (§10.1, §10.2).
 *
 * SMALL EXPORTS STREAM, LARGE ONES QUEUE, and the user is told which before
 * they commit: the screen shows the row count their filters select and says
 * whether it will arrive now or as a link. An export that silently became a
 * background job is one people press twice.
 *
 * EVERY EXPORT IS LOGGED WHETHER OR NOT IT IS QUEUED. The synchronous path
 * writes its row too — an export is an export, and a log that only recorded the
 * big ones would answer "who has a copy of Treasury's assessment" wrongly in
 * precisely the common case.
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly RcsaExportService $exports,
        private readonly RcsaWorkbookWriter $writer,
    ) {}

    /**
     * The export screen, and the log beneath it.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', RcsaExportJob::class);

        $user = $request->user();

        // §10.2: "Exports are visible to administrators in an export log."
        // Everybody else sees their own — not for secrecy but because a risk
        // champion scrolling the whole bank's export history learns nothing and
        // loses their own.
        $seesEverything = $user->can('rcsa_audit.view');

        $log = RcsaExportJob::query()
            ->with('user:id,name')
            ->when(! $seesEverything, fn ($q) => $q->where('user_id', $user->id))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $log->through(fn (RcsaExportJob $job) => [
            'id' => $job->id,
            'user' => $job->getRelationValue('user')?->name,
            'is_mine' => (int) $job->user_id === (int) $user->id,
            'filters' => $job->filters ?? [],
            'status' => $job->status,
            'row_count' => $job->row_count,
            'created_at' => $job->created_at?->toDateTimeString(),
            'expires_at' => $job->expires_at?->toDateTimeString(),
            'has_expired' => $job->hasExpired(),
            'downloaded_at' => $job->downloaded_at?->toDateTimeString(),
            'download_count' => $job->download_count,
            'ip_address' => $seesEverything ? $job->ip_address : null,
            'failure_reason' => $job->failure_reason,
            // Signed here rather than on the client: the signature has to be
            // made server-side, and only for a row that is actually collectable.
            'download_url' => $job->isCollectable()
                ? URL::temporarySignedRoute('rcsa.exports.download', $job->expires_at, ['export' => $job->id], absolute: false)
                : null,
        ]);

        return Inertia::render('RcsaExports/Index', [
            'log' => $log,
            'sees_everything' => $seesEverything,
            'cycles' => RcsaCycle::query()->orderByDesc('period_start')->get(['id', 'name'])->all(),
            'units' => $this->exports->unitOptions($user),
            'statuses' => [
                RcsaAssessment::IN_PROGRESS, RcsaAssessment::SUBMITTED,
                RcsaAssessment::UNDER_REVIEW, RcsaAssessment::VALIDATED,
                RcsaAssessment::RETURNED, RcsaAssessment::CLOSED,
            ],
            'sync_limit' => RcsaExportService::SYNC_LIMIT,
            'link_ttl_hours' => RcsaExportService::LINK_TTL_HOURS,
        ]);
    }

    /**
     * How many rows the current filters select — the preview beside the button.
     */
    public function preview(Request $request)
    {
        Gate::authorize('viewAny', RcsaExportJob::class);

        $filters = $this->filters($request);
        $count = $this->exports->count($filters, $request->user());

        return response()->json([
            'rows' => $count,
            'synchronous' => $this->exports->isSynchronous($count),
        ]);
    }

    /**
     * Run one.
     */
    public function store(StoreRcsaExportRequest $request)
    {
        $filters = $this->filters($request);
        $count = $this->exports->count($filters, $request->user());

        if ($count === 0) {
            return back()->with('error', 'Those filters select no risks. Nothing was exported and nothing was logged.');
        }

        if (! $this->exports->isSynchronous($count)) {
            $export = $this->exports->log($request->user(), $filters, $count, $request, RcsaExportJob::QUEUED);

            GenerateRcsaExportJob::dispatch($export->id);

            return back()->with('success', sprintf(
                '%d rows is too many to build while you wait, so it is running in the background. '
                .'You will get a link when it is ready.',
                $count,
            ));
        }

        $export = $this->exports->log($request->user(), $filters, $count, $request, RcsaExportJob::READY);

        $lines = $this->exports->lines($filters, $request->user());

        $contents = $this->writer->bulkExport($lines, $filters, $request->user()->name);

        // Streamed, not stored: a file the user is holding in their browser
        // right now does not need a copy on the disk to expire later. The LOG
        // row still exists, which is what §10.2 actually asks for.
        $export->forceFill([
            'downloaded_at' => now(),
            'download_count' => 1,
            'expires_at' => null,
        ])->save();

        return $this->stream($contents, sprintf('rcsa-export-%s.xlsx', now()->format('Y-m-d')));
    }

    /**
     * Collect a queued export.
     *
     * SIGNED **AND** AUTHORISED. The `signed` middleware stops the URL being
     * guessed or enumerated; it is not authorisation, so the permission, the
     * tenant and the ownership are all checked here too. A link forwarded to a
     * colleague without `rcsa_export.bulk` gets nothing.
     */
    public function download(Request $request, RcsaExportJob $export): StreamedResponse
    {
        Gate::authorize('view', $export);

        abort_unless($export->isCollectable(), 410, $export->hasExpired()
            ? 'This download link has expired. Run the export again.'
            : 'This export is not ready.');

        abort_unless(Storage::disk('local')->exists($export->file_path), 404);

        $export->forceFill([
            'downloaded_at' => now(),
            'download_count' => (int) $export->download_count + 1,
        ])->save();

        return Storage::disk('local')->download(
            $export->file_path,
            sprintf('rcsa-export-%d.xlsx', $export->id),
        );
    }

    /* ------------------------------------------------------------------ */
    /*  The offline working copy (§10.4) */
    /* ------------------------------------------------------------------ */

    /**
     * One in-progress assessment, as a file to edit on a laptop with no
     * connectivity.
     *
     * Gated on `complete`, not on the export permission: taking your own
     * unit's assessment away to fill in is part of doing it, and requiring the
     * bulk-download permission would mean a risk champion could not use the
     * feature the plan calls a differentiator for exactly their situation.
     */
    public function workingCopy(Request $request, RcsaAssessment $assessment): StreamedResponse
    {
        Gate::authorize('complete', $assessment);

        $assessment->load(['cycle:id,name', 'businessUnit:id,name']);

        $lines = $assessment->lines()->with(['actionPlans.owner:id,name', 'assessor:id,name'])->get();

        $contents = $this->writer->workingCopy($assessment, $lines);

        return $this->stream($contents, sprintf(
            'rcsa-working-copy-%d-%s.xlsx',
            $assessment->id,
            now()->format('Y-m-d'),
        ));
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return array_filter(
            $request->only([
                'cycle', 'business_units', 'risk_category', 'inherent_level',
                'residual_level', 'treatment', 'appetite', 'assessment_status', 'from', 'to',
            ]),
            fn ($value) => $value !== null && $value !== '' && $value !== [],
        );
    }

    private function stream(string $contents, string $filename): StreamedResponse
    {
        return response()->streamDownload(
            fn () => print $contents,
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}

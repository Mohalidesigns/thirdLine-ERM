<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\StoreEvidenceRequest;
use App\Models\Bcms\Evidence;
use App\Models\Bcms\ExerciseOccurrence;
use App\Services\Bcms\Exercises\EvidenceService;
use App\Services\FileUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Evidence upload, download and delete-before-lock (ADR 0019).
 *
 * NESTED UNDER THE OCCURRENCE, `->scopeBindings()`'d (ADR 0019 §4): the
 * occurrence is the single anchor path, and an evidence row addressed through
 * the wrong occurrence must 404, not resolve.
 *
 * DELETE REFUSES A LOCKED ROW, AND LOGS THE REFUSAL. `EvidenceService::
 * delete()` is where that happens — this controller only turns the exception
 * into a flash message, per the product's lifecycle-as-flash rule.
 */
class EvidenceController extends Controller
{
    public function __construct(
        private EvidenceService $evidence,
        private FileUploadService $uploads,
    ) {}

    public function store(StoreEvidenceRequest $request, ExerciseOccurrence $occurrence): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->evidence->upload(
                $occurrence,
                $request->file('file'),
                $data['owner_type'],
                (int) $data['owner_id'],
                $data['kind'],
                $request->user(),
                $data['caption'] ?? null,
                $data['iso_clause_ref'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Uploaded.');
    }

    public function download(Request $request, ExerciseOccurrence $occurrence, Evidence $evidence): StreamedResponse
    {
        Gate::authorize('bcms.exercise.view');

        // FileUploadService refuses with a bare RuntimeException — a file that
        // is no longer on the disk, or a stored path that leaves the evidence
        // root. Neither is a server error. The first will be ordinary once
        // retention runs: the policy is to delete the file and keep the row
        // (NDPA register §8.1; §10 records that no purge command exists yet),
        // and the row's download link outlives the file. Uncaught, both were
        // a 500.
        try {
            return $this->uploads->download($evidence->file_path, $evidence->file_name);
        } catch (RuntimeException $e) {
            // The storage layer's OWN failures are not "no longer available".
            // Flysystem's exceptions extend RuntimeException too — a file that
            // exists but cannot be read, a disk that stopped answering — and
            // answering those with a 404 and a warning would hide an outage
            // from anything that pages on errors. They stay a 500.
            if ($e instanceof FilesystemException) {
                throw $e;
            }

            // The same answer either way; the detail goes to the log, not to
            // the response. No file name and no path: evidence can name a person.
            Log::warning('BCMS evidence could not be served.', [
                'evidence_id' => $evidence->getKey(),
                'occurrence_id' => $occurrence->getKey(),
                'reason' => $e->getMessage(),
            ]);

            abort(404, 'That file is no longer available.');
        }
    }

    public function destroy(Request $request, ExerciseOccurrence $occurrence, Evidence $evidence): RedirectResponse
    {
        Gate::authorize('bcms.exercise.facilitate');

        try {
            $this->evidence->delete($evidence, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Removed.');
    }
}

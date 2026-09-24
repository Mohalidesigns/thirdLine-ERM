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
use InvalidArgumentException;
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

        return $this->uploads->download($evidence->file_path, $evidence->file_name);
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

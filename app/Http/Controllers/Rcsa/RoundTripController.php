<?php

namespace App\Http\Controllers\Rcsa;

use App\Http\Controllers\Controller;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaImportBatch;
use App\Models\Rcsa\RcsaImportRow;
use App\Services\Rcsa\RcsaRoundTripService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The upload half of §10.4 — a working copy coming back from a laptop.
 *
 * PARSED IN THE REQUEST, NOT ON A QUEUE, and that is a deliberate difference
 * from the universe import. A working copy is one assessment: a few hundred
 * rows at most, bounded by the number of risks the unit was given. The universe
 * import can be five thousand rows of a file somebody assembled by hand, which
 * is why it queues. Making this one queue too would put a spinner between the
 * user and the conflict screen they came here for.
 *
 * NOTHING IS WRITTEN UNTIL THE USER PRESSES APPLY. Same shape as the universe
 * import: stage, preview, confirm. The conflict screen in between is the entire
 * feature — an offline round trip that silently overwrote a colleague's morning
 * would be worse than no offline round trip.
 */
class RoundTripController extends Controller
{
    public function __construct(private readonly RcsaRoundTripService $roundTrip) {}

    /**
     * Receive a filled-in working copy.
     */
    public function store(Request $request, RcsaAssessment $assessment)
    {
        Gate::authorize('complete', $assessment);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:20480'],
        ], [
            'file.mimes' => 'Upload the .xlsx working copy the system produced.',
        ]);

        // The private disk. An RCSA working copy is the bank's operational risk
        // profile for one unit, and every other upload in this product moved
        // off the web-served disk for that reason.
        $path = $request->file('file')->store('rcsa/round-trip', 'local');

        $batch = RcsaImportBatch::create([
            'organization_id' => TenantContext::organizationId(),
            'user_id' => $request->user()->id,
            'type' => 'assessment',
            'assessment_id' => $assessment->id,
            'file_path' => $path,
            'original_name' => $request->file('file')->getClientOriginalName(),
            'status' => RcsaImportBatch::QUEUED,
        ]);

        try {
            $this->roundTrip->stage($batch);
        } catch (\RuntimeException $e) {
            $batch->update(['status' => RcsaImportBatch::FAILED, 'failure_reason' => $e->getMessage()]);

            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('rcsa.round-trip.show', [$assessment, $batch])
            ->with('success', 'File read. Nothing has been changed yet — check the rows below and apply.');
    }

    /**
     * The conflict-resolution screen (§10.4).
     */
    public function show(Request $request, RcsaAssessment $assessment, RcsaImportBatch $batch)
    {
        Gate::authorize('complete', $assessment);
        abort_unless((int) $batch->assessment_id === (int) $assessment->id, 404);

        $rows = $this->roundTrip->preview($batch);

        return Inertia::render('RcsaRoundTrip/Show', [
            'assessment' => [
                'id' => $assessment->id,
                'business_unit' => $assessment->businessUnit?->name,
                'status' => $assessment->status,
                'editable' => $assessment->acceptsEdits(),
            ],
            'batch' => [
                'id' => $batch->id,
                'original_name' => $batch->original_name,
                'status' => $batch->status,
                'total_rows' => $batch->total_rows,
                'uploaded_at' => $batch->created_at?->toDateTimeString(),
                'applied' => $batch->status === RcsaImportBatch::PUBLISHED,
                'updated_count' => $batch->updated_count,
            ],
            'rows' => $rows,
            'summary' => [
                'total' => count($rows),
                'changes' => count(array_filter(
                    $rows,
                    fn ($r) => $r['changes'] !== [] && ! $r['is_conflict'] && ! $r['is_locked'] && $r['status'] !== 'error',
                )),
                'conflicts' => count(array_filter($rows, fn ($r) => $r['is_conflict'])),
                'locked' => count(array_filter($rows, fn ($r) => $r['is_locked'])),
                'errors' => count(array_filter($rows, fn ($r) => $r['status'] === 'error')),
                'unchanged' => count(array_filter(
                    $rows,
                    fn ($r) => $r['changes'] === [] && ! $r['is_locked'] && $r['status'] !== 'error',
                )),
            ],
        ]);
    }

    /**
     * Choose whose answer to keep on one row.
     */
    public function resolve(Request $request, RcsaAssessment $assessment, RcsaImportBatch $batch, RcsaImportRow $row)
    {
        Gate::authorize('complete', $assessment);
        abort_unless((int) $batch->assessment_id === (int) $assessment->id && (int) $row->batch_id === (int) $batch->id, 404);

        $action = $request->validate([
            'action' => ['required', 'in:'.RcsaRoundTripService::ACTION_MINE.','.RcsaRoundTripService::ACTION_THEIRS],
        ])['action'];

        try {
            $this->roundTrip->resolve($row, $action);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    /**
     * Write the rows the user kept.
     */
    public function apply(Request $request, RcsaAssessment $assessment, RcsaImportBatch $batch)
    {
        Gate::authorize('complete', $assessment);
        abort_unless((int) $batch->assessment_id === (int) $assessment->id, 404);

        if ($batch->status === RcsaImportBatch::PUBLISHED) {
            return back()->with('error', 'This upload has already been applied.');
        }

        try {
            $result = $this->roundTrip->apply($batch, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('rcsa.assessments.show', $assessment)
            ->with('success', sprintf(
                '%d risk%s updated from your working copy%s.',
                $result['applied'],
                $result['applied'] === 1 ? '' : 's',
                $result['refused'] > 0
                    ? sprintf('. %d could not be changed because the ORM did not reopen them', $result['refused'])
                    : '',
            ));
    }
}

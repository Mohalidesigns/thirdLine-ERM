<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * WP-07 TASK 1 — the background jobs screen.
 *
 * Not Horizon. Horizon is for whoever runs the platform and shows queues,
 * throughput and payloads; this is for the person who pressed a button and
 * wants to know whether their board pack is ready. Different audience, different
 * permission, different vocabulary.
 */
class JobRunController extends Controller
{
    public function index(Request $request)
    {
        $canSeeAll = $request->user()->can('admin.queues');

        Gate::authorize('viewAny', JobRun::class);

        $runs = JobRun::query()
                // Without admin.queues you see the jobs you started. A job label
                // names the record it is about — "Board pack, Q2 2026" — so a
                // full list would be a list of what everybody is working on.
            ->when(! $canSeeAll, fn ($q) => $q->where('created_by', $request->user()->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with('creator:id,name')
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Admin/Jobs/Index', [
            'runs' => $runs->through(fn (JobRun $run) => array_merge($run->only([
                'id', 'label', 'status', 'progress', 'subject_type', 'subject_id', 'error',
            ]), [
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'created_at' => $run->created_at?->toIso8601String(),
                'cancel_requested_at' => $run->cancel_requested_at?->toIso8601String(),
                'creator' => $run->getRelationValue('creator')?->name,
                'is_finished' => $run->isFinished(),
                'can_cancel' => ! $run->isFinished() && Gate::allows('cancel', $run),
            ])),
            'filters' => ['status' => (string) $request->query('status', '')],
            'canSeeAll' => $canSeeAll,
            'active' => JobRun::query()
                ->when(! $canSeeAll, fn ($q) => $q->where('created_by', $request->user()->id))
                ->active()
                ->count(),
        ]);
    }

    /**
     * Ask a running job to stop.
     *
     * A request, not an interrupt: a worker cannot be killed from a web
     * request, only told. The job checks between units of work and stops
     * somewhere it can leave things consistent.
     */
    public function cancel(Request $request, JobRun $jobRun)
    {
        // Your own, or anybody's with admin.queues — JobRunPolicy.
        Gate::authorize('cancel', $jobRun);

        if ($jobRun->isFinished()) {
            return back()->with('error', 'That job has already finished.');
        }

        $jobRun->forceFill([
            'cancel_requested_at' => now(),
            'cancel_requested_by' => $request->user()->id,
        ])->save();

        // A simulation also carries the request on its own row, because the
        // quantification screens read that table rather than job_runs.
        if ($jobRun->subject_type === 'simulation_run') {
            \App\Models\SimulationRun::withoutGlobalScopes()
                ->whereKey($jobRun->subject_id)
                ->update(['cancel_requested_at' => now()]);
        }

        return back()->with('success', 'Cancellation requested. The job stops at its next checkpoint.');
    }
}

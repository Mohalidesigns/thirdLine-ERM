<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\JobRun;
use Illuminate\Http\Request;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The session-authenticated twin of GET api/v1/jobs/{jobRun}, for the SPA's
 * useJobProgress hook (migration Phase 2). Polls rather than pushes: this
 * platform runs on-premise where a WebSocket through the corporate proxy is
 * a project of its own, and a job that takes ninety seconds does not need
 * sub-second latency.
 */
class JobProgressController extends Controller
{
    public function show(Request $request, JobRun $jobRun)
    {
        abort_unless($jobRun->organization_id === TenantContext::organizationIdOrNull(), 404);

        return response()->json([
            'id' => $jobRun->id,
            'label' => $jobRun->label,
            'status' => $jobRun->status,
            'color' => $jobRun->statusColor(),
            'progress' => (int) $jobRun->progress,
            'processed' => $jobRun->processed,
            'total' => $jobRun->total,
            'message' => $jobRun->message,
            'error' => $jobRun->error,
            'finished' => $jobRun->isFinished(),
            'cancel_requested' => $jobRun->isCancelRequested(),
            'can_cancel' => ! $jobRun->isFinished()
                && ($jobRun->created_by === $request->user()->id || $request->user()->can('admin.queues')),
            'estimated_seconds_remaining' => $jobRun->estimatedSecondsRemaining(),
            'duration_seconds' => $jobRun->durationSeconds(),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobRun;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The progress of a queued job, for a client that started one.
 *
 * WP-07 moved the long work onto a queue, which means an API client that asks
 * for a report or a simulation gets a job id rather than a result. Without this
 * endpoint that id is useless and the client has to poll the resource itself
 * and guess when it is done.
 */
class JobRunController extends Controller
{
    public function show(Request $request, JobRun $jobRun): JsonResponse
    {
        abort_unless($jobRun->organization_id === TenantContext::organizationId(), 404);

        return response()->json([
            'data' => [
                'type' => 'jobs',
                'id' => (string) $jobRun->id,
                'attributes' => [
                    'label' => $jobRun->label,
                    'status' => $jobRun->status,
                    'progress' => $jobRun->progress,
                    'processed' => $jobRun->processed,
                    'total' => $jobRun->total,
                    'message' => $jobRun->message,
                    'error' => $jobRun->error,
                    'result' => $jobRun->result,
                    'subject' => $jobRun->subject_type === null ? null : [
                        'type' => $jobRun->subject_type,
                        'id' => (string) $jobRun->subject_id,
                    ],
                    'queued_at' => $jobRun->queued_at?->toIso8601String(),
                    'started_at' => $jobRun->started_at?->toIso8601String(),
                    'finished_at' => $jobRun->finished_at?->toIso8601String(),
                    'duration_seconds' => $jobRun->durationSeconds(),
                    'estimated_seconds_remaining' => $jobRun->estimatedSecondsRemaining(),
                ],
            ],
        ]);
    }
}

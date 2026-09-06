<?php

namespace App\Policies;

use App\Models\JobRun;
use App\Models\User;

/**
 * Who may see and stop background jobs (migration Phase 6.7).
 *
 * Not Horizon. Horizon is for whoever runs the platform; this is for the person
 * who pressed a button and wants to know whether their board pack is ready.
 *
 * WITHOUT `admin.queues` YOU SEE THE JOBS YOU STARTED. A job label names the
 * record it is about — "Board pack, Q2 2026" — so a full list would be a list
 * of what everybody in the institution is working on.
 */
class JobRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('job.view');
    }

    public function view(User $user, JobRun $run): bool
    {
        if ((int) $run->organization_id !== (int) $user->organization_id) {
            return false;
        }

        return (int) $run->created_by === (int) $user->id || $user->can('admin.queues');
    }

    /**
     * Ask a running job to stop.
     *
     * A request, not an interrupt: a worker cannot be killed from a web
     * request, only told. The job checks between units of work and stops
     * somewhere it can leave things consistent.
     */
    public function cancel(User $user, JobRun $run): bool
    {
        return $this->view($user, $run);
    }
}

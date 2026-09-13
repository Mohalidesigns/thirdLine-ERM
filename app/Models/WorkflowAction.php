<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The immutable log of everything that happened to an instance.
 *
 * Distinct from workflow_tasks, which holds current state: a task is
 * reassigned in place when it is delegated, and this table is where the fact
 * that it USED to belong to somebody else survives that.
 *
 * actor_id is nullable — a task expiring on its SLA is an action with no actor,
 * and recording the sweeper's decision against whichever user triggered the
 * cron would be a false attribution in an audit log.
 */
class WorkflowAction extends Model
{
    protected $fillable = [
        'instance_id', 'task_id', 'stage', 'stage_name', 'node_code', 'actor_id',
        'action', 'comments', 'delegated_to', 'acted_at',
    ];

    protected $casts = ['acted_at' => 'datetime'];

    public function instance()
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    public function task()
    {
        return $this->belongsTo(WorkflowTask::class, 'task_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function delegatee()
    {
        return $this->belongsTo(User::class, 'delegated_to');
    }

    /** Reads "the platform" when nobody acted — an SLA expiry or a service task. */
    public function actorLabel(): string
    {
        return $this->actor?->name ?? 'the platform';
    }
}

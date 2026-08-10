<?php

namespace App\Models;

use App\Enums\WorkflowInstanceStatus;
use App\Enums\WorkflowTaskStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Services\Workflow\WorkflowGraph;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One run of one workflow definition over one subject.
 *
 * current_nodes is an ARRAY. That is the whole difference from the previous
 * engine, whose current_stage was an integer index into a list — a shape in
 * which a parallel review (legal and risk, concurrently) simply cannot be
 * expressed, and so was never offered.
 *
 * current_stage is still maintained, as the count of completed human nodes, so
 * the existing instance view keeps working for one release.
 */
class WorkflowInstance extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'definition_id', 'definition_version', 'entity_type',
        'entity_id', 'current_stage', 'current_nodes', 'context', 'status',
        'outcome', 'started_at', 'sla_due_at', 'breached_at', 'correlation_key',
        'completed_at', 'initiated_by', 'cancellation_reason', 'cancelled_by',
    ];

    protected $casts = [
        'status' => WorkflowInstanceStatus::class,
        'current_nodes' => 'array',
        'context' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'sla_due_at' => 'datetime',
        'breached_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function definition()
    {
        return $this->belongsTo(WorkflowDefinition::class, 'definition_id');
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function actions()
    {
        return $this->hasMany(WorkflowAction::class, 'instance_id')->orderBy('acted_at');
    }

    public function tasks()
    {
        return $this->hasMany(WorkflowTask::class, 'instance_id')->orderBy('id');
    }

    public function openTasks()
    {
        return $this->tasks()->whereIn('status', WorkflowTaskStatus::openValues());
    }

    public function entity()
    {
        return $this->morphTo('entity', 'entity_type', 'entity_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            WorkflowInstanceStatus::Active->value,
            WorkflowInstanceStatus::Escalated->value,
        ]);
    }

    public function scopeForEntity(Builder $query, Model $entity): Builder
    {
        return $query->where('entity_type', $entity->getMorphClass())->where('entity_id', $entity->getKey());
    }

    /* ------------------------------------------------------------------ */
    /*  Graph */
    /* ------------------------------------------------------------------ */

    /**
     * The graph this instance is pinned to.
     *
     * Read from definition_id, which points at a specific version row — never
     * re-resolved by code, because that would silently move a running instance
     * onto a definition somebody published this morning.
     */
    public function definitionSnapshot(): WorkflowGraph
    {
        return WorkflowGraph::make($this->definition?->definition);
    }

    /** @return list<string> */
    public function currentNodeCodes(): array
    {
        return array_values(array_filter((array) ($this->current_nodes ?? []), 'is_string'));
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * A context value by dot path, e.g. contextValue('subject.risk_code').
     */
    public function contextValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->context ?? [], $key, $default);
    }
}

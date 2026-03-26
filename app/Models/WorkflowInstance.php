<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowInstance extends Model
{
    protected $fillable = [
        'definition_id', 'entity_type', 'entity_id', 'current_stage',
        'status', 'started_at', 'completed_at', 'initiated_by',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function definition() { return $this->belongsTo(WorkflowDefinition::class, 'definition_id'); }
    public function initiator()  { return $this->belongsTo(User::class, 'initiated_by'); }
    public function actions()    { return $this->hasMany(WorkflowAction::class, 'instance_id')->orderBy('acted_at'); }

    public function entity()
    {
        return $this->morphTo('entity', 'entity_type', 'entity_id');
    }
}

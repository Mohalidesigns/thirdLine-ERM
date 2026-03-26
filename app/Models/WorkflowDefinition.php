<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkflowDefinition extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'description', 'entity_type', 'stages',
        'escalation_rules', 'is_active', 'created_by',
    ];

    protected $casts = [
        'stages'           => 'array',
        'escalation_rules' => 'array',
        'is_active'        => 'boolean',
    ];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }
    public function instances()    { return $this->hasMany(WorkflowInstance::class, 'definition_id'); }
}

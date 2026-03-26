<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowAction extends Model
{
    protected $fillable = [
        'instance_id', 'stage', 'stage_name', 'actor_id', 'action',
        'comments', 'delegated_to', 'acted_at',
    ];

    protected $casts = ['acted_at' => 'datetime'];

    public function instance()   { return $this->belongsTo(WorkflowInstance::class, 'instance_id'); }
    public function actor()      { return $this->belongsTo(User::class, 'actor_id'); }
    public function delegatee()  { return $this->belongsTo(User::class, 'delegated_to'); }
}

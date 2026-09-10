<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A point-in-time snapshot of an object's graph-visible fields.
 *
 * Not a replacement for risk_audit_trail: that table is hash-chained,
 * append-only at the database level, and is the tamper-evident record a
 * regulator would ask for. This is the graph's own change history — cheap,
 * queryable, and safe to prune — and it records only what `objects` holds.
 */
class ObjectVersion extends Model
{
    use HasFactory;

    protected $table = 'object_versions';

    public $timestamps = false;

    protected $fillable = [
        'object_id',
        'version',
        'snapshot',
        'changed_by',
        'changed_at',
        'change_reason',
        'source',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'version' => 'integer',
        'changed_at' => 'datetime',
    ];

    public function object()
    {
        return $this->belongsTo(GraphObject::class, 'object_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

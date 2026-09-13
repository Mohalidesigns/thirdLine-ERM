<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One row of the org-model unification review queue.
 *
 * The unifier merges only what is unambiguous and records everything else here.
 * A `pending` row means the platform found two things that might be the same
 * node and refused to decide. Those decisions gate the release that drops
 * entities.business_unit_id and risks.entity_id — until the queue is empty,
 * the legacy columns are still the fallback.
 */
class ObjectMergeCandidate extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'object_merge_candidates';

    protected $fillable = [
        'organization_id',
        'left_source_type',
        'left_source_id',
        'left_name',
        'right_source_type',
        'right_source_id',
        'right_name',
        'similarity',
        'match_basis',
        'decision',
        'resolved_object_id',
        'notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'similarity' => 'decimal:4',
        'reviewed_at' => 'datetime',
    ];

    public function resolvedObject()
    {
        return $this->belongsTo(GraphObject::class, 'resolved_object_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('decision', 'pending');
    }

    /** Everything the unifier decided on its own, for after-the-fact review. */
    public function scopeAutomatic(Builder $query): Builder
    {
        return $query->whereIn('decision', ['auto_merged', 'auto_attached']);
    }
}

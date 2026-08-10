<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One typed edge between two objects.
 *
 * effective_from / effective_to make the edge period-aware: a control that
 * mitigated a risk through FY2025 and was replaced in FY2026 leaves both edges
 * in place, and a roll-up as at a date sees the one that was live then.
 */
class ObjectRelationship extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'object_relationships';

    protected $fillable = [
        'organization_id',
        'relationship_type_id',
        'from_object_id',
        'to_object_id',
        'weight',
        'attributes',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected $casts = [
        'attributes' => 'array',
        'weight' => 'decimal:4',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function relationshipType()
    {
        return $this->belongsTo(ObjectRelationshipType::class, 'relationship_type_id');
    }

    public function fromObject()
    {
        return $this->belongsTo(GraphObject::class, 'from_object_id');
    }

    public function toObject()
    {
        return $this->belongsTo(GraphObject::class, 'to_object_id');
    }

    public function scopeOfType(Builder $query, string $code): Builder
    {
        return $query->whereIn(
            'relationship_type_id',
            ObjectRelationshipType::query()->where('code', $code)->select('id')
        );
    }

    /**
     * Edges live on the given date.
     *
     * An edge with no dates is treated as always live, which is what every row
     * migrated from the old pivot tables is: those tables never recorded when a
     * mapping started or stopped applying.
     */
    public function scopeLiveOn(Builder $query, \DateTimeInterface|string|null $date = null): Builder
    {
        $date = $date ?: now()->toDateString();

        return $query
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }
}

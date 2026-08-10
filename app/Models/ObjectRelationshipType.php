<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A named kind of edge.
 *
 * from_type_ids / to_type_ids NULL means "any type". An empty array would mean
 * "no type", which would make the edge unusable — the distinction matters
 * because the seeder writes NULL for the deliberately generic edges.
 */
class ObjectRelationshipType extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'object_relationship_types';

    protected bool $tenantIncludesGlobal = true;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'inverse_code',
        'from_type_ids',
        'to_type_ids',
        'cardinality',
        'has_weight',
        'attribute_schema',
        'is_system',
    ];

    protected $casts = [
        'from_type_ids' => 'array',
        'to_type_ids' => 'array',
        'attribute_schema' => 'array',
        'has_weight' => 'boolean',
        'is_system' => 'boolean',
    ];

    public static function resolve(string $code): ?self
    {
        return static::query()
            ->where('code', $code)
            ->orderByRaw('CASE WHEN organization_id IS NULL THEN 1 ELSE 0 END')
            ->first();
    }

    public function relationships()
    {
        return $this->hasMany(ObjectRelationship::class, 'relationship_type_id');
    }

    /**
     * Whether an edge of this type may run between these two types.
     *
     * Enforced on write rather than by the schema: the constraint is "the id is
     * one of these", and no portable SQL expresses that against a JSON column.
     */
    public function permits(int $fromTypeId, int $toTypeId): bool
    {
        $allows = fn (?array $allowed, int $typeId) => $allowed === null || $allowed === [] || in_array($typeId, $allowed, true);

        return $allows($this->from_type_ids, $fromTypeId) && $allows($this->to_type_ids, $toTypeId);
    }
}

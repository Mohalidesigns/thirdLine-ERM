<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A kind of thing in the object graph.
 *
 * organization_id NULL means a system type, seeded from ObjectTypeRegistry and
 * shared by every tenant; $tenantIncludesGlobal makes the tenancy scope let
 * those through alongside the tenant's own types. A tenant may define its own
 * types with the same code as a system one — resolve() prefers the tenant's.
 */
class ObjectType extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'object_types';

    /** System types carry a NULL organization_id and belong to everyone. */
    protected bool $tenantIncludesGlobal = true;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'plural_name',
        'description',
        'backing_model',
        'category',
        'parent_type_id',
        'icon',
        'color',
        'level_hint',
        'is_system',
        'is_node_type',
        'allowed_child_type_ids',
        'default_lifecycle_id',
        'code_prefix',
        'sort_order',
    ];

    protected $casts = [
        'level_hint' => 'integer',
        'is_system' => 'boolean',
        'is_node_type' => 'boolean',
        'allowed_child_type_ids' => 'array',
        'sort_order' => 'integer',
    ];

    /**
     * The type with this code for the current tenant, preferring a tenant's own
     * definition over the system one of the same name.
     */
    public static function resolve(string $code, ?int $organizationId = null): ?self
    {
        return static::query()
            ->where('code', $code)
            ->when(
                $organizationId !== null,
                fn ($query) => $query->orderByRaw('CASE WHEN organization_id IS NULL THEN 1 ELSE 0 END')
            )
            ->first();
    }

    public function parentType()
    {
        return $this->belongsTo(self::class, 'parent_type_id');
    }

    public function childTypes()
    {
        return $this->hasMany(self::class, 'parent_type_id');
    }

    /**
     * NOT named attributes(): Eloquent already owns $model->attributes, and a
     * relation of that name shadows the model's own attribute bag in ways that
     * only show up at serialisation time.
     */
    public function attributeDefinitions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ObjectAttribute::class, 'object_type_id')->orderBy('sort_order');
    }

    public function lifecycles()
    {
        return $this->hasMany(ObjectLifecycle::class, 'object_type_id');
    }

    public function defaultLifecycle()
    {
        return $this->belongsTo(ObjectLifecycle::class, 'default_lifecycle_id');
    }

    public function objects()
    {
        return $this->hasMany(GraphObject::class, 'object_type_id');
    }

    /**
     * Own attributes plus every ancestor type's, nearest definition winning.
     *
     * Opportunity inherits Risk's attribute set; an Opportunity-specific
     * attribute with the same code overrides the inherited one rather than
     * appearing twice.
     *
     * @return \Illuminate\Support\Collection<string, ObjectAttribute>
     */
    public function resolvedAttributes(): \Illuminate\Support\Collection
    {
        $chain = collect();
        $type = $this;
        $guard = 0;

        while ($type !== null && $guard++ < 20) {
            $chain->push($type);
            $type = $type->parentType;
        }

        // Furthest ancestor first, so a nearer definition overwrites it.
        return $chain->reverse()
            ->flatMap(fn (self $t) => $t->attributeDefinitions)
            ->keyBy('code');
    }
}

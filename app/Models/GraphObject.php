<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A node in the object graph.
 *
 * Named GraphObject, not Object: `object` is a reserved word in PHP and cannot
 * be a class name. The table is `objects`.
 *
 * This row is an INDEX over a typed record, not the record itself. The typed
 * table stays the source of truth for its own domain columns — a risk's
 * inherent score lives on `risks` and nowhere else. What lives here is what the
 * graph needs: parentage, node ownership, lifecycle state, and the JSON bag of
 * configured attributes that has no column anywhere.
 */
class GraphObject extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'objects';

    protected $fillable = [
        'organization_id',
        'object_type_id',
        'node_id',
        'code',
        'name',
        'description',
        'owner_id',
        'delegate_owner_id',
        'lifecycle_state',
        'status',
        'effective_from',
        'effective_to',
        'attributes',
        'hierarchy_path',
        'hierarchy_depth',
        'parent_id',
        'sort_order',
        'source_model_type',
        'source_model_id',
        'created_by',
        'updated_by',
        'version',
    ];

    protected $casts = [
        // 'attributes' would collide with Eloquent's own attribute bag, so the
        // JSON column is reached through customAttributes() rather than being
        // cast. See getCustomAttributesAttribute() below.
        'effective_from' => 'date',
        'effective_to' => 'date',
        'hierarchy_depth' => 'integer',
        'sort_order' => 'integer',
        'version' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  The attributes JSON column */
    /* ------------------------------------------------------------------ */

    /**
     * The configured-attribute bag.
     *
     * Reached through a named method rather than a cast because the column is
     * called `attributes` and Eloquent's $attributes property is the model's
     * own storage — casting it would make $object->attributes ambiguous in a
     * way that fails silently during serialisation.
     *
     * @return array<string, mixed>
     */
    public function customAttributes(): array
    {
        $raw = $this->getAttributeFromArray('attributes');

        if ($raw === null || $raw === '') {
            return [];
        }

        return is_array($raw) ? $raw : (json_decode($raw, true) ?: []);
    }

    public function customAttribute(string $code, mixed $default = null): mixed
    {
        return $this->customAttributes()[$code] ?? $default;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setCustomAttributes(array $values): void
    {
        $this->setAttribute('attributes', json_encode($values));
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function objectType()
    {
        return $this->belongsTo(ObjectType::class, 'object_type_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** The org-graph node this object hangs off. Self, for an org node. */
    public function node()
    {
        return $this->belongsTo(self::class, 'node_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function delegateOwner()
    {
        return $this->belongsTo(User::class, 'delegate_owner_id');
    }

    /**
     * The typed record this object indexes.
     *
     * Uses the enforced morph map, so source_model_type holds 'risk', never a
     * namespace that a refactor would invalidate.
     */
    public function source()
    {
        return $this->morphTo(__FUNCTION__, 'source_model_type', 'source_model_id');
    }

    public function versions()
    {
        return $this->hasMany(ObjectVersion::class, 'object_id')->orderByDesc('version');
    }

    public function outgoingRelationships()
    {
        return $this->hasMany(ObjectRelationship::class, 'from_object_id');
    }

    public function incomingRelationships()
    {
        return $this->hasMany(ObjectRelationship::class, 'to_object_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeOfType(Builder $query, string $code): Builder
    {
        return $query->whereIn('object_type_id', ObjectType::query()->where('code', $code)->select('id'));
    }

    /** Org nodes only — the backbone types a governance object can hang off. */
    public function scopeNodes(Builder $query): Builder
    {
        return $query->whereIn('object_type_id', ObjectType::query()->where('is_node_type', true)->select('id'));
    }

    /**
     * This object and everything beneath it, as an indexed prefix match.
     */
    public function scopeInSubtreeOf(Builder $query, self|string $root): Builder
    {
        $path = $root instanceof self ? $root->pathOrFallback() : $root;

        return $query->where('hierarchy_path', 'like', $path.'%');
    }

    /**
     * The materialised path, reconstructed if it was never written.
     *
     * A NULL path would make an object invisible to every subtree query, which
     * on an authorization boundary is worse than being wrong.
     */
    public function pathOrFallback(): string
    {
        return $this->hierarchy_path ?: '/'.$this->getKey().'/';
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasObjectIdentity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property-read int|null $risks_count
 * @property-read int|null $issues_count
 * @property-read int|null $key_risk_indicators_count
 */
class Entity extends Model
{
    use BelongsToOrganization, HasFactory, HasObjectIdentity, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'entity_type_id',
        'parent_id',
        'entity_code',
        'name',
        'description',
        'owner_id',
        'delegate_owner_id',
        'status',
        'level',
        'hierarchy_path',
        'regulatory_frameworks',
        'risk_appetite_level',
        'category_appetites',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'level' => 'integer',
        'regulatory_frameworks' => 'array',
        'category_appetites' => 'array',
        'metadata' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });

        // Materialise the path so subtree authorization is an indexed prefix
        // match rather than a recursive walk. Written after insert because a
        // root node's path contains its own id.
        static::created(fn (self $model) => $model->refreshHierarchyPath());

        static::updated(function (self $model) {
            if ($model->wasChanged('parent_id')) {
                $model->refreshHierarchyPath();
            }
        });
    }

    /**
     * The object graph type for THIS row.
     *
     * An entity is not one kind of thing: entity_type_id decides whether it is
     * a Group, a Branch or a Process, so the type cannot be a constant on the
     * class the way it is for Risk. Falls back to BusinessUnit when the legacy
     * entity type has no counterpart in the system registry — the unification
     * migration records every such row in object_merge_candidates for retyping
     * rather than letting it disappear.
     */
    public function resolveObjectTypeCode(): string
    {
        $legacyCode = $this->entityType?->code;

        if ($legacyCode === null) {
            return 'BusinessUnit';
        }

        return \App\Support\Graph\ObjectTypeRegistry::legacyEntityTypeMap()[$legacyCode] ?? 'BusinessUnit';
    }

    /**
     * Recompute this node's path from its parent, then cascade to descendants.
     */
    public function refreshHierarchyPath(): void
    {
        $parentPath = $this->parent_id
            ? (self::query()->whereKey($this->parent_id)->value('hierarchy_path') ?: '/'.$this->parent_id.'/')
            : '/';

        $path = $parentPath.$this->getKey().'/';

        if ($this->hierarchy_path !== $path) {
            $this->hierarchy_path = $path;
            $this->saveQuietly();
        }

        foreach (self::query()->where('parent_id', $this->getKey())->get() as $child) {
            $child->refreshHierarchyPath();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<EntityType, $this> */
    public function entityType(): BelongsTo
    {
        return $this->belongsTo(EntityType::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Recursive children for hierarchy tree
     *
     * @return HasMany<self, $this>
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants.entityType');
    }

    /**
     * Recursive parent for full path
     *
     * @return BelongsTo<self, $this>
     */
    public function ancestors(): BelongsTo
    {
        return $this->parent()->with('ancestors');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function delegateOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Risk, $this> */
    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class);
    }

    /** @return HasMany<Control, $this> */
    public function controls(): HasMany
    {
        return $this->hasMany(Control::class);
    }

    /** @return HasMany<Issue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /** @return HasMany<LossEvent, $this> */
    public function lossEvents(): HasMany
    {
        return $this->hasMany(LossEvent::class);
    }

    /** @return HasMany<KeyRiskIndicator, $this> */
    public function keyRiskIndicators(): HasMany
    {
        return $this->hasMany(KeyRiskIndicator::class);
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors */
    /* ------------------------------------------------------------------ */

    public function getLevelLabelAttribute(): string
    {
        $typeName = $this->entityType?->name ?? 'Entity';

        return "L{$this->level} {$typeName}";
    }

    public function getFullPathAttribute(): string
    {
        $path = collect([$this->name]);
        $current = $this;

        while ($current->parent) {
            $current = $current->parent;
            $path->prepend($current->name);
        }

        return $path->implode(' → ');
    }

    public function getRegulatoryFrameworksListAttribute(): string
    {
        return $this->regulatory_frameworks
            ? implode(', ', $this->regulatory_frameworks)
            : '—';
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOfType($query, $typeId)
    {
        return $query->where('entity_type_id', $typeId);
    }
}

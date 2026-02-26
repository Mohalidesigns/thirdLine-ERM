<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Entity extends Model
{
    use HasFactory, SoftDeletes;

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
        'regulatory_frameworks',
        'risk_appetite_level',
        'category_appetites',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'level'                 => 'integer',
        'regulatory_frameworks' => 'array',
        'category_appetites'    => 'array',
        'metadata'              => 'array',
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
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function entityType()
    {
        return $this->belongsTo(EntityType::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Recursive children for hierarchy tree */
    public function descendants()
    {
        return $this->children()->with('descendants.entityType');
    }

    /** Recursive parent for full path */
    public function ancestors()
    {
        return $this->parent()->with('ancestors');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function delegateOwner()
    {
        return $this->belongsTo(User::class, 'delegate_owner_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function risks()
    {
        return $this->hasMany(Risk::class);
    }

    public function controls()
    {
        return $this->hasMany(Control::class);
    }

    public function issues()
    {
        return $this->hasMany(Issue::class);
    }

    public function lossEvents()
    {
        return $this->hasMany(LossEvent::class);
    }

    public function keyRiskIndicators()
    {
        return $this->hasMany(KeyRiskIndicator::class);
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors                                                          */
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
    /*  Scopes                                                             */
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

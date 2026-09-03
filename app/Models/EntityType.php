<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property-read int|null $entities_count
 */
class EntityType extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'description',
        'level',
        'icon',
        'color',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'level' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
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
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Entity, $this> */
    public function entities(): HasMany
    {
        return $this->hasMany(Entity::class);
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors */
    /* ------------------------------------------------------------------ */

    public function getLevelLabelAttribute(): string
    {
        return "L{$this->level} {$this->name}";
    }
}

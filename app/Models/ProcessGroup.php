<?php

namespace App\Models;

use App\Models\Concerns\HasObjectIdentity;
use App\Support\MorphTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Top-tier Process Group (APQC PCF-aligned hierarchy: Process Group -> Process -> Sub-Process).
 *
 * ADR 0028 Decision 3 & 5:
 * - Stored in its own table so `business_processes.parent_id IS NULL` remains strictly
 *   Level-2 Processes (preserving the RCSA v2 invariant).
 * - `business_unit_id` is nullable: NULL represents an enterprise-wide / cross-departmental group.
 */
class ProcessGroup extends Model
{
    use BelongsToOrganization, HasFactory, HasObjectIdentity, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $table = 'process_groups';

    protected $fillable = [
        'organization_id',
        'business_unit_id',
        'code',
        'name',
        'description',
        'apqc_code',
        'owner_id',
        'sort_order',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->status)) {
                $model->status = self::STATUS_ACTIVE;
            }
            if ($model->sort_order === null) {
                $model->sort_order = 0;
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

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasMany<BusinessProcess, $this> */
    public function processes(): HasMany
    {
        return $this->hasMany(BusinessProcess::class, 'process_group_id');
    }

    /** @return HasMany<BusinessProcess, $this> */
    public function topLevelProcesses(): HasMany
    {
        return $this->hasMany(BusinessProcess::class, 'process_group_id')->whereNull('parent_id');
    }

    /** @return HasMany<RiskAuditTrail, $this> */
    public function auditTrail(): HasMany
    {
        return $this->hasMany(RiskAuditTrail::class, 'entity_id')
            ->whereIn('entity_type', MorphTypes::spellingsFor($this->getMorphClass()));
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes & Helpers */
    /* ------------------------------------------------------------------ */

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** @param Builder<self> $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->isActive();
    }
}

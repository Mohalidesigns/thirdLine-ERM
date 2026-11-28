<?php

namespace App\Models;

use App\Enums\BaselEventTypeL1;
use App\Enums\BaselEventTypeL2;
use App\Models\Concerns\HasObjectIdentity;
use App\Support\MorphTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Master risk catalogue entry (ADR 0028).
 *
 * Tenant-scoped via BelongsToOrganization, entity- and department-agnostic
 * within a tenant, carries ZERO score columns (ISO 31000 §6.4.2), and is
 * retired rather than deleted (ADR 0028 Decision 6 — no SoftDeletes).
 */
class RiskLibraryItem extends Model
{
    use BelongsToOrganization, HasFactory, HasObjectIdentity;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_RETIRED];

    /** @var list<array{value: string, label: string}> */
    public const RISK_SOURCES = [
        ['value' => 'internal', 'label' => 'Internal'],
        ['value' => 'external', 'label' => 'External'],
        ['value' => 'people', 'label' => 'People'],
        ['value' => 'process', 'label' => 'Process'],
        ['value' => 'systems', 'label' => 'Systems & Technology'],
    ];

    /** @var list<string> */
    public const IMPACT_DOMAINS = [
        'Financial',
        'Regulatory',
        'Operational',
        'Reputational',
        'Legal',
        'Customer',
    ];

    /** @var list<string> */
    public const CONTROL_TYPES = [
        'preventive',
        'detective',
        'corrective',
    ];

    protected $fillable = [
        'organization_id',
        'code',
        'title',
        'description',
        'category_id',
        'risk_source',
        'basel_l1_event_type',
        'basel_l2_event_type',
        'typical_causes',
        'typical_consequences',
        'suggested_controls',
        'suggested_kris',
        'current_version',
        'status',
        'retired_at',
        'retired_by',
        'retirement_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'typical_causes' => 'array',
        'typical_consequences' => 'array',
        'suggested_controls' => 'array',
        'suggested_kris' => 'array',
        'current_version' => 'integer',
        'retired_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if ($model->current_version === null) {
                $model->current_version = 1;
            }
            if (empty($model->status)) {
                $model->status = self::STATUS_ACTIVE;
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

    /** @return BelongsTo<RiskCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RiskCategory::class, 'category_id');
    }

    /** @return HasMany<RiskLibraryItemVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(RiskLibraryItemVersion::class, 'library_item_id')
            ->orderByDesc('version_number');
    }

    /** @return HasOne<RiskLibraryItemVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(RiskLibraryItemVersion::class, 'library_item_id')
            ->ofMany('version_number', 'max');
    }

    /** @return HasMany<Risk, $this> */
    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class, 'library_item_id');
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

    /** @return BelongsTo<User, $this> */
    public function retiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by');
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
    public function scopeRetired(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RETIRED);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isRetired(): bool
    {
        return $this->status === self::STATUS_RETIRED;
    }

    public function baselL1Enum(): ?BaselEventTypeL1
    {
        return $this->basel_l1_event_type ? BaselEventTypeL1::tryFrom($this->basel_l1_event_type) : null;
    }

    public function baselL2Enum(): ?BaselEventTypeL2
    {
        return $this->basel_l2_event_type ? BaselEventTypeL2::tryFrom($this->basel_l2_event_type) : null;
    }
}

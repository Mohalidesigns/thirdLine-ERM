<?php

namespace App\Models;

use App\Models\Concerns\HasObjectIdentity;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Support\MorphTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

class BusinessProcess extends Model
{
    use BelongsToOrganization, HasFactory, HasObjectIdentity, SoftDeletes;

    /** @var list<string> */
    public const CRITICALITIES = ['critical', 'high', 'medium', 'low'];

    protected $table = 'business_processes';

    protected $fillable = [
        'organization_id',
        'business_unit_id',
        'process_group_id',
        'parent_id',
        'code',
        'apqc_code',
        'name',
        'description',
        'owner_id',
        'criticality',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** @return BelongsTo<ProcessGroup, $this> */
    public function processGroup(): BelongsTo
    {
        return $this->belongsTo(ProcessGroup::class, 'process_group_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class, 'process_id');
    }

    /** @return HasMany<RcsaRegisterRisk, $this> */
    public function rcsaRisks(): HasMany
    {
        return $this->hasMany(RcsaRegisterRisk::class, 'process_id');
    }

    /** @return BelongsToMany<Entity, $this> */
    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class, 'entity_business_process', 'business_process_id', 'entity_id')
            ->withPivot(['organization_id', 'is_primary', 'attached_by', 'attached_at', 'created_at']);
    }

    /**
     * The process this one is a sub-process of.
     *
     * Added by the RCSA rewrite, whose workbook has separate Process and
     * Sub-Process columns (C and D). A NULL parent is a top-level process,
     * which is every row that existed before that migration — nothing about
     * the risk register's use of this table changes.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    /** @return HasMany<self, $this> */
    public function subProcesses(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    /** @return HasMany<RiskAuditTrail, $this> */
    public function auditTrail(): HasMany
    {
        return $this->hasMany(RiskAuditTrail::class, 'entity_id')
            ->whereIn('entity_type', MorphTypes::spellingsFor($this->getMorphClass()));
    }

    /**
     * Top-level processes only.
     *
     * @param  Builder<self>  $query
     */
    public function scopeTopLevel($query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Active processes only.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }
}

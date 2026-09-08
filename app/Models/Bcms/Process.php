<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\Bcms\Concerns\ScopedToOrgHierarchy;
use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A business process, as the BCM discipline sees it.
 *
 * AN OVERLAY, NOT A SECOND CATALOGUE. `business_process_id` is nullable and
 * points at the org's own `business_processes` where it has one (ADR 0001).
 * `criticality_tier` is STORED and written only by the BIA approval path: a
 * tier fixed by the last approved BIA must not change because somebody edited
 * an impact score in a draft.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property ?int $business_unit_id
 * @property ?int $business_process_id
 * @property ?int $parent_process_id
 * @property string $code
 * @property string $name
 * @property ?string $description
 * @property ?int $owner_id
 * @property ?string $category
 * @property ?int $criticality_tier
 * @property bool $is_critical_service
 * @property ?string $critical_service_justification
 * @property array<array-key, mixed> $regulatory_flags
 * @property string $status
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Process extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, ScopedToOrgHierarchy, SoftDeletes;

    protected $table = 'bcms_processes';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'business_unit_id', 'business_process_id', 'parent_process_id', 'code',
        'name', 'description', 'owner_id', 'category', 'criticality_tier', 'is_critical_service', 'critical_service_justification',
        'regulatory_flags', 'status', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'regulatory_flags' => 'array',
            'organization_id' => 'integer',
            'business_unit_id' => 'integer',
            'business_process_id' => 'integer',
            'parent_process_id' => 'integer',
            'owner_id' => 'integer',
            'criticality_tier' => 'integer',
            'is_critical_service' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'business_unit_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<BusinessProcess, $this> */
    public function businessProcess(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class, 'business_process_id');
    }

    /** @return BelongsTo<Process, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Process::class, 'parent_process_id');
    }

    /** @return HasMany<Process, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Process::class, 'parent_process_id');
    }

    /** @return HasMany<BiaAssessment, $this> */
    public function assessments(): HasMany
    {
        return $this->hasMany(BiaAssessment::class, 'process_id');
    }

    /** @return HasMany<Strategy, $this> */
    public function strategies(): HasMany
    {
        return $this->hasMany(Strategy::class, 'process_id');
    }

    /**
     * RACI assignments against this process.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<RaciAssignment, $this>
     */
    public function raci(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(RaciAssignment::class, 'assignable');
    }

    /**
     * The one person accountable, or null — which is the gap report's whole
     * question. `owner_id` is who runs it day to day and is a different fact.
     */
    public function accountable(): ?\App\Models\User
    {
        return $this->raci()
            ->where('raci_role', \App\Enums\Bcms\RaciRole::Accountable->value)
            ->with('user')
            ->first()?->user;
    }
}

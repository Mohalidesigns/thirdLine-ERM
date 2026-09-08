<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\PlanType;
use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\Bcms\Concerns\ScopedToOrgHierarchy;
use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A continuity, recovery, crisis or incident plan.
 *
 * `content` and the section rows are both real: `content` holds the assembled
 * document, `bcms_plan_sections` holds the parts and their bindings to live
 * BIA and call-tree data. A section a human has edited is marked overridden so
 * regeneration never silently discards it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property ?int $business_unit_id
 * @property ?int $site_id
 * @property \App\Enums\Bcms\PlanType $plan_type
 * @property string $title
 * @property string $version
 * @property ?int $supersedes_plan_id
 * @property string $status
 * @property ?\Illuminate\Support\Carbon $effective_from
 * @property ?\Illuminate\Support\Carbon $next_review_date
 * @property ?int $owner_id
 * @property ?int $approver_id
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property array<array-key, mixed> $content
 * @property ?\Illuminate\Support\Carbon $offline_bundle_generated_at
 * @property ?string $offline_bundle_path
 * @property bool $ai_generated
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Plan extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, ScopedToOrgHierarchy, SoftDeletes;

    protected $table = 'bcms_plans';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'business_unit_id', 'site_id', 'plan_type', 'title', 'version',
        'supersedes_plan_id', 'status', 'effective_from', 'next_review_date', 'owner_id',
        'approver_id', 'approved_at', 'content', 'offline_bundle_generated_at',
        'offline_bundle_path', 'ai_generated', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'organization_id' => 'integer',
            'business_unit_id' => 'integer',
            'site_id' => 'integer',
            'supersedes_plan_id' => 'integer',
            'effective_from' => 'date',
            'next_review_date' => 'date',
            'owner_id' => 'integer',
            'approver_id' => 'integer',
            'approved_at' => 'datetime',
            'offline_bundle_generated_at' => 'datetime',
            'ai_generated' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'plan_type' => PlanType::class,
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

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /** @return BelongsTo<Plan, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'supersedes_plan_id');
    }

    /** @return HasMany<PlanSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(PlanSection::class, 'plan_id');
    }

    /** @return HasMany<PlanActivation, $this> */
    public function activations(): HasMany
    {
        return $this->hasMany(PlanActivation::class, 'plan_id');
    }

    /** @return HasMany<PlanAttestation, $this> */
    public function attestations(): HasMany
    {
        return $this->hasMany(PlanAttestation::class, 'plan_id');
    }

    /** @return HasMany<Plan, $this> */
    public function supersededBy(): HasMany
    {
        return $this->hasMany(Plan::class, 'supersedes_plan_id');
    }

    /**
     * An approved plan version is IMMUTABLE. It is superseded, never edited —
     * the supersession chain is the version history an auditor reads, and a
     * chain whose links can be rewritten is not a history.
     */
    public function isImmutable(): bool
    {
        return in_array($this->status, ['approved', 'archived'], true);
    }
}

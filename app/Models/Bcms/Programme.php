<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * The BC programme for a year: scope, policy, owner, approval and the board
 * attestation the CBN Corporate Governance Guidelines make a board act.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property string $name
 * @property int $year
 * @property ?string $scope_statement
 * @property ?string $out_of_scope_statement
 * @property array<array-key, mixed> $interested_parties
 * @property ?int $policy_plan_id
 * @property ?int $owner_id
 * @property string $status
 * @property ?int $approved_by
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property ?\Illuminate\Support\Carbon $board_attested_at
 * @property ?int $board_attested_by
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Programme extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, SoftDeletes;

    protected $table = 'bcms_programmes';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'name', 'year', 'scope_statement', 'out_of_scope_statement', 'interested_parties', 'policy_plan_id',
        'owner_id', 'status', 'approved_by', 'approved_at',
        'board_attested_at', 'board_attested_by', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'interested_parties' => 'array',
            'organization_id' => 'integer',
            'year' => 'integer',
            'policy_document_id' => 'integer',
            'owner_id' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'board_attested_at' => 'datetime',
            'board_attested_by' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<Objective, $this> */
    public function objectives(): HasMany
    {
        return $this->hasMany(Objective::class, 'programme_id');
    }

    /** @return HasMany<BiaCampaign, $this> */
    public function biaCampaigns(): HasMany
    {
        return $this->hasMany(BiaCampaign::class, 'programme_id');
    }

    /** @return HasMany<ExerciseProgramme, $this> */
    public function exerciseProgrammes(): HasMany
    {
        return $this->hasMany(ExerciseProgramme::class, 'programme_id');
    }

    /** @return BelongsTo<Plan, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'policy_plan_id');
    }

    /** @return HasMany<ProgrammeScopeItem, $this> */
    public function scopeItems(): HasMany
    {
        return $this->hasMany(ProgrammeScopeItem::class, 'programme_id');
    }

    /** @return HasMany<ProgrammeObligation, $this> */
    public function obligations(): HasMany
    {
        return $this->hasMany(ProgrammeObligation::class, 'programme_id');
    }

    /** @return HasMany<ManagementReview, $this> */
    public function managementReviews(): HasMany
    {
        return $this->hasMany(ManagementReview::class, 'programme_id');
    }

    /** @return HasMany<MaturityAssessment, $this> */
    public function maturityAssessments(): HasMany
    {
        return $this->hasMany(MaturityAssessment::class, 'programme_id');
    }

    /**
     * RACI assignments against the programme itself.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<RaciAssignment, $this>
     */
    public function raci(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(RaciAssignment::class, 'assignable');
    }
}

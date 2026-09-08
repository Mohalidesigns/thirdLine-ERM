<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\BiaAssessmentStatus;
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
 * One process's business impact analysis (ISO/TS 22317).
 *
 * MTPD, RTO and RPO are the four numbers the whole module turns on: every
 * strategy, plan, DR tier and regulatory return is measured against them, which
 * is why approving an assessment is a separate permission from completing one.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property ?int $campaign_id
 * @property int $process_id
 * @property ?int $assessor_id
 * @property BiaAssessmentStatus $status
 * @property ?string $mtpd_hours
 * @property ?string $derived_mtpd_hours
 * @property ?string $rto_hours
 * @property ?int $rpo_minutes
 * @property ?string $mbco_description
 * @property ?int $min_staff_required
 * @property array<array-key, mixed> $peak_periods
 * @property bool $workaround_available
 * @property ?string $workaround_max_duration_hours
 * @property bool $ai_generated
 * @property ?\Illuminate\Support\Carbon $ai_drafted_at
 * @property array<array-key, mixed> $ai_reasoning
 * @property ?\Illuminate\Support\Carbon $chased_at
 * @property int $chase_count
 * @property ?\Illuminate\Support\Carbon $escalated_at
 * @property ?int $escalated_to_user_id
 * @property ?\Illuminate\Support\Carbon $submitted_at
 * @property ?int $approved_by
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class BiaAssessment extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, SoftDeletes;

    protected $table = 'bcms_bia_assessments';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'campaign_id', 'process_id', 'assessor_id', 'status', 'mtpd_hours', 'derived_mtpd_hours',
        'rto_hours', 'rpo_minutes', 'mbco_description', 'min_staff_required', 'peak_periods',
        'workaround_available', 'workaround_max_duration_hours', 'ai_generated', 'ai_drafted_at', 'ai_reasoning', 'chased_at', 'chase_count', 'escalated_at', 'escalated_to_user_id',
        'submitted_at', 'approved_by', 'approved_at', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'peak_periods' => 'array',
            'organization_id' => 'integer',
            'campaign_id' => 'integer',
            'process_id' => 'integer',
            'assessor_id' => 'integer',
            'status' => BiaAssessmentStatus::class,
            'mtpd_hours' => 'decimal:2',
            'derived_mtpd_hours' => 'decimal:2',
            'ai_reasoning' => 'array',
            'chased_at' => 'datetime',
            'chase_count' => 'integer',
            'escalated_at' => 'datetime',
            'escalated_to_user_id' => 'integer',
            'rto_hours' => 'decimal:2',
            'rpo_minutes' => 'integer',
            'min_staff_required' => 'integer',
            'workaround_available' => 'boolean',
            'workaround_max_duration_hours' => 'decimal:2',
            'ai_generated' => 'boolean',
            'ai_drafted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<BiaCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(BiaCampaign::class, 'campaign_id');
    }

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class, 'process_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<BiaImpact, $this> */
    public function impacts(): HasMany
    {
        return $this->hasMany(BiaImpact::class, 'assessment_id');
    }

    /** @return HasMany<Dependency, $this> */
    public function dependencies(): HasMany
    {
        return $this->hasMany(Dependency::class, 'assessment_id');
    }
}

<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A candidate continuity strategy for a process (ISO 22331).
 *
 * `gap_vs_required_hours` is STORED against the assessment it was judged
 * against, because the required RTO moves with each BIA cycle and a derived
 * gap would silently rewrite last year's approved strategy paper.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int $process_id
 * @property string $strategy_type
 * @property ?string $title
 * @property ?string $description
 * @property ?int $cost_estimate_minor
 * @property ?string $currency
 * @property ?string $rto_achievable_hours
 * @property ?string $gap_vs_required_hours
 * @property ?int $assessed_against_assessment_id
 * @property bool $is_selected
 * @property ?string $selection_rationale
 * @property string $approval_status
 * @property ?int $approved_by
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property array<array-key, mixed> $resource_requirements
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Strategy extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, SoftDeletes;

    protected $table = 'bcms_strategies';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'process_id', 'strategy_type', 'title', 'description',
        'cost_estimate_minor', 'currency', 'rto_achievable_hours', 'gap_vs_required_hours',
        'assessed_against_assessment_id', 'is_selected', 'selection_rationale', 'approval_status',
        'approved_by', 'approved_at', 'resource_requirements', 'iso_clause_ref', 'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'resource_requirements' => 'array',
            'organization_id' => 'integer',
            'process_id' => 'integer',
            'cost_estimate_minor' => 'integer',
            'rto_achievable_hours' => 'decimal:2',
            'gap_vs_required_hours' => 'decimal:2',
            'assessed_against_assessment_id' => 'integer',
            'is_selected' => 'boolean',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class, 'process_id');
    }

    /** @return BelongsTo<BiaAssessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(BiaAssessment::class, 'assessed_against_assessment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

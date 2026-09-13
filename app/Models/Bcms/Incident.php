<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\Bcms\Concerns\ScopedToOrgHierarchy;
use App\Models\BusinessUnit;
use App\Models\LossEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A disruption, declared and run.
 *
 * `detected_at` and `declared_at` are different moments and both matter: the
 * regulatory clock runs from detection, the crisis team's response time from
 * declaration. `is_exercise` keeps a drill's incident record out of the loss
 * history a regulator reads.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property string $reference
 * @property string $title
 * @property ?string $incident_type
 * @property ?string $severity
 * @property ?int $business_unit_id
 * @property ?int $site_id
 * @property ?\Illuminate\Support\Carbon $detected_at
 * @property ?int $declared_by
 * @property ?\Illuminate\Support\Carbon $declared_at
 * @property ?\Illuminate\Support\Carbon $closed_at
 * @property string $status
 * @property ?string $activation_level
 * @property array<array-key, mixed> $impacted_processes
 * @property ?int $estimated_impact_minor
 * @property ?string $currency
 * @property bool $is_exercise
 * @property ?int $occurrence_id
 * @property bool $is_reportable
 * @property ?\Illuminate\Support\Carbon $regulator_notified_at
 * @property ?string $cbn_reference
 * @property ?\Illuminate\Support\Carbon $reporting_due_at
 * @property ?int $erm_loss_event_id
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Incident extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, ScopedToOrgHierarchy, SoftDeletes;

    protected $table = 'bcms_incidents';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'reference', 'title', 'incident_type', 'severity', 'business_unit_id',
        'site_id', 'detected_at', 'declared_by', 'declared_at', 'closed_at', 'status',
        'activation_level', 'impacted_processes', 'estimated_impact_minor', 'currency',
        'is_exercise', 'occurrence_id', 'is_reportable', 'regulator_notified_at', 'cbn_reference',
        'reporting_due_at', 'erm_loss_event_id', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'impacted_processes' => 'array',
            'organization_id' => 'integer',
            'business_unit_id' => 'integer',
            'site_id' => 'integer',
            'detected_at' => 'datetime',
            'declared_by' => 'integer',
            'declared_at' => 'datetime',
            'closed_at' => 'datetime',
            'estimated_impact_minor' => 'integer',
            'is_exercise' => 'boolean',
            'occurrence_id' => 'integer',
            'is_reportable' => 'boolean',
            'regulator_notified_at' => 'datetime',
            'reporting_due_at' => 'datetime',
            'erm_loss_event_id' => 'integer',
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

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /** @return BelongsTo<ExerciseOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(ExerciseOccurrence::class, 'occurrence_id');
    }

    /** @return BelongsTo<User, $this> */
    public function declaredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }

    /** @return HasMany<IncidentLogEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(IncidentLogEntry::class, 'incident_id');
    }

    /** @return HasMany<IncidentTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(IncidentTask::class, 'incident_id');
    }

    /** @return HasMany<Alert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'incident_id');
    }

    /** @return HasMany<Finding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class, 'incident_id');
    }

    /** @return BelongsTo<LossEvent, $this> */
    public function ermLossEvent(): BelongsTo
    {
        return $this->belongsTo(LossEvent::class, 'erm_loss_event_id');
    }
}

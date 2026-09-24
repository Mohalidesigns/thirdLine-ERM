<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\ActivationLevel;
use App\Enums\Bcms\IncidentSeverity;
use App\Enums\Bcms\IncidentStatus;
use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\BindsToVisibleRecord;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\Bcms\Concerns\ScopedToOrgHierarchy;
use App\Models\Bcms\Concerns\ScopedToOrgHierarchyContract;
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
 * PHASE 10 (ADR 0020): AN ANCHOR, LIKE `Plan`. `business_unit_id` is this
 * row's own unit column (the default `ScopedToOrgHierarchy` column), so a
 * Kano incident and a Lagos incident are visible only to the unit(s) each
 * belongs to, plus organisation-level and named-user readers — the same rule
 * every other BCMS anchor already follows.
 *
 * `reporting_due_at`, `regulator_notified_at` and `cbn_reference` ARE RETIRED
 * IN PLACE (ADR 0020 §2). The columns stay on the table until a later cleanup
 * migration drops them; nothing in this phase or after writes them, and
 * `bcms_incident_notifications` is the replacement — see
 * `App\Services\Bcms\Incidents\NotificationService`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property string $reference
 * @property string $title
 * @property ?string $incident_type
 * @property ?\App\Enums\Bcms\IncidentSeverity $severity
 * @property ?int $business_unit_id
 * @property ?int $site_id
 * @property ?\Illuminate\Support\Carbon $detected_at
 * @property ?int $declared_by
 * @property ?\Illuminate\Support\Carbon $declared_at
 * @property ?\Illuminate\Support\Carbon $closed_at
 * @property \App\Enums\Bcms\IncidentStatus $status
 * @property ?\App\Enums\Bcms\ActivationLevel $activation_level
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
class Incident extends Model implements ScopedToOrgHierarchyContract
{
    use BcmsAuditable, BelongsToOrganization, BindsToVisibleRecord, HasBcmsUuid, HasFactory, ScopedToOrgHierarchy, SoftDeletes;

    protected $table = 'bcms_incidents';

    /**
     * @var list<string>
     *
     * `reporting_due_at`, `regulator_notified_at` and `cbn_reference` are
     * DELIBERATELY ABSENT (ADR 0020 §2) — retired in place. Nothing writes
     * them from Phase 10 onward; `bcms_incident_notifications` is where that
     * information lives now, one row per regulator per submission.
     */
    protected $fillable = [
        'organization_id', 'reference', 'title', 'incident_type', 'severity', 'business_unit_id',
        'site_id', 'detected_at', 'declared_by', 'declared_at', 'closed_at', 'status',
        'activation_level', 'impacted_processes', 'estimated_impact_minor', 'currency',
        'is_exercise', 'occurrence_id', 'is_reportable',
        'erm_loss_event_id', 'iso_clause_ref', 'created_by', 'updated_by',
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
            'severity' => IncidentSeverity::class,
            'status' => IncidentStatus::class,
            'activation_level' => ActivationLevel::class,
            'is_reportable' => 'boolean',
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

    /** @return HasMany<IncidentNotification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(IncidentNotification::class, 'incident_id');
    }

    /** @return HasMany<PlanActivation, $this> */
    public function planActivations(): HasMany
    {
        return $this->hasMany(PlanActivation::class, 'incident_id');
    }

    /** @return HasMany<Aar, $this> (at most one — the unique index on incident_id) */
    public function reviews(): HasMany
    {
        return $this->hasMany(Aar::class, 'incident_id');
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

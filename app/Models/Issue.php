<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasObjectIdentity;
use App\Models\Concerns\ScopedToGraph;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Columns added by 2026_02_22_200038_align_schema_with_controllers through its
 * own addColumns() loop, which Larastan cannot see statically, plus the
 * is_overdue accessor below.
 *
 * @property string|null $root_cause
 * @property string|null $impact_description
 * @property string|null $recommended_action
 * @property string|null $closure_justification
 * @property string|null $evidence_of_resolution
 * @property \Illuminate\Support\Carbon|null $closure_requested_at
 * @property int|null $closure_requested_by
 * @property string|null $closure_rejection_reason
 * @property \Illuminate\Support\Carbon|null $closure_rejected_at
 * @property int|null $closure_rejected_by
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property int|null $closed_by
 * @property int|null $progress_percentage
 * @property \Illuminate\Support\Carbon|null $status_changed_at
 * @property int|null $status_changed_by
 * @property int|null $updated_by
 * @property-read bool $is_overdue
 */
class Issue extends Model
{
    use BelongsToOrganization, HasFactory, HasObjectIdentity, ScopedToGraph, SoftDeletes;

    /**
     * From IssueController's inline `in:` rules (migration Phase 4.4).
     *
     * @var list<string>
     */
    public const SOURCES = [
        'audit', 'risk_assessment', 'incident', 'regulatory',
        'self_identified', 'customer_complaint', 'other',
    ];

    /** @var list<string> */
    public const PRIORITIES = ['critical', 'high', 'medium', 'low'];

    /** @var list<string> */
    public const STATUSES = [
        'OPEN', 'IN_PROGRESS', 'OVERDUE', 'PENDING_CLOSURE', 'CLOSED', 'CANCELLED', 'REOPENED',
    ];

    protected $fillable = [
        'organization_id',
        'entity_id',
        'issue_reference',
        'title',
        'description',
        'observation',
        'criteria_violated',
        'issue_source',
        'examination_ref',
        'examination_date',
        'issue_category',
        'priority',
        'issue_status',
        'business_unit_id',
        'department',
        'responsible_owner_id',
        'regulatory_reportable',
        'cbn_reportable',
        'cbn_examination_finding',
        'cbn_response_deadline',
        'cbn_response_submitted',
        'bofia_reportable',
        'ndpa_reportable',
        'ndpa_breach_type',
        'management_response_due',
        'remediation_due_date',
        'actual_close_date',
        'management_response',
        'action_plan',
        'interim_controls',
        'current_escalation_level',
        'escalation_path',
        'potential_loss_kobo',
        'actual_loss_kobo',
        'risk_register_id',
        'loss_event_id',
        'created_by',
        // The 200038 duplicates (issue_title, issue_description,
        // issue_owner_id, target_resolution_date, actual_resolution_date,
        // source_reference, escalation_level) are deliberately absent — they
        // are no longer written. See docs/schema/canonical-columns.md.
        'root_cause',
        'impact_description',
        'recommended_action',
        'progress_percentage',
        'closure_justification',
        'evidence_of_resolution',
        'closure_requested_at',
        'closure_requested_by',
        'closure_rejection_reason',
        'closure_rejected_at',
        'closure_rejected_by',
        'closed_at',
        'closed_by',
        'status_changed_at',
        'status_changed_by',
        'updated_by',
    ];

    protected $casts = [
        'examination_date' => 'date',
        'cbn_response_deadline' => 'date',
        'management_response_due' => 'date',
        'remediation_due_date' => 'date',
        'actual_close_date' => 'date',
        // current_escalation_level is declared string(50) but has only ever
        // held an integer level. Casting here so comparisons behave; the
        // column retype is Migration B work (docs/schema/deprecations.md).
        'current_escalation_level' => 'integer',
        'closure_requested_at' => 'datetime',
        'closure_rejected_at' => 'datetime',
        'closed_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'regulatory_reportable' => 'boolean',
        'cbn_reportable' => 'boolean',
        'cbn_examination_finding' => 'boolean',
        'cbn_response_submitted' => 'boolean',
        'bofia_reportable' => 'boolean',
        'ndpa_reportable' => 'boolean',
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

    public function entity()
    {
        return $this->belongsTo(Entity::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function responsibleOwner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_owner_id');
    }

    public function riskRegister()
    {
        return $this->belongsTo(Risk::class, 'risk_register_id');
    }

    public function lossEvent()
    {
        return $this->belongsTo(LossEvent::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<IssueRemediationAction, $this> */
    public function remediationActions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(IssueRemediationAction::class);
    }

    /**
     * Whether the remediation is past its due date (migration Phase 4.4).
     *
     * THE SHOW PAGE HAS ALWAYS READ `$issue->is_overdue` AND IT HAS NEVER
     * EXISTED — no column, no accessor — so every use of it was null and the
     * overdue badge, the red due-date and the warning banner on an issue's own
     * detail page never rendered once. An issue could be six weeks late and its
     * page would look ordinary.
     *
     * Derived rather than stored, from the two facts that decide it: a settled
     * issue is not overdue however old, and one with no due date cannot be.
     * `issue_status` carries an OVERDUE value too, but that is set by a
     * scheduled command and lags reality by up to a day.
     */
    public function getIsOverdueAttribute(): bool
    {
        if (in_array($this->issue_status, ['CLOSED', 'CANCELLED'], true)) {
            return false;
        }

        return $this->remediation_due_date !== null
            && $this->remediation_due_date->startOfDay()->isPast();
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<IssueProgressUpdate, $this> */
    public function progressUpdates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(IssueProgressUpdate::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<IssueAttachment, $this> */
    public function attachments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(IssueAttachment::class);
    }

    public function escalationLog()
    {
        return $this->hasMany(IssueEscalationLog::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function issueOwner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->responsibleOwner();
    }

    public function owner()
    {
        return $this->responsibleOwner();
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<IssueEscalationLog, $this> */
    public function escalationLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->escalationLog();
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Risk, $this> */
    public function risk(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->riskRegister();
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors */
    /* ------------------------------------------------------------------ */

    protected function daysOpen(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->identified_date) {
                    return null;
                }

                $end = $this->closed_date ? Carbon::parse($this->closed_date) : Carbon::now();

                return Carbon::parse($this->identified_date)->diffInDays($end);
            },
        );
    }

    protected function isOverdue(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->due_date || $this->status === 'closed') {
                    return false;
                }

                return Carbon::now()->greaterThan(Carbon::parse($this->due_date));
            },
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Deprecated-column bridges (WP-01 TASK 1) — read-only, removed with */
    /*  the columns in Migration B. */
    /* ------------------------------------------------------------------ */

    protected function issueTitle(): Attribute
    {
        return Attribute::make(get: fn () => $this->title);
    }

    protected function issueDescription(): Attribute
    {
        return Attribute::make(get: fn () => $this->description);
    }

    protected function issueOwnerId(): Attribute
    {
        return Attribute::make(get: fn () => $this->responsible_owner_id);
    }

    protected function targetResolutionDate(): Attribute
    {
        return Attribute::make(get: fn () => $this->remediation_due_date);
    }

    protected function actualResolutionDate(): Attribute
    {
        return Attribute::make(get: fn () => $this->actual_close_date);
    }

    protected function sourceReference(): Attribute
    {
        return Attribute::make(get: fn () => $this->examination_ref);
    }

    protected function escalationLevel(): Attribute
    {
        return Attribute::make(get: fn () => (int) $this->current_escalation_level);
    }
}

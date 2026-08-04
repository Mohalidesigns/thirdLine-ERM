<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Issue extends Model
{
    use HasFactory, SoftDeletes;

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
        'issue_title',
        'issue_description',
        'issue_owner_id',
        'target_resolution_date',
        'actual_resolution_date',
        'root_cause',
        'impact_description',
        'recommended_action',
        'source_reference',
        'escalation_level',
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
        'examination_date'        => 'date',
        'cbn_response_deadline'   => 'date',
        'management_response_due' => 'date',
        'remediation_due_date'    => 'date',
        'actual_close_date'       => 'date',
        'target_resolution_date'  => 'date',
        'actual_resolution_date'  => 'date',
        'closure_requested_at'    => 'datetime',
        'closure_rejected_at'     => 'datetime',
        'closed_at'               => 'datetime',
        'status_changed_at'       => 'datetime',
        'regulatory_reportable'   => 'boolean',
        'cbn_reportable'          => 'boolean',
        'cbn_examination_finding' => 'boolean',
        'cbn_response_submitted'  => 'boolean',
        'bofia_reportable'        => 'boolean',
        'ndpa_reportable'         => 'boolean',
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
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function entity()
    {
        return $this->belongsTo(Entity::class);
    }

    public function businessUnit()
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function responsibleOwner()
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

    public function remediationActions()
    {
        return $this->hasMany(IssueRemediationAction::class);
    }

    public function progressUpdates()
    {
        return $this->hasMany(IssueProgressUpdate::class);
    }

    public function attachments()
    {
        return $this->hasMany(IssueAttachment::class);
    }

    public function escalationLog()
    {
        return $this->hasMany(IssueEscalationLog::class);
    }

    public function issueOwner()
    {
        return $this->responsibleOwner();
    }

    public function owner()
    {
        return $this->responsibleOwner();
    }

    public function escalationLogs()
    {
        return $this->escalationLog();
    }

    public function risk()
    {
        return $this->riskRegister();
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors                                                          */
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
}

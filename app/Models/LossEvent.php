<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class LossEvent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        // --- Original migration columns (actual DB columns) ---
        'organization_id',
        'event_reference',
        'title',
        'description',
        'initial_root_cause',
        'date_of_loss',
        'date_discovered',
        'date_reported',
        'business_unit_id',
        'department',
        'branch_name',
        'responsible_officer_id',
        'basel_l1_category',
        'basel_l2_category',
        'basel_l3_detail',
        'cbn_risk_category',
        'cbn_orms_event_type',
        'cbn_product_line',
        'gross_loss_amount_kobo',
        'insurance_recovery_kobo',
        'other_recovery_kobo',
        'pending_recovery_kobo',
        'actual_recovery_kobo',
        'provision_amount_kobo',
        'cost_centre',
        'gl_account_code',
        'loss_category',
        'insurance_covered',
        'insurance_policy_ref',
        'insured_amount_kobo',
        'insurance_provider',
        'insurance_claim_status',
        'event_severity',
        'is_near_miss',
        'current_status',
        'cbn_reportable',
        'cbn_reporting_deadline',
        'cbn_notification_sent',
        'cbn_notification_date',
        'cbn_notification_ref',
        'nfiu_reportable',
        'nfiu_report_type',
        'nfiu_str_reference',
        'nfiu_report_filed',
        'bofia_reportable',
        'ndic_reportable',
        'law_enforcement_notified',
        'police_report_ref',
        'efcc_reported',
        'customer_impact_rating',
        'customers_affected_count',
        'reputational_impact_rating',
        'operational_disruption_hrs',
        'indirect_cost_kobo',
        'risk_register_id',
        'assigned_to_id',
        'current_approval_stage',
        'created_by',
        // --- Alignment migration columns (used by controllers) ---
        'event_title',
        'event_description',
        'status',
        'gross_loss_amount',
        'recovery_amount',
        'insurance_recovery',
        'net_loss_amount',
        'event_type',
        'severity',
        'basel_event_type',
        'cbn_loss_category',
        'currency',
        'is_regulatory_reportable',
        'regulatory_body',
        'reporting_deadline',
        'root_cause_summary',
        'corrective_action_summary',
        'reported_by',
        'approved_by',
        'approved_at',
        'status_changed_at',
        'status_changed_by',
        'updated_by',
    ];

    protected $casts = [
        // Original columns
        'date_of_loss'             => 'date',
        'date_discovered'          => 'date',
        'date_reported'            => 'date',
        'insurance_covered'        => 'boolean',
        'cbn_reportable'           => 'boolean',
        'cbn_reporting_deadline'   => 'date',
        'cbn_notification_sent'    => 'boolean',
        'cbn_notification_date'    => 'date',
        'nfiu_reportable'          => 'boolean',
        'nfiu_report_filed'        => 'boolean',
        'bofia_reportable'         => 'boolean',
        'ndic_reportable'          => 'boolean',
        'law_enforcement_notified' => 'boolean',
        'efcc_reported'            => 'boolean',
        'is_near_miss'             => 'boolean',
        // Alignment columns
        'reporting_deadline'       => 'date',
        'is_regulatory_reportable' => 'boolean',
        'approved_at'              => 'datetime',
        'status_changed_at'        => 'datetime',
        'gross_loss_amount'        => 'decimal:2',
        'recovery_amount'          => 'decimal:2',
        'insurance_recovery'       => 'decimal:2',
        'net_loss_amount'          => 'decimal:2',
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

    public function responsibleOfficer()
    {
        return $this->belongsTo(User::class, 'responsible_officer_id');
    }

    public function riskRegister()
    {
        return $this->belongsTo(Risk::class, 'risk_register_id');
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function failedControls()
    {
        return $this->hasMany(LossEventControl::class);
    }

    public function attachments()
    {
        return $this->hasMany(LossEventAttachment::class);
    }

    public function approvals()
    {
        return $this->hasMany(LossEventApproval::class);
    }

    public function rca()
    {
        return $this->hasOne(LossEventRca::class);
    }

    public function reporter()
    {
        return $this->responsibleOfficer();
    }

    public function risk()
    {
        return $this->riskRegister();
    }

    public function controls()
    {
        return $this->failedControls();
    }

    public function rootCauseAnalysis()
    {
        return $this->rca();
    }

    public function convertedNearMisses()
    {
        return $this->hasMany(NearMiss::class, 'converted_loss_event_id');
    }

    public function issuesLinked()
    {
        return $this->hasMany(Issue::class, 'loss_event_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors                                                          */
    /* ------------------------------------------------------------------ */

    // Bridge legacy column names used by some views.
    protected function reference(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->event_reference,
        );
    }

    protected function netLossAmountKobo(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ?? (
                (int) $this->gross_loss_amount_kobo
                - (int) $this->insurance_recovery_kobo
                - (int) $this->other_recovery_kobo
            ),
        );
    }

    protected function daysOpen(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->event_date) {
                    return null;
                }

                $end = $this->closed_at ? Carbon::parse($this->closed_at) : Carbon::now();

                return Carbon::parse($this->event_date)->diffInDays($end);
            },
        );
    }
}

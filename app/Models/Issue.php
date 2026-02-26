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
        'issue_code',
        'title',
        'description',
        'source',
        'source_reference',
        'business_unit_id',
        'risk_category',
        'severity',
        'priority',
        'responsible_owner_id',
        'risk_register_id',
        'loss_event_id',
        'identified_date',
        'due_date',
        'original_due_date',
        'closed_date',
        'status',
        'progress_pct',
        'is_regulatory',
        'regulatory_reference',
        'regulator_deadline',
        'escalated',
        'escalation_level',
        'times_extended',
        'created_by',
    ];

    protected $casts = [
        'identified_date'    => 'date',
        'due_date'           => 'date',
        'original_due_date'  => 'date',
        'closed_date'        => 'date',
        'regulator_deadline' => 'date',
        'is_regulatory'      => 'boolean',
        'escalated'          => 'boolean',
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

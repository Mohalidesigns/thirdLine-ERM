<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasObjectIdentity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class NearMiss extends Model
{
    use BelongsToOrganization, HasFactory, HasObjectIdentity, SoftDeletes;

    protected $table = 'near_misses';

    /**
     * From LossEventController's inline `in:` rule (migration Phase 4.3).
     * These are NOT LossEvent::SEVERITIES — a near miss is graded low/medium/
     * high/critical and a loss event insignificant..catastrophic, which is a
     * pre-existing divergence rather than something this port introduced.
     *
     * @var list<string>
     */
    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    protected $fillable = [
        'organization_id',
        'reference',
        'event_reference',
        'title',
        'description',
        'date_occurred',
        'date_reported',
        'business_unit_id',
        'potential_loss_kobo',
        'severity',
        'control_gap_identified',
        'control_gap_description',
        'linked_control_id',
        'status',
        'converted_loss_event_id',
        'investigator_id',
        'investigation_deadline',
        'risk_register_id',
        'reported_by',
    ];

    protected $casts = [
        'date_occurred' => 'date',
        'date_reported' => 'date',
        'investigation_deadline' => 'date',
        'control_gap_identified' => 'boolean',
    ];

    /**
     * Potential loss in naira (stored in kobo).
     */
    public function getPotentialLossAttribute(): float
    {
        return ($this->potential_loss_kobo ?? 0) / 100;
    }

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

    public function businessUnit()
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function linkedControl()
    {
        return $this->belongsTo(Control::class, 'linked_control_id');
    }

    public function convertedLossEvent()
    {
        return $this->belongsTo(LossEvent::class, 'converted_loss_event_id');
    }

    public function investigator()
    {
        return $this->belongsTo(User::class, 'investigator_id');
    }

    public function riskRegister()
    {
        return $this->belongsTo(Risk::class, 'risk_register_id');
    }

    public function reportedBy()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}

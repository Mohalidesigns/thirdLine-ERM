<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RiskAuditTrail extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'risk_audit_trail';

    protected $fillable = [
        'organization_id',
        'entity_type',
        'entity_id',
        'action_type',
        'field_changed',
        'old_value',
        'new_value',
        'changed_by',
        'changed_at',
        'ip_address',
        'change_reason',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function changedByUser()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Polymorphic relationship to the audited entity.
     * Maps entity_type string to the corresponding model class.
     */
    public function auditable()
    {
        $morphMap = [
            'Risk' => \App\Models\Risk::class,
            'Control' => \App\Models\Control::class,
            'RiskAssessment' => \App\Models\RiskAssessment::class,
            'LossEvent' => \App\Models\LossEvent::class,
            'Issue' => \App\Models\Issue::class,
            'TreatmentPlan' => \App\Models\TreatmentPlan::class,
            'KeyRiskIndicator' => \App\Models\KeyRiskIndicator::class,
            'RiskAppetite' => \App\Models\RiskAppetite::class,
        ];

        $class = $morphMap[$this->entity_type] ?? null;
        if ($class) {
            return $this->belongsTo($class, 'entity_id');
        }
        return $this->belongsTo(Risk::class, 'entity_id'); // fallback
    }
}

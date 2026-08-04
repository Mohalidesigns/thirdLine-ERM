<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DomainEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'event_type',
        'source_module',
        'source_id',
        'payload',
        'status',
        'retry_count',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'processed_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Polymorphic relationship to the aggregate root.
     * Maps aggregate_type string to the corresponding model class.
     */
    public function aggregate()
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

        $class = $morphMap[$this->aggregate_type] ?? null;
        if ($class) {
            return $this->belongsTo($class, 'aggregate_id');
        }
        return $this->belongsTo(Risk::class, 'aggregate_id'); // fallback
    }
}

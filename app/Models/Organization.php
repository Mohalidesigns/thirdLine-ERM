<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'short_name',
        'cbn_institution_code',
        'ndic_member_number',
        'rc_number',
        'tin',
        'institution_type',
        'sector',
        'settings',
        'is_active',
    ];

    protected $casts = [
        'settings'  => 'array',
        'is_active' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Boot                                                               */
    /* ------------------------------------------------------------------ */

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

    public function businessUnits()
    {
        return $this->hasMany(BusinessUnit::class);
    }

    public function riskCategories()
    {
        return $this->hasMany(RiskCategory::class);
    }

    public function risks()
    {
        return $this->hasMany(Risk::class);
    }

    public function controls()
    {
        return $this->hasMany(Control::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function treatmentPlans()
    {
        return $this->hasMany(TreatmentPlan::class);
    }

    public function riskAssessments()
    {
        return $this->hasMany(RiskAssessment::class);
    }

    public function lossEvents()
    {
        return $this->hasMany(LossEvent::class);
    }

    public function issues()
    {
        return $this->hasMany(Issue::class);
    }

    public function keyRiskIndicators()
    {
        return $this->hasMany(KeyRiskIndicator::class);
    }

    public function quantificationScenarios()
    {
        return $this->hasMany(QuantificationScenario::class);
    }

    public function riskAppetites()
    {
        return $this->hasMany(RiskAppetite::class);
    }

    public function simulationRuns()
    {
        return $this->hasMany(SimulationRun::class);
    }

    public function nearMisses()
    {
        return $this->hasMany(NearMiss::class);
    }

    public function businessProcesses()
    {
        return $this->hasMany(BusinessProcess::class);
    }
}

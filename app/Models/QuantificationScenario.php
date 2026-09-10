<?php

namespace App\Models;

use App\Models\Concerns\HasObjectIdentity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

class QuantificationScenario extends Model
{
    use BelongsToOrganization, HasFactory, HasObjectIdentity, SoftDeletes;

    /**
     * The values `quantification_scenarios.scenario_type` takes.
     *
     * The column is `string(50)` NOT NULL WITH NO DEFAULT, and until Phase 5.2
     * neither the create form nor the library import set it — so every attempt
     * to create a scenario through the interface, by either route, ended in a
     * NOT NULL violation and a 500. QuantificationSeeder has used
     * `single_event` since it was written, and QuantificationController's
     * stress report finds stress scenarios with
     * `LOWER(scenario_type) = 'stress'`; those two plus DemoDataSeeder's
     * `single_risk` are the vocabulary that is actually in the data.
     *
     * @var list<string>
     */
    public const TYPES = ['single_event', 'single_risk', 'stress', 'aggregate'];

    /**
     * What a scenario is when nobody said.
     *
     * A library template and a hand-entered scenario both describe one loss
     * event with a frequency and a severity, which is what `single_event`
     * means and what the seeder has always written.
     */
    public const DEFAULT_TYPE = 'single_event';

    protected $fillable = [
        'organization_id',
        'scenario_reference',
        'risk_register_id',
        'scenario_type',
        'name',
        'description',
        'cbn_risk_category',
        'basel_l1_category',
        'frequency_distribution',
        'frequency_lambda',
        'frequency_n',
        'frequency_p',
        'severity_distribution',
        'severity_mu',
        'severity_sigma',
        'severity_location',
        'severity_scale',
        'severity_shape',
        'severity_min_kobo',
        'severity_max_kobo',
        'expected_annual_frequency',
        'expected_loss_per_event_kobo',
        'expected_annual_loss_kobo',
        'cbn_stress_scenario',
        'stress_multiplier_frequency',
        'stress_multiplier_severity',
        'status',
        'data_quality_score',
        'calibrated_by',
        'calibration_date',
        'approved_by',
        'approval_date',
        'created_by',
    ];

    protected $casts = [
        'frequency_lambda' => 'decimal:4',
        'severity_mu' => 'decimal:6',
        'severity_sigma' => 'decimal:6',
        'calibration_date' => 'date',
        'approval_date' => 'date',
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

    public function riskRegister()
    {
        return $this->belongsTo(Risk::class, 'risk_register_id');
    }

    public function calibratedBy()
    {
        return $this->belongsTo(User::class, 'calibrated_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------------ */
    /*  View-compatible accessors (blade views expect these properties) */
    /* ------------------------------------------------------------------ */

    public function getRiskCategoryAttribute()
    {
        return $this->cbn_risk_category;
    }

    public function getDistributionTypeAttribute()
    {
        return $this->severity_distribution ?? $this->frequency_distribution ?? 'lognormal';
    }

    public function getMeanAttribute()
    {
        // Expected loss per event in Naira (kobo / 100)
        return $this->expected_loss_per_event_kobo
            ? round($this->expected_loss_per_event_kobo / 100, 2)
            : ($this->severity_mu ? round(exp($this->severity_mu) / 100, 2) : 0);
    }

    public function getStdDevAttribute()
    {
        if ($this->severity_sigma && $this->severity_mu) {
            // Standard deviation of lognormal: sqrt((exp(sigma^2)-1) * exp(2*mu + sigma^2))
            $mu = (float) $this->severity_mu;
            $sigma = (float) $this->severity_sigma;

            return round(sqrt((exp($sigma ** 2) - 1) * exp(2 * $mu + $sigma ** 2)) / 100, 2);
        }

        return 0;
    }

    public function getFrequencyPerYearAttribute()
    {
        return $this->expected_annual_frequency ?? $this->frequency_lambda ?? 0;
    }

    public function getMinLossAttribute()
    {
        return $this->severity_min_kobo ? round($this->severity_min_kobo / 100, 2) : 0;
    }

    public function getMaxLossAttribute()
    {
        return $this->severity_max_kobo ? round($this->severity_max_kobo / 100, 2) : 0;
    }

    public function getSourceAttribute()
    {
        return $this->cbn_stress_scenario ?? 'CBN Data';
    }

    public function getExpectedAnnualLossAttribute()
    {
        if ($this->expected_annual_loss_kobo) {
            return round($this->expected_annual_loss_kobo / 100, 2);
        }

        return round(($this->frequency_per_year ?? 0) * ($this->mean ?? 0), 2);
    }
}

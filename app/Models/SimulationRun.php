<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SimulationRun extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'simulation_reference',
        'status',
        'iterations',
        'random_seed',
        'horizon_years',
        'correlation_method',
        'confidence_levels',
        'scenario_ids',
        'stress_config',
        'icaap_period',
        'started_at',
        'completed_at',
        'runtime_seconds',
        'error_message',
        'initiated_by',
    ];

    protected $casts = [
        'confidence_levels' => 'array',
        'scenario_ids' => 'array',
        'stress_config' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
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

    public function initiatedBy()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function results()
    {
        return $this->hasMany(SimulationResult::class);
    }

    /* ------------------------------------------------------------------ */
    /*  View-compatible accessors */
    /* ------------------------------------------------------------------ */

    public function getNameAttribute()
    {
        return $this->simulation_reference;
    }

    public function getScenarioCountAttribute()
    {
        return is_array($this->scenario_ids) ? count($this->scenario_ids) : 0;
    }

    /**
     * Get aggregate result (the result with result_type='aggregate')
     */
    public function getAggregateResultAttribute()
    {
        return $this->results()->where('result_type', 'aggregate')->first()
            ?? $this->results()->first();
    }

    public function getVar95Attribute()
    {
        $agg = $this->aggregate_result;

        return $agg ? round(($agg->var_95_kobo ?? 0) / 100, 2) : 0;
    }

    public function getVar99Attribute()
    {
        $agg = $this->aggregate_result;

        return $agg ? round(($agg->var_99_kobo ?? 0) / 100, 2) : 0;
    }

    public function getVar995Attribute()
    {
        $agg = $this->aggregate_result;

        return $agg ? round(($agg->var_99_9_kobo ?? 0) / 100, 2) : 0;
    }

    public function getExpectedLossAttribute()
    {
        $agg = $this->aggregate_result;

        return $agg ? round(($agg->expected_annual_loss_kobo ?? 0) / 100, 2) : 0;
    }

    /**
     * Expected shortfall (CVaR) at 95%, in naira.
     *
     * This used to read `return round(($agg->var_99_kobo ?? 0) / 100, 2)` under
     * a comment claiming "ES approximated as average of losses above VaR 95".
     * Those are two different statistics: VaR(99) is a single order statistic
     * of the loss sample, ES(95) is the mean of the whole tail beyond VaR(95).
     * Neither the label nor the comment described what the number was, and it
     * was rendered as a headline KPI on the quantification dashboard and on the
     * results page. It is now read from the value MonteCarloService actually
     * computes from the sorted loss vector.
     *
     * Returns null — not 0 — when no ES was stored, which is the case for every
     * run completed before the es_*_kobo columns existed. A zero would be read
     * off a dashboard as "this portfolio has no tail loss"; a null lets the
     * view say the figure was never computed. Callers that still coalesce with
     * `?? 0` will need updating before the empty state is honest on screen.
     */
    public function getExpectedShortfallAttribute()
    {
        $agg = $this->aggregate_result;

        if (! $agg || $agg->es_95_kobo === null) {
            return null;
        }

        return round($agg->es_95_kobo / 100, 2);
    }

    public function getMaxLossAttribute()
    {
        $agg = $this->aggregate_result;
        $dist = $agg->percentile_distribution ?? [];

        return isset($dist['p99.9']) ? round($dist['p99.9'] / 100, 2) : $this->var_995;
    }

    public function getPercentilesAttribute()
    {
        $agg = $this->aggregate_result;
        $dist = $agg->percentile_distribution ?? [];
        $formatted = [];
        $labels = ['p5' => '5%', 'p10' => '10%', 'p25' => '25%', 'p50' => '50%', 'p75' => '75%', 'p90' => '90%', 'p95' => '95%', 'p99' => '99%', 'p99.5' => '99.5%', 'p99.9' => '99.9%'];
        foreach ($labels as $key => $label) {
            if (isset($dist[$key])) {
                $formatted[$label] = round($dist[$key] / 100, 2);
            }
        }

        return $formatted;
    }

    public function getScenarioContributionsAttribute()
    {
        $scenarioResults = $this->results()->where('result_type', 'scenario')->with('scenario')->get();
        $agg = $this->aggregate_result;
        $totalExpected = $agg ? ($agg->expected_annual_loss_kobo ?? 1) : 1;

        return $scenarioResults->map(function ($sr) use ($totalExpected) {
            return (object) [
                'scenario_name' => $sr->scenario->name ?? 'Unknown',
                'category' => $sr->scenario->cbn_risk_category ?? '-',
                'expected_loss' => round(($sr->expected_annual_loss_kobo ?? 0) / 100, 2),
                'contribution_pct' => $totalExpected > 0 ? round((($sr->expected_annual_loss_kobo ?? 0) / $totalExpected) * 100, 1) : 0,
                'var_95' => round(($sr->var_95_kobo ?? 0) / 100, 2),
            ];
        });
    }
}

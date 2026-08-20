<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SimulationResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'simulation_run_id',
        'scenario_id',
        'result_type',
        'expected_annual_loss_kobo',
        'var_90_kobo',
        'var_95_kobo',
        'var_99_kobo',
        'var_99_9_kobo',
        // Expected shortfall (CVaR). Added because the model accessor that
        // published "Expected Shortfall" was returning var_99_kobo — a
        // quantile, not a tail mean. There is nowhere else to put it: the loss
        // vector is discarded at the end of MonteCarloService::runSimulation().
        'es_95_kobo',
        'es_99_kobo',
        'std_deviation_kobo',
        'risk_contributions',
        'percentile_distribution',
    ];

    protected $casts = [
        'risk_contributions' => 'array',
        'percentile_distribution' => 'array',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function simulationRun()
    {
        return $this->belongsTo(SimulationRun::class);
    }

    public function scenario()
    {
        return $this->belongsTo(QuantificationScenario::class, 'scenario_id');
    }
}

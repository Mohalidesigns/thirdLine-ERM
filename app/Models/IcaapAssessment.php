<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

class IcaapAssessment extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'period',
        'status',
        'cet1_capital_kobo',
        'tier1_capital_kobo',
        'tier2_capital_kobo',
        'total_qualifying_capital_kobo',
        'total_rwa_kobo',
        'car_actual',
        'cbn_minimum_car',
        'conservation_buffer',
        'pillar2a_credit_kobo',
        'pillar2a_market_kobo',
        'pillar2a_operational_kobo',
        'pillar2a_other_kobo',
        'pillar2b_stress_buffer_kobo',
        'base_simulation_id',
        'stress_simulation_id',
        'prepared_by',
        'reviewed_by',
        'approved_by_board',
        'board_approval_date',
        'cbn_submission_date',
        'cbn_submission_ref',
    ];

    protected $casts = [
        'car_actual' => 'decimal:4',
        'cbn_minimum_car' => 'decimal:4',
        'conservation_buffer' => 'decimal:4',
        'board_approval_date' => 'date',
        'cbn_submission_date' => 'date',
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

    public function baseSimulation()
    {
        return $this->belongsTo(SimulationRun::class, 'base_simulation_id');
    }

    public function stressSimulation()
    {
        return $this->belongsTo(SimulationRun::class, 'stress_simulation_id');
    }

    public function preparedBy()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedByBoard()
    {
        return $this->belongsTo(User::class, 'approved_by_board');
    }
}

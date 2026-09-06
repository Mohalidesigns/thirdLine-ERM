<?php

namespace App\Models\Rcsa;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rating band and what it obliges: workbook columns M and R (the level), S
 * (the treatment) and T (the appetite statement).
 *
 * The bounds are INCLUSIVE and decimal. A residual score is fractional — an
 * inherent 25 with a Mostly Achieved control is 6.25 — and defect D3 of the
 * plan is explicit that banding happens on the raw value and never on a
 * rounded one, because rounding 4.5 up to 5 moves a risk from MEDIUM to MEDIUM
 * harmlessly but rounding 2.4 down to 2 moves it from LOW to VERY LOW and
 * changes what the bank is obliged to do about it.
 */
class RcsaRiskBand extends Model
{
    public const TREAT = 'treat';

    public const MITIGATE = 'mitigate';

    public const ACCEPT = 'accept';

    protected $table = 'rcsa_risk_bands';

    protected $fillable = [
        'methodology_id',
        'level',
        'label',
        'min_score',
        'max_score',
        'colour',
        'treatment',
        'appetite_status',
        'sort_order',
    ];

    protected $casts = [
        'min_score' => 'float',
        'max_score' => 'float',
        'sort_order' => 'integer',
    ];

    /** @return BelongsTo<RcsaMethodology, $this> */
    public function methodology(): BelongsTo
    {
        return $this->belongsTo(RcsaMethodology::class, 'methodology_id');
    }

    /** Whether $score falls inside this band, both bounds inclusive. */
    public function contains(float $score): bool
    {
        return $score >= $this->min_score && $score <= $this->max_score;
    }
}

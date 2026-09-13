<?php

namespace App\Models\Rcsa;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The descriptor for one (impact level × dimension) pair — thirty rows per
 * methodology, from the workbook's `Risk Matrix` sheet.
 *
 * These exist as rows rather than as a spreadsheet the assessor is asked to
 * consult, because the highest applicable DIMENSION drives the impact rating.
 * An assessor choosing "High" on financial grounds needs to see that High also
 * means a regulatory penalty and a serious injury; without that, the five-point
 * scale collapses into a naira scale and every non-financial risk is
 * under-rated.
 */
class RcsaImpactCriterion extends Model
{
    protected $table = 'rcsa_impact_criteria';

    protected $fillable = [
        'methodology_id',
        'impact_value',
        'dimension',
        'descriptor',
        'sort_order',
    ];

    protected $casts = [
        'impact_value' => 'integer',
        'sort_order' => 'integer',
    ];

    /** @return BelongsTo<RcsaMethodology, $this> */
    public function methodology(): BelongsTo
    {
        return $this->belongsTo(RcsaMethodology::class, 'methodology_id');
    }
}

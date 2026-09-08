<?php

namespace App\Models\Rcsa;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One risk category's appetite ceiling — §14 Q4's answer, when the bank gives
 * one.
 *
 * Only consulted when the methodology's `appetite_mode` is `per_category`. A
 * row that exists under `single` mode is inert rather than an error: a tenant
 * configuring per-category appetite will fill the table first and flip the mode
 * once, and refusing the rows until the mode changed would force them to do it
 * in an order nobody would guess.
 *
 * NO ROW MEANS THE HOUSE CEILING APPLIES, not that the category is unlimited.
 * Falling back to `appetite_ceiling_level` is the only safe reading: a
 * half-configured methodology must not quietly place a category outside every
 * obligation, which is what "no row = no ceiling" would do. `RcsaMethodology`
 * reports which categories are relying on the fallback so a settings screen can
 * say so out loud.
 */
class RcsaCategoryAppetite extends Model
{
    protected $table = 'rcsa_category_appetites';

    protected $fillable = [
        'methodology_id',
        'risk_category',
        'ceiling_level',
        'note',
    ];

    /** @return BelongsTo<RcsaMethodology, $this> */
    public function methodology(): BelongsTo
    {
        return $this->belongsTo(RcsaMethodology::class, 'methodology_id');
    }
}

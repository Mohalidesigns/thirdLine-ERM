<?php

namespace App\Models\Rcsa;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rung of a methodology's likelihood, impact or control-effectiveness
 * scale. Workbook columns J, K and O.
 *
 * NOT TENANT-SCOPED, deliberately: it has no organization_id. A scale item is
 * meaningless apart from its methodology and is only ever reached through one,
 * so the tenancy boundary is enforced once, on RcsaMethodology, rather than
 * repeated on four child tables where it could silently disagree.
 *
 * `value` AND `modifier` ARE DIFFERENT NUMBERS AND ONLY ONE OF THEM IS ARITHMETIC.
 * For likelihood and impact, `value` is the 1-5 rating and is multiplied.
 * For control effectiveness, `value` is an ORDERING in which 1 is the BEST
 * rating (Fully Achieved), and multiplying by it would invert the whole model;
 * the number that participates in the calculation is `modifier` — 100, 75, 50
 * or 25 — which is null on the other two types.
 */
class RcsaScaleItem extends Model
{
    public const TYPE_LIKELIHOOD = 'likelihood';

    public const TYPE_IMPACT = 'impact';

    public const TYPE_CONTROL_EFFECTIVENESS = 'control_effectiveness';

    protected $table = 'rcsa_scale_items';

    protected $fillable = [
        'methodology_id',
        'type',
        'label',
        'value',
        'modifier',
        'percent_band',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'value' => 'integer',
        'modifier' => 'integer',
        'sort_order' => 'integer',
    ];

    /** @return BelongsTo<RcsaMethodology, $this> */
    public function methodology(): BelongsTo
    {
        return $this->belongsTo(RcsaMethodology::class, 'methodology_id');
    }
}

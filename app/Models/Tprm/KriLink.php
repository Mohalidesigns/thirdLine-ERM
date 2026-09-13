<?php

namespace App\Models\Tprm;

use App\Models\BusinessUnit;
use App\Models\KeyRiskIndicator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Which KRI in the collection module a TPRM metric publishes into —
 * FR-RPT-10.
 *
 * IT EXISTS SO THE LINK SURVIVES A RENAME. A tenant that renames "Critical
 * vendors assessed within cadence" to match its own board pack vocabulary must
 * not thereby detach it from the metric that feeds it; the join is on
 * `metric_code`, which is ours, rather than on the KRI's name or code, which
 * are theirs.
 *
 * ONE METRIC MAY PUBLISH INTO SEVERAL KRIs. The unique key is on the triple,
 * not on the metric — a group with a KRI per business unit is a legitimate
 * shape, and `business_unit_id` narrows the computation for that row.
 */
class KriLink extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_kri_links';

    protected $fillable = [
        'organization_id', 'metric_code', 'key_risk_indicator_id',
        'business_unit_id', 'tier_filter',
    ];

    /** Written by the publisher when it records a reading. */
    public const GUARDED_STATE = ['last_published_at'];

    protected $casts = [
        'last_published_at' => 'datetime',
    ];

    /** @return BelongsTo<KeyRiskIndicator, $this> */
    public function keyRiskIndicator(): BelongsTo
    {
        return $this->belongsTo(KeyRiskIndicator::class, 'key_risk_indicator_id');
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'business_unit_id');
    }
}

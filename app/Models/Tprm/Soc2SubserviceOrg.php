<?php

namespace App\Models\Tprm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A subservice organisation named in a SOC 2 report — FR-EVD-06.
 *
 * `method` is the column that decides whether this row means anything.
 *
 *  - `inclusive`: the report's scope covers this organisation's controls. The
 *    assurance is real and there is no gap.
 *  - `carve_out`: the service auditor examined NOTHING this organisation does,
 *    while the vendor's own controls depend on it. That is an assurance gap
 *    with a name attached, and it becomes a proposed nth-party edge.
 *
 * A carve-out naming a provider the vendor never declared to us is stronger
 * still: two rows then prove a broken disclosure obligation.
 */
class Soc2SubserviceOrg extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_soc2_subservice_orgs';

    public const METHOD_CARVE_OUT = 'carve_out';

    public const METHOD_INCLUSIVE = 'inclusive';

    protected $fillable = [
        'organization_id', 'soc2_id', 'name', 'services', 'method',
        'proposed_nth_party_edge_id',
    ];

    /** @return BelongsTo<Soc2Detail, $this> */
    public function soc2(): BelongsTo
    {
        return $this->belongsTo(Soc2Detail::class, 'soc2_id');
    }

    public function isCarvedOut(): bool
    {
        return $this->method === self::METHOD_CARVE_OUT;
    }
}

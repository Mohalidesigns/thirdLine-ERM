<?php

namespace App\Models\Tprm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One Section 4 exception — a control the service auditor tested and found not
 * operating as described.
 *
 * `population` and `exceptions_noted` are kept as the report states them ("40
 * samples", "2 of 40") rather than parsed into numbers. A ratio is what a
 * reviewer needs to judge severity, and reports state it in prose that varies
 * enough that parsing it would be inventing precision.
 */
class Soc2Exception extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_soc2_exceptions';

    protected $fillable = [
        'organization_id', 'soc2_id', 'control_reference', 'description',
        'population', 'exceptions_noted', 'management_response',
        'severity_assessment', 'linked_finding_id',
    ];

    /** @return BelongsTo<Soc2Detail, $this> */
    public function soc2(): BelongsTo
    {
        return $this->belongsTo(Soc2Detail::class, 'soc2_id');
    }

    /** Whether a finding has already been raised from this exception. */
    public function isLinked(): bool
    {
        return $this->linked_finding_id !== null;
    }
}

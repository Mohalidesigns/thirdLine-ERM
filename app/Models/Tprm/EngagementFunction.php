<?php

namespace App\Models\Tprm;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Which business functions an engagement supports, and how heavily.
 *
 * A model rather than a bare pivot because the concentration analyser reads it
 * as a first-class object: the single-points-of-failure table in TRD §7.8 is
 * "how many CRITICAL functions depend on this provider group", which is a
 * query over these rows, not over engagements.
 */
class EngagementFunction extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_engagement_functions';

    /** @var list<string> */
    public const DEPENDENCY_LEVELS = ['primary', 'supporting'];

    /** @var list<string> */
    public const RELIANCE_LEVELS = ['low', 'medium', 'high', 'full'];

    protected $fillable = [
        'organization_id', 'engagement_id', 'business_function_id',
        'dependency_level', 'reliance_level', 'created_by',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    /** @return BelongsTo<BusinessFunction, $this> */
    public function businessFunction(): BelongsTo
    {
        return $this->belongsTo(BusinessFunction::class, 'business_function_id');
    }
}

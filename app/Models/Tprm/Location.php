<?php

namespace App\Models\Tprm;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Where a third party operates from.
 *
 * `is_data_processing_location` rather than the entity's headquarters country
 * is what the NDPA §41 cross-border analysis reads: a vendor incorporated in
 * Lagos processing in Frankfurt is a cross-border transfer, and a vendor
 * incorporated in Delaware processing in Lagos is not.
 */
class Location extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'tp_locations';

    /** @var list<string> */
    public const ROLES = ['hq', 'service_delivery', 'data_centre', 'dr_site', 'call_centre', 'office'];

    protected $fillable = [
        'organization_id', 'third_party_id', 'role', 'address_line1', 'address_line2',
        'city', 'state', 'country', 'is_data_processing_location', 'datacentre_tier',
        'certifications', 'latitude', 'longitude', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_data_processing_location' => 'boolean',
        'certifications' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ThirdParty, $this> */
    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }
}

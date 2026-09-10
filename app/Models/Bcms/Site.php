<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\Bcms\Concerns\ScopedToOrgHierarchy;
use App\Models\BusinessUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A facility: a branch, a head office, a data centre, a recovery site.
 *
 * A site is NOT an org node. A branch is both a department and a building and
 * carries both ids — a fire drill is about the building, a call tree is about
 * the department (ADR 0006). One of the four seam registers of ADR 0001:
 * BCMS owns it only because no upstream module does.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property ?int $business_unit_id
 * @property string $code
 * @property string $name
 * @property string $site_type
 * @property ?string $address
 * @property ?string $city
 * @property ?string $state
 * @property string $country
 * @property ?string $latitude
 * @property ?string $longitude
 * @property ?int $headcount
 * @property bool $is_recovery_site
 * @property ?int $recovery_site_id
 * @property ?string $external_ref
 * @property bool $is_active
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Site extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, ScopedToOrgHierarchy, SoftDeletes;

    protected $table = 'bcms_sites';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'business_unit_id', 'code', 'name', 'site_type', 'address', 'city',
        'state', 'country', 'latitude', 'longitude', 'headcount', 'is_recovery_site',
        'recovery_site_id', 'external_ref', 'is_active', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'business_unit_id' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'headcount' => 'integer',
            'is_recovery_site' => 'boolean',
            'recovery_site_id' => 'integer',
            'is_active' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'business_unit_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function recoverySite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'recovery_site_id');
    }

    /** @return HasMany<Contact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'site_id');
    }

    /** @return HasMany<Equipment, $this> */
    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class, 'site_id');
    }
}

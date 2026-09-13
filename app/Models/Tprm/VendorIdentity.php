<?php

namespace App\Models\Tprm;

use App\Models\Tprm\Concerns\HasTprmUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One company, in the world — the anchor the reusable trust profile hangs
 * from (FR-PRT-04).
 *
 * NO `BelongsToOrganization`, AND THAT IS THE WHOLE POINT. This is the row
 * that means "Cloudspan Nigeria Limited", not "Lagos Union Bank's supplier
 * record for Cloudspan". Every tenant keeps its own `tp_third_parties` row
 * with its own name, category, owner and tier; they may all point at this.
 *
 * BECAUSE IT SITS OUTSIDE THE TENANT SCOPE, NOTHING MAY READ THROUGH IT
 * CASUALLY. `thirdParties()` deliberately returns rows across every tenant and
 * exists for the vendor's own portal screens — an internal screen that called
 * it would be looking at other banks' registers. The internal side reaches a
 * profile only through an approved `TrustProfileShare`, never through here.
 */
class VendorIdentity extends Model
{
    use HasTprmUuid;

    protected $table = 'tp_vendor_identities';

    protected $fillable = [
        'canonical_name', 'registration_number', 'country_of_incorporation', 'lei', 'primary_domain',
    ];

    /** @return HasOne<TrustProfile, $this> */
    public function trustProfile(): HasOne
    {
        return $this->hasOne(TrustProfile::class, 'vendor_identity_id');
    }

    /**
     * Every tenant's supplier record for this company.
     *
     * CROSSES TENANTS BY CONSTRUCTION. Only the vendor's own portal and the
     * identity-linking service have any business calling it; see the class
     * comment.
     *
     * @return HasMany<ThirdParty, $this>
     */
    public function thirdParties(): HasMany
    {
        return $this->hasMany(ThirdParty::class, 'vendor_identity_id');
    }

    /**
     * How many of our tenants this vendor serves.
     *
     * Shown to the VENDOR, never to a client. "You serve 4 organisations on
     * this platform" is a useful nudge toward completing one profile instead
     * of four questionnaires; the same number shown to a bank is a competitor
     * list.
     */
    public function tenantCount(): int
    {
        return (int) $this->thirdParties()
            ->withoutGlobalScopes()
            ->distinct()
            ->count('organization_id');
    }
}

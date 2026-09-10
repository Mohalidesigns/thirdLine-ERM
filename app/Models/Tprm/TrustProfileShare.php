<?php

namespace App\Models\Tprm;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tprm\TrustProfileSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One client's permission to read one vendor's trust profile — FR-PRT-04.
 *
 * THIS TABLE IS WHERE TENANCY LIVES for the whole trust-profile feature. The
 * profile and the identity behind it sit outside the tenant scope on purpose;
 * this row is the only thing that lets a given organisation see any of it, and
 * it carries `BelongsToOrganization` so the global scope filters it like
 * everything else.
 *
 * `approved_by_vendor_at` NULL MEANS NO ACCESS. Not "not yet checked", not
 * "pending so probably fine" — no access. `isLive()` is the only question any
 * caller should ask, and `scopeLive()` the only way a query should find these,
 * so that the null case cannot be reasoned about incorrectly one file at a
 * time.
 *
 * The vendor approves EACH CLIENT SEPARATELY and can revoke. That is not
 * ceremony: a vendor's security posture is commercially sensitive, and a
 * profile that was readable by every bank on the platform the moment it was
 * written would be a profile no vendor completes.
 */
class TrustProfileShare extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_trust_profile_shares';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'trust_profile_id', 'organization_id', 'third_party_id', 'scope', 'requested_at', 'requested_by',
    ];

    /**
     * The vendor's decision, and nothing a client can post.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = [
        'status', 'approved_by_vendor_at', 'approved_by_portal_user_id', 'revoked_at', 'revocation_reason',
    ];

    protected $casts = [
        'scope' => 'array',
        'requested_at' => 'datetime',
        'approved_by_vendor_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_REQUESTED,
    ];

    /** @return BelongsTo<TrustProfile, $this> */
    public function trustProfile(): BelongsTo
    {
        return $this->belongsTo(TrustProfile::class, 'trust_profile_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /** @return BelongsTo<ThirdParty, $this> */
    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Whether this client may read the profile right now.
     *
     * Both halves are load-bearing. A status of `approved` with no timestamp
     * is data corruption, and a timestamp with a later revocation is a share
     * the vendor has withdrawn.
     */
    public function isLive(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->approved_by_vendor_at !== null
            && $this->revoked_at === null;
    }

    /**
     * The sections this share exposes.
     *
     * A null scope means EVERY shareable section, because the vendor approved
     * the request as it was made and a request with no sections named is a
     * request for the profile. An empty array means the vendor approved
     * nothing, which is a different thing and must not collapse into the same
     * answer.
     *
     * @return list<string>
     */
    public function sections(): array
    {
        if ($this->scope === null) {
            return TrustProfileSchema::shareableSections();
        }

        return array_values(array_intersect(
            (array) $this->scope,
            TrustProfileSchema::shareableSections(),
        ));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED)
            ->whereNotNull('approved_by_vendor_at')
            ->whereNull('revoked_at');
    }
}

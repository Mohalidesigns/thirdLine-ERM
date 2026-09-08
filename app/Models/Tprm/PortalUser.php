<?php

namespace App\Models\Tprm;

use App\Models\Tprm\Concerns\HasTprmUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A person at a vendor, with an account on the portal — FR-PRT-01.
 *
 * IT HAS NO RELATIONSHIP TO `App\Models\User`, AND THAT IS THE WHOLE DESIGN.
 * Not a flag on the internal user, not a role, not a polymorphic parent — a
 * different table, resolved by a different provider, authenticated by a
 * different guard, holding a different session cookie. Every shortcut that
 * merges the two populations ends the same way: a query somewhere forgets to
 * filter, and a vendor sees the register.
 *
 * `invited_by` DOES point at a `users` row, and that is not a contradiction:
 * it records which of our staff issued the invitation. It is provenance, not
 * identity, and nothing authenticates through it.
 *
 * MFA IS MANDATORY HERE and optional internally. A vendor credential is used
 * from outside our network, reaches assessment content and evidence, and is
 * the likeliest password in the system to be reused on something that has
 * already been breached. `mfa_enabled` false means the account cannot finish
 * signing in — see `PortalAuthService`.
 */
class PortalUser extends Authenticatable
{
    use BelongsToOrganization, HasTprmUuid, SoftDeletes;

    protected $table = 'tp_portal_users';

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /** How many failures before the account locks, and for how long. */
    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_MINUTES = 15;

    protected $fillable = [
        'organization_id', 'third_party_id', 'email', 'name', 'password', 'invited_by', 'invited_at',
    ];

    /**
     * Never mass-assignable. Every one of these is set by
     * `PortalAuthService`, and a vendor-facing form that could post its own
     * `status` or `mfa_enabled` would be the shortest path to an unverified
     * account with a live session.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = [
        'status', 'mfa_secret', 'mfa_enabled', 'accepted_at', 'last_login_at',
        'failed_attempts', 'locked_until',
    ];

    /** @var list<string> */
    protected $hidden = ['password', 'mfa_secret', 'remember_token'];

    /**
     * The `$casts` PROPERTY, not a `casts()` method. Both work at runtime;
     * only the property is read by the static analyser, and without it every
     * `->isAfter()` on a datetime here is reported as a method call on a
     * string. The rest of the module's models use the property too.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'password' => 'hashed',
        'mfa_secret' => 'encrypted',
        'mfa_enabled' => 'boolean',
        'invited_at' => 'datetime',
        'accepted_at' => 'datetime',
        'last_login_at' => 'datetime',
        'locked_until' => 'datetime',
        'failed_attempts' => 'integer',
    ];

    protected $attributes = [
        'status' => self::STATUS_INVITED,
        'mfa_enabled' => false,
        'failed_attempts' => 0,
    ];

    /** @return BelongsTo<ThirdParty, $this> */
    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    /**
     * Which of our people issued the invitation.
     *
     * Provenance only. Nothing authenticates through this, and the portal
     * guard has no idea the `users` table exists.
     *
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isLocked(?Carbon $asOf = null): bool
    {
        return $this->locked_until !== null
            && $this->locked_until->isAfter($asOf ?? Carbon::now());
    }

    /**
     * Whether this account may hold a full session.
     *
     * Both halves matter. An account that has accepted its invitation but not
     * enrolled MFA gets as far as the enrolment screen and no further, which
     * is what makes "mandatory" true rather than aspirational.
     */
    public function canAuthenticate(): bool
    {
        return $this->isActive() && ! $this->isLocked() && $this->deleted_at === null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * The vendors this account may act for.
     *
     * Exactly one, always. The method exists so that every authorisation check
     * in the portal reads the same way and none of them is tempted to compare
     * `third_party_id` inline — the comparison that gets forgotten.
     */
    public function actsFor(int $thirdPartyId): bool
    {
        return (int) $this->third_party_id === $thirdPartyId;
    }
}

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
 * already been breached.
 *
 * TWO METHODS, AND EMAIL IS THE DEFAULT. A code to the inbox needs no
 * enrolment, which matters because the tail of a bank's vendor register is
 * small firms who will not install an authenticator — and an MFA requirement
 * they cannot meet becomes an MFA requirement somebody turns off. TOTP stays
 * available for vendors who can hold a secret, and is the stronger of the two:
 * an emailed code shares a channel with password recovery, so one compromised
 * mailbox is both factors, where a TOTP secret lives on a separate device.
 */
class PortalUser extends Authenticatable
{
    use BelongsToOrganization, HasTprmUuid, SoftDeletes;

    protected $table = 'tp_portal_users';

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const METHOD_EMAIL = 'email';

    public const METHOD_TOTP = 'totp';

    /** @var list<string> */
    public const METHODS = [self::METHOD_EMAIL, self::METHOD_TOTP];

    /** How many failures before the account locks, and for how long. */
    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_MINUTES = 15;

    /**
     * How long an emailed code is good for.
     *
     * Ten minutes: long enough to switch to a mail client on a slow connection
     * and come back, short enough that a code sitting in an unattended inbox
     * is not a standing key. TOTP's own window is 30 seconds and is handled by
     * the algorithm rather than by us.
     */
    public const CODE_TTL_MINUTES = 10;

    /** The shortest gap between two code requests. */
    public const CODE_RESEND_SECONDS = 60;

    /** Wrong codes tolerated against one issued code before it is burned. */
    public const CODE_MAX_ATTEMPTS = 5;

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
        'status', 'mfa_secret', 'mfa_enabled', 'mfa_method', 'mfa_code_hash',
        'mfa_code_expires_at', 'mfa_code_sent_at', 'mfa_code_attempts',
        'accepted_at', 'last_login_at', 'failed_attempts', 'locked_until',
    ];

    /** @var list<string> */
    protected $hidden = ['password', 'mfa_secret', 'mfa_code_hash', 'remember_token'];

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
        'mfa_code_expires_at' => 'datetime',
        'mfa_code_sent_at' => 'datetime',
        'mfa_code_attempts' => 'integer',
    ];

    protected $attributes = [
        'status' => self::STATUS_INVITED,
        'mfa_enabled' => false,
        'mfa_method' => self::METHOD_EMAIL,
        'failed_attempts' => 0,
        'mfa_code_attempts' => 0,
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
     * Deliberately does NOT include a second-factor check: whether MFA has
     * been satisfied is a property of the SESSION, not of the account, and
     * folding the two together is how "is this account allowed to exist" and
     * "has this person proved who they are just now" get confused.
     */
    public function canAuthenticate(): bool
    {
        return $this->isActive() && ! $this->isLocked() && $this->deleted_at === null;
    }

    public function usesEmailCodes(): bool
    {
        return $this->mfa_method !== self::METHOD_TOTP;
    }

    /**
     * Whether a second factor is available to this account at all.
     *
     * ALWAYS TRUE FOR THE EMAIL METHOD, because every account has an address —
     * that is the point of making it the default. TOTP has to be enrolled
     * first, and an account that chose it and stopped halfway gets as far as
     * the enrolment screen and no further.
     */
    public function hasSecondFactor(): bool
    {
        return $this->usesEmailCodes() || $this->mfa_enabled;
    }

    /** Whether an issued code is still live. */
    public function hasLiveCode(?Carbon $asOf = null): bool
    {
        return $this->mfa_code_hash !== null
            && $this->mfa_code_expires_at !== null
            && $this->mfa_code_expires_at->isAfter($asOf ?? Carbon::now());
    }

    /**
     * Seconds until another code may be requested, or zero.
     *
     * A resend button with no throttle is a way to send somebody a hundred
     * emails, and mail providers notice.
     */
    public function secondsUntilResend(?Carbon $asOf = null): int
    {
        if ($this->mfa_code_sent_at === null) {
            return 0;
        }

        $elapsed = ($asOf ?? Carbon::now())->diffInSeconds($this->mfa_code_sent_at, true);

        return (int) max(0, self::CODE_RESEND_SECONDS - $elapsed);
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

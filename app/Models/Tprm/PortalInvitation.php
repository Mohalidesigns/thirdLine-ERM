<?php

namespace App\Models\Tprm;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A single-use invitation to the vendor portal — FR-PRT-01.
 *
 * THE TOKEN IS NEVER STORED. `token_hash` holds a SHA-256 of it and the plain
 * value exists only in the moment it is generated, long enough to be put in
 * one email. An invitation table that holds usable tokens is an invitation
 * table that anybody with a database backup — or a read-only reporting
 * replica, or a support export — can accept on somebody else's behalf.
 *
 * SHA-256 RATHER THAN BCRYPT, deliberately, and this is the one place in the
 * codebase where that is the right answer. A bcrypt lookup cannot be indexed:
 * accepting an invitation would mean loading every open invitation and hashing
 * the candidate against each. The token is 32 bytes of CSPRNG output, so there
 * is no dictionary to attack and nothing for a slow hash to buy; the property
 * that matters is that the stored value is useless, and a digest gives that.
 */
class PortalInvitation extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_portal_invitations';

    public const ROLE_RESPONDER = 'responder';

    public const ROLE_ADMIN = 'admin';

    /** @var list<string> */
    public const ROLES = [self::ROLE_RESPONDER, self::ROLE_ADMIN];

    public const TTL_DAYS = 14;

    protected $fillable = [
        'organization_id', 'third_party_id', 'email', 'token_hash', 'role', 'expires_at', 'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** @return BelongsTo<ThirdParty, $this> */
    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** A fresh token. Returned once; only its digest is ever persisted. */
    public static function mintToken(): string
    {
        return Str::random(64);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isOpen(?Carbon $asOf = null): bool
    {
        $asOf ??= Carbon::now();

        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isAfter($asOf);
    }

    /**
     * Why this invitation cannot be used, in words a vendor can act on.
     *
     * "Invalid invitation" is what most products say and it sends the person
     * to the wrong place: a link that expired needs a new one from the bank,
     * a link already used needs the password-reset flow, and a revoked one
     * needs a conversation. Three different next steps.
     */
    public function refusalReason(?Carbon $asOf = null): ?string
    {
        $asOf ??= Carbon::now();

        return match (true) {
            $this->accepted_at !== null => 'This invitation has already been used. If the account is yours, '
                .'sign in instead, or use the forgotten-password link.',
            $this->revoked_at !== null => 'This invitation was withdrawn. Ask your contact at the organisation '
                .'that sent it to issue a new one.',
            ! $this->expires_at->isAfter($asOf) => sprintf(
                'This invitation expired on %s. Ask for a new one — they are valid for %d days.',
                $this->expires_at->toFormattedDateString(),
                self::TTL_DAYS,
            ),
            default => null,
        };
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }
}

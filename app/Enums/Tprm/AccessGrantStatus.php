<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * The life of a named person's access — FR-ACC-02 and FR-ACC-05.
 *
 * `Expired` IS A STORED STATE AND `isLive()` STILL RETURNS TRUE FOR IT. This
 * looks wrong and is the point of the enum. A grant past its valid-to date has
 * expired on paper; whether the account was actually disabled is a different
 * question, and until somebody records a revocation with evidence the credential
 * should be assumed to still work. FR-ACC-05 calls that a Critical finding, and
 * AC-10 blocks termination on it, both of which need the state to count as live.
 *
 * Only `Revoked` — which requires evidence — takes a grant out of the way.
 */
enum AccessGrantStatus: string
{
    use EnumHelpers;

    case Requested = 'requested';
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Active => 'Active',
            self::Expired => 'Expired, not revoked',
            self::Revoked => 'Revoked',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Revoked => 'neutral',
            self::Active => 'medium',
            self::Expired => 'critical',
            self::Requested => 'low',
        };
    }

    /**
     * Whether the credential should be assumed to still work.
     *
     * See the class comment: expiry is a date passing, revocation is somebody
     * doing something. Only the second one is evidence.
     */
    public function isLive(): bool
    {
        return $this !== self::Revoked;
    }
}

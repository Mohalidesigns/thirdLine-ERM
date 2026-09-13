<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * The life of a connection — FR-ACC-01, read by AC-10.
 *
 * `Closed` is the ONLY state that lets an engagement terminate, and reaching
 * it needs closure evidence. `Suspended` deliberately does not qualify: a
 * suspended tunnel is a tunnel whose configuration still exists, and the
 * control being tested here is removal, not deactivation.
 */
enum ConnectionStatus: string
{
    use EnumHelpers;

    case Requested = 'requested';
    case Approved = 'approved';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Closed => 'neutral',
            self::Active => 'medium',
            self::Suspended => 'high',
            self::Requested, self::Approved => 'low',
        };
    }

    /** Whether a path still exists that somebody would have to tear down. */
    public function isOpen(): bool
    {
        return $this !== self::Closed;
    }
}

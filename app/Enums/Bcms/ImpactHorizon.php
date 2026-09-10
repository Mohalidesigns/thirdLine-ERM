<?php

namespace App\Enums\Bcms;

/**
 * The time horizons a BIA scores impact over (Blueprint §9.1).
 *
 * Ordered, and `hours()` is why: MTPD is derived by finding the first horizon
 * at which impact crosses the organisation's tolerance, which needs the
 * horizons compared numerically rather than by the order somebody listed them
 * in a form.
 */
enum ImpactHorizon: string
{
    case H1 = '1h';
    case H4 = '4h';
    case H8 = '8h';
    case H24 = '24h';
    case H72 = '72h';
    case W1 = '1w';
    case W2 = '2w';

    public function hours(): int
    {
        return match ($this) {
            self::H1 => 1,
            self::H4 => 4,
            self::H8 => 8,
            self::H24 => 24,
            self::H72 => 72,
            self::W1 => 168,
            self::W2 => 336,
        };
    }
}

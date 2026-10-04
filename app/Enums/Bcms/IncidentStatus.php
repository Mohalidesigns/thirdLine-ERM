<?php

namespace App\Enums\Bcms;

/**
 * `bcms_incidents.status` (ADR 0020 §3). The ISO 22320/22361 response arc —
 * detection, declaration, activation, response, containment, recovery,
 * stand-down, closure — maps onto these five states; see the clause map §1.1
 * table for which column or log entry carries each step that is not a status
 * value of its own.
 */
enum IncidentStatus: string
{
    case Open = 'open';
    case Contained = 'contained';
    case Recovering = 'recovering';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Contained => 'Contained',
            self::Recovering => 'Recovering',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }
}

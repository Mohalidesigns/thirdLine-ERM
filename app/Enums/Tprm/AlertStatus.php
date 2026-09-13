<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * The lifecycle of a monitoring alert — §6.9.
 *
 * `Muted` carries a reason and an expiry on the row, both mandatory. A mute
 * without an expiry is how a monitoring stream stops being read: the noisy
 * rule gets silenced once and nobody ever revisits it, and eighteen months
 * later the console is trusted for a coverage it no longer has.
 */
enum AlertStatus: string
{
    use EnumHelpers;

    case New = 'new';
    case Acknowledged = 'acknowledged';
    case Actioned = 'actioned';
    case Muted = 'muted';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Acknowledged => 'Acknowledged',
            self::Actioned => 'Actioned',
            self::Muted => 'Muted',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'high',
            self::Acknowledged => 'medium',
            self::Actioned, self::Closed => 'low',
            self::Muted => 'neutral',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Acknowledged, self::Muted, self::Closed],
            self::Acknowledged => [self::Actioned, self::Muted, self::Closed],
            self::Actioned => [self::Closed],
            // A mute expiring returns the alert to New, not to Closed.
            self::Muted => [self::New, self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Acknowledged], true);
    }
}

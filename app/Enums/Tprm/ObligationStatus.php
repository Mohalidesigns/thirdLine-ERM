<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * The lifecycle of a contractual or regulatory obligation — TRD §5.2.
 *
 * An obligation is the unit that turns a contract from a PDF into something
 * monitorable (FR-CTR-06): each one has an obligor, an owner, a frequency and
 * an evidence requirement, and a recurring one regenerates on schedule.
 *
 * `Waived` is terminal for the instance, not for the obligation: a waived
 * quarterly obligation still produces next quarter's instance. Waiving the
 * series is retiring the obligation, which is a delete with an audit row.
 */
enum ObligationStatus: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case Due = 'due';
    case Satisfied = 'satisfied';
    case Breached = 'breached';
    case Waived = 'waived';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Due => 'Due',
            self::Satisfied => 'Satisfied',
            self::Breached => 'Breached',
            self::Waived => 'Waived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Satisfied => 'low',
            self::Due => 'medium',
            self::Breached => 'critical',
            self::Waived => 'neutral',
            self::Pending => 'neutral',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Due, self::Satisfied, self::Waived],
            self::Due => [self::Satisfied, self::Breached, self::Waived],
            // Late evidence still satisfies it; the breach stays on the count.
            self::Breached => [self::Satisfied, self::Waived],
            self::Satisfied, self::Waived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isOutstanding(): bool
    {
        return in_array($this, [self::Pending, self::Due, self::Breached], true);
    }
}

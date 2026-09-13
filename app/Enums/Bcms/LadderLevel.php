<?php

namespace App\Enums\Bcms;

/**
 * The ISO 22398 exercise ladder.
 *
 * THIS IS NOT A DROPDOWN. It is an ordered maturity progression, and the
 * ordering is the feature: the engine warns when a full-scale exercise is
 * scheduled for a process that has never had a successful tabletop, and each
 * level is expected to build on the corrective actions of the one below
 * (compliance-analyst rule 1, Blueprint §2.2).
 *
 * `rank()` is what makes that checkable. A string comparison of
 * 'tabletop' against 'full_scale' answers alphabetically, which is wrong in
 * both directions.
 */
enum LadderLevel: string
{
    case Orientation = 'orientation';
    case Tabletop = 'tabletop';
    case Walkthrough = 'walkthrough';
    case Drill = 'drill';
    case Functional = 'functional';
    case FullScale = 'full_scale';

    public function rank(): int
    {
        return match ($this) {
            self::Orientation => 1,
            self::Tabletop => 2,
            self::Walkthrough => 3,
            self::Drill => 4,
            self::Functional => 5,
            self::FullScale => 6,
        };
    }

    /** The level immediately below this one, or null at the bottom of the ladder. */
    public function previous(): ?self
    {
        $below = array_filter(self::cases(), fn (self $l) => $l->rank() === $this->rank() - 1);

        return $below === [] ? null : array_values($below)[0];
    }

    public function isAtOrAbove(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::Orientation => 'Orientation / briefing',
            self::Tabletop => 'Tabletop',
            self::Walkthrough => 'Walkthrough',
            self::Drill => 'Drill',
            self::Functional => 'Functional exercise',
            self::FullScale => 'Full-scale exercise',
        };
    }
}

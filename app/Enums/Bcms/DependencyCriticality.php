<?php

namespace App\Enums\Bcms;

/**
 * How badly a process needs one dependency (`bcms_dependencies.criticality`).
 *
 * `rank()` exists so the SPOF register can sort by consequence rather than
 * alphabetically, which is the whole difference between a register somebody
 * works down and a list they scroll past.
 */
enum DependencyCriticality: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }

    /**
     * A single point of failure at this criticality is a finding, not a note.
     *
     * Used by the BIA report to decide which SPOFs are raised as findings
     * automatically and which are listed for the assessor to judge. A Low
     * dependency with no alternative is a fact; a Critical one is a gap.
     */
    public function spofIsFinding(): bool
    {
        return in_array($this, [self::Critical, self::High], true);
    }
}

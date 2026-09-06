<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * Whether a required contract clause is actually in the contract.
 *
 * `Partial` blocks activation exactly as `Absent` does (FR-CTR-05, AC-06), and
 * that is the point rather than an oversight: an audit-rights clause that
 * omits the regulator is not a partial audit-rights clause, it is a clause
 * that fails the CBN requirement it exists to satisfy. Anything short of
 * present needs a waiver with a rationale, an approver and an expiry.
 */
enum ClausePresence: string
{
    use EnumHelpers;

    case Present = 'present';
    case Partial = 'partial';
    case Absent = 'absent';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Partial => 'Partial',
            self::Absent => 'Absent',
            self::NotApplicable => 'Not applicable',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Present => 'low',
            self::Partial => 'high',
            self::Absent => 'critical',
            self::NotApplicable => 'neutral',
        };
    }

    /**
     * Whether this presence value blocks an engagement reaching `active`,
     * for a clause marked `is_blocking`.
     */
    public function blocksActivation(): bool
    {
        return in_array($this, [self::Partial, self::Absent], true);
    }
}

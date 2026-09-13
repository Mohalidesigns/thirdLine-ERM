<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * An analyst's decision on one sanctions, PEP or adverse-media match.
 *
 * `TrueMatch` is the highest-consequence value in the module: AC-08 requires
 * that confirming one suspends every engagement with the third party, forces
 * `RR = 100`, notifies the AML function and opens an STR task with a 24-hour
 * deadline (CBN AML/CFT Regulations Reg. 38). It is never set by a screening
 * driver — only by a named person, with a rationale, recorded against
 * `decided_by` and `decided_at`.
 *
 * `Possible` exists so that "we looked and could not tell" is a recordable
 * answer rather than a false positive by default. Regulation 35 requires the
 * decision to be retrievable for five years; "we cleared it" and "we could not
 * establish it" are different answers to a supervisor.
 */
enum ScreeningDecision: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case TrueMatch = 'true_match';
    case FalsePositive = 'false_positive';
    case Possible = 'possible';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::TrueMatch => 'True match',
            self::FalsePositive => 'False positive',
            self::Possible => 'Possible match',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::TrueMatch => 'critical',
            self::Possible => 'high',
            self::Pending => 'medium',
            self::FalsePositive => 'low',
        };
    }

    /** Whether the decision has been made by a person. */
    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }

    /** Whether this decision triggers the AC-08 escalation. */
    public function escalates(): bool
    {
        return $this === self::TrueMatch;
    }
}

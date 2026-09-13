<?php

namespace App\Enums\Bcms;

/**
 * The result of a completed exercise.
 *
 * `Inconclusive` exists because the alternative is worse. An exercise
 * abandoned when the DR site was unreachable proves nothing, and recording it
 * as `Fail` claims a finding that was never made while recording it as `Pass`
 * is a lie to an examiner. Inconclusive occurrences do not satisfy a
 * regulatory cadence — a quarterly failover obligation with one inconclusive
 * quarter is short by one.
 */
enum ExerciseOutcome: string
{
    case Pass = 'pass';
    case PassWithFindings = 'pass_with_findings';
    case Fail = 'fail';
    case Inconclusive = 'inconclusive';

    /** Does this outcome discharge a regulatory testing obligation? */
    public function satisfiesCadence(): bool
    {
        return in_array($this, [self::Pass, self::PassWithFindings], true);
    }
}

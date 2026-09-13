<?php

namespace App\Enums\Bcms;

/**
 * What kind of thing an exercise, audit or incident turned up
 * (`bcms_findings`, Blueprint §9.3).
 *
 * The three are not severities and must not be collapsed into one. A
 * `Nonconformity` is a failure to meet a stated requirement and carries a
 * clause reference and a mandatory corrective action under ISO 22301 10.1. An
 * `Improvement` is an opportunity nobody is obliged to take. An `Observation`
 * is a fact recorded so that the next exercise can look at it again.
 *
 * A system that lets a customer file every finding as an observation is a
 * system that produces a clean audit and an untested plan.
 */
enum FindingClassification: string
{
    case Observation = 'observation';
    case Improvement = 'improvement';
    case Nonconformity = 'nonconformity';

    /** Does clause 10.1 require a corrective action for this classification? */
    public function requiresCorrectiveAction(): bool
    {
        return $this === self::Nonconformity;
    }
}

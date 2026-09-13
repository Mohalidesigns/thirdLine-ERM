<?php

namespace App\Services\Tprm\Assessment;

use App\Enums\Tprm\AssuranceLevel;
use App\Enums\Tprm\ComplianceLevel;

/**
 * One answer, reduced to exactly what TRD §7.4 needs to score it.
 *
 * A DTO rather than the Eloquent model, so `AssessmentScorer` stays pure. The
 * five boolean-ish modifier flags are resolved by the caller — deciding whether
 * a certificate's scope names the service, or whether a SOC 2's period has
 * lapsed, needs the evidence rows and the clock — and the scorer then applies
 * them arithmetically with no idea where they came from. That split is what
 * lets the golden-file suite state a fixture in one line and assert an exact
 * number.
 */
class ScoreableAnswer
{
    public function __construct(
        public readonly string $questionCode,
        public readonly float $weight,
        public readonly ComplianceLevel $compliance,
        public readonly ?AssuranceLevel $assuranceLevel = null,
        public readonly ?string $sectionCode = null,
        public readonly ?string $domainTag = null,
        public readonly bool $isCritical = false,

        /** Evidence expired, or its period ended more than twelve months ago. */
        public readonly bool $evidenceExpired = false,

        /** The control is evidenced only by a bridge letter for the gap period. */
        public readonly bool $bridgeLetterOnly = false,

        /** The certificate's scope text does not name the service consumed. */
        public readonly bool $scopeMismatch = false,

        /** The response-quality checker flagged the answer as evasive or contradicted. */
        public readonly bool $qualityFlagged = false,

        /** Cycles this answer has been carried without being re-evidenced. */
        public readonly int $carryForwardCycles = 0,
    ) {}
}

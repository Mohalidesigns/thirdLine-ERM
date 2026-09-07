<?php

namespace App\Services\Tprm\Scoring;

/**
 * One finding, as the residual calculator sees it.
 *
 * Deliberately NOT the Eloquent model. The calculator is pure and testable
 * against plain values, and the three booleans below are decisions made where
 * the clock and the remediation plan live — not re-derived inside the
 * arithmetic, where a subtly different reading of "overdue" would give the
 * score panel and the findings board two different answers about the same
 * finding.
 */
class FindingContribution
{
    public function __construct(
        public readonly string $severity,
        public readonly bool $withinSlaWithAcceptedPlan = false,
        public readonly bool $overdueBeyondThreshold = false,
        public readonly bool $riskAccepted = false,
        public readonly ?string $reference = null,
        public readonly ?string $title = null,
        public readonly ?int $id = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'severity' => $this->severity,
            'within_sla_with_accepted_plan' => $this->withinSlaWithAcceptedPlan,
            'overdue_beyond_threshold' => $this->overdueBeyondThreshold,
            'risk_accepted' => $this->riskAccepted,
        ];
    }
}

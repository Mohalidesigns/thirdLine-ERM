<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * The lifecycle of a finding — TRD §5.2.
 *
 * `Overdue` and `Escalated` are NOT states here, and that is deliberate even
 * though §5.2 lists them alongside the rest. Overdue is a FUNCTION of
 * `target_date` and the clock; escalation is a level, held in
 * `escalation_level`. Storing either as a status means a finding that passes
 * its target date at midnight stays "in_remediation" until a job runs, and
 * every report that reads status disagrees with every report that reads dates.
 * `isOverdue()` on the model answers the question from the date.
 *
 * The three closure states are separate rather than one `closed` with a
 * reason, because the FU calculation weights them differently: a
 * risk-accepted finding keeps contributing at 0.5 until its acceptance
 * expires (TRD §7.5), while a remediated one contributes nothing.
 */
enum FindingStatus: string
{
    use EnumHelpers;

    case Open = 'open';
    case Assigned = 'assigned';
    case InRemediation = 'in_remediation';
    case EvidenceSubmitted = 'evidence_submitted';
    case UnderVerification = 'under_verification';
    case ClosedRemediated = 'closed_remediated';
    case ClosedRiskAccepted = 'closed_risk_accepted';
    case ClosedFalsePositive = 'closed_false_positive';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Assigned => 'Assigned',
            self::InRemediation => 'In remediation',
            self::EvidenceSubmitted => 'Evidence submitted',
            self::UnderVerification => 'Under verification',
            self::ClosedRemediated => 'Closed — remediated',
            self::ClosedRiskAccepted => 'Closed — risk accepted',
            self::ClosedFalsePositive => 'Closed — false positive',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ClosedRemediated, self::ClosedFalsePositive => 'low',
            self::ClosedRiskAccepted => 'medium',
            self::Open => 'high',
            default => 'medium',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Assigned, self::ClosedFalsePositive, self::ClosedRiskAccepted],
            self::Assigned => [self::InRemediation, self::ClosedFalsePositive, self::ClosedRiskAccepted],
            self::InRemediation => [self::EvidenceSubmitted, self::ClosedRiskAccepted, self::ClosedFalsePositive],
            self::EvidenceSubmitted => [self::UnderVerification, self::InRemediation],
            // Verification either closes it or sends it back for more work.
            self::UnderVerification => [self::ClosedRemediated, self::InRemediation, self::ClosedRiskAccepted, self::ClosedFalsePositive],
            // A risk acceptance that expires reopens the finding (FR-FND-04).
            self::ClosedRiskAccepted => [self::Open],
            self::ClosedRemediated, self::ClosedFalsePositive => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isOpen(): bool
    {
        return ! str_starts_with($this->value, 'closed_');
    }

    /**
     * Whether this finding still contributes to FU.
     *
     * A risk-accepted finding does, at half weight, until its acceptance
     * expires — TRD §7.5. Accepting a risk is not the same as fixing it, and
     * the residual score has to keep saying so.
     */
    public function contributesToUplift(): bool
    {
        return $this->isOpen() || $this === self::ClosedRiskAccepted;
    }
}

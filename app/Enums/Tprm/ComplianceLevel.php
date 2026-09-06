<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * A reviewer's verdict on one answer, and its weight in AC (TRD §7.4).
 *
 * `NotApplicable` is EXCLUDED from both sums rather than scored zero. An
 * inapplicable control is not a failed one; scoring it zero would punish a
 * vendor for a question that should not have been asked, and would make a
 * badly scoped questionnaire look like a bad vendor.
 *
 * `Unanswered` has no weight either, but for the opposite reason: it is not a
 * verdict at all. An assessment with unanswered questions is not finished, and
 * `AssessmentScorer` refuses to produce a final score while any remain.
 */
enum ComplianceLevel: string
{
    use EnumHelpers;

    case Compliant = 'compliant';
    case Partial = 'partial';
    case NonCompliant = 'non_compliant';
    case NotApplicable = 'na';
    case Unanswered = 'unanswered';

    public function label(): string
    {
        return match ($this) {
            self::Compliant => 'Compliant',
            self::Partial => 'Partial',
            self::NonCompliant => 'Non-compliant',
            self::NotApplicable => 'Not applicable',
            self::Unanswered => 'Unanswered',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Compliant => 'low',
            self::Partial => 'medium',
            self::NonCompliant => 'critical',
            self::NotApplicable => 'neutral',
            self::Unanswered => 'neutral',
        };
    }

    /** Whether this verdict enters the AC numerator and denominator at all. */
    public function isScored(): bool
    {
        return in_array($this, [self::Compliant, self::Partial, self::NonCompliant], true);
    }

    /** compliance_q ∈ {1.0, 0.5, 0.0}; null for the two excluded verdicts. */
    public function weight(): ?float
    {
        if (! $this->isScored()) {
            return null;
        }

        /** @var array<string, float> $weights */
        $weights = config('tprm.scoring.compliance');

        return (float) $weights[$this->value];
    }
}

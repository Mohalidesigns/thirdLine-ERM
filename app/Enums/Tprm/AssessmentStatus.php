<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * The lifecycle of one questionnaire cycle — TRD §5.2.
 *
 * `Scored` is separate from `Validated` and the order is Validated → Scored,
 * not the other way round. A score computed before a reviewer has accepted the
 * answers is a score over unreviewed vendor claims; the module's whole
 * argument (TRD §7.4) is that a number is only worth what the evidence behind
 * it is worth, so the review gate comes first.
 *
 * `Expired` and `Withdrawn` are reachable from most live states and are not
 * part of the happy path, which is why they are listed separately in §5.2.
 */
enum AssessmentStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case Scoped = 'scoped';
    case Issued = 'issued';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case ClarificationRequested = 'clarification_requested';
    case Validated = 'validated';
    case Scored = 'scored';
    case Closed = 'closed';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scoped => 'Scoped',
            self::Issued => 'Issued',
            self::InProgress => 'In progress',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::ClarificationRequested => 'Clarification requested',
            self::Validated => 'Validated',
            self::Scored => 'Scored',
            self::Closed => 'Closed',
            self::Expired => 'Expired',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Closed, self::Scored, self::Validated => 'low',
            self::Expired => 'high',
            self::Withdrawn, self::Draft => 'neutral',
            default => 'medium',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Scoped, self::Withdrawn],
            self::Scoped => [self::Issued, self::Draft, self::Withdrawn],
            self::Issued => [self::InProgress, self::Expired, self::Withdrawn],
            self::InProgress => [self::Submitted, self::Expired, self::Withdrawn],
            self::Submitted => [self::UnderReview, self::Withdrawn],
            self::UnderReview => [self::ClarificationRequested, self::Validated, self::Withdrawn],
            // A clarification returns the vendor to the response screen.
            self::ClarificationRequested => [self::InProgress, self::Submitted, self::Expired, self::Withdrawn],
            self::Validated => [self::Scored],
            self::Scored => [self::Closed],
            self::Closed, self::Expired, self::Withdrawn => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Whether the vendor may still edit responses. */
    public function isOpenToVendor(): bool
    {
        return in_array($this, [self::Issued, self::InProgress, self::ClarificationRequested], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Expired, self::Withdrawn], true);
    }
}

<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * The lifecycle of the legal entity — TRD §5.2.
 *
 * Entity status is NOT engagement status. A third party can be `active` while
 * one of its engagements is `terminated`, and `blacklisted` while engagements
 * are still winding down. The two are separate columns on separate tables for
 * that reason, and the only coupling is the one AC-08 requires: a confirmed
 * sanctions match suspends the entity AND every engagement under it.
 *
 * `Blacklisted` is terminal by policy rather than by data: re-approving a
 * blacklisted entity is a decision with an approver and a rationale, made by
 * clearing the blacklist rather than by a status transition, so no transition
 * out of it is offered here.
 */
enum ThirdPartyStatus: string
{
    use EnumHelpers;

    case Prospect = 'prospect';
    case Screening = 'screening';
    case ApprovedSupplier = 'approved_supplier';
    case Active = 'active';
    case UnderReview = 'under_review';
    case Suspended = 'suspended';
    case Exiting = 'exiting';
    case Exited = 'exited';
    case Blacklisted = 'blacklisted';

    public function label(): string
    {
        return match ($this) {
            self::Prospect => 'Prospect',
            self::Screening => 'Screening',
            self::ApprovedSupplier => 'Approved supplier',
            self::Active => 'Active',
            self::UnderReview => 'Under review',
            self::Suspended => 'Suspended',
            self::Exiting => 'Exiting',
            self::Exited => 'Exited',
            self::Blacklisted => 'Blacklisted',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active, self::ApprovedSupplier => 'low',
            self::Prospect, self::Screening, self::UnderReview, self::Exiting => 'medium',
            self::Suspended => 'high',
            self::Blacklisted => 'critical',
            self::Exited => 'neutral',
        };
    }

    /**
     * The transitions this status permits.
     *
     * Suspension is reachable from every live status because AC-08 has to be
     * able to suspend whatever the entity happens to be doing at the time.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Prospect => [self::Screening, self::Blacklisted],
            self::Screening => [self::ApprovedSupplier, self::Prospect, self::Suspended, self::Blacklisted],
            self::ApprovedSupplier => [self::Active, self::UnderReview, self::Suspended, self::Blacklisted],
            self::Active => [self::UnderReview, self::Suspended, self::Exiting, self::Blacklisted],
            self::UnderReview => [self::Active, self::Suspended, self::Exiting, self::Blacklisted],
            self::Suspended => [self::UnderReview, self::Active, self::Exiting, self::Blacklisted],
            self::Exiting => [self::Exited, self::Suspended, self::Blacklisted],
            self::Exited, self::Blacklisted => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Whether an engagement may be raised against an entity in this status. */
    public function acceptsNewEngagements(): bool
    {
        return in_array($this, [self::ApprovedSupplier, self::Active], true);
    }
}

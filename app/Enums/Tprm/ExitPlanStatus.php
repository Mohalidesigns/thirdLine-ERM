<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * The lifecycle of an exit plan — TRD §5.2, the stage every competitor skips.
 *
 * `Stale` is a real stored state here, unlike the finding module's overdue,
 * and the difference is worth stating: staleness has a CONSEQUENCE beyond
 * display. A plan past its test interval raises a High finding and adds
 * `SU = 4` to the residual score (AC-11), so something has to have fired. The
 * nightly job that sets it is what fires those, and the status is the record
 * that it did.
 */
enum ExitPlanStatus: string
{
    use EnumHelpers;

    case NotRequired = 'not_required';
    case Draft = 'draft';
    case Approved = 'approved';
    case Tested = 'tested';
    case Stale = 'stale';
    case Invoked = 'invoked';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'Not required',
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Tested => 'Tested',
            self::Stale => 'Stale',
            self::Invoked => 'Invoked',
            self::Completed => 'Completed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Tested, self::Completed => 'low',
            self::Approved => 'medium',
            self::Stale => 'high',
            self::Invoked => 'critical',
            self::NotRequired, self::Draft => 'neutral',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::NotRequired => [self::Draft],
            self::Draft => [self::Approved, self::NotRequired],
            self::Approved => [self::Tested, self::Stale, self::Draft, self::Invoked],
            self::Tested => [self::Stale, self::Draft, self::Invoked],
            // A stale plan is re-tested or re-drafted; it is still invocable,
            // because a bad plan in an emergency beats no plan.
            self::Stale => [self::Tested, self::Draft, self::Invoked],
            self::Invoked => [self::Completed],
            self::Completed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Whether this plan satisfies the "has a tested exit plan" coverage test. */
    public function isCurrent(): bool
    {
        return $this === self::Tested;
    }
}

<?php

namespace App\Enums;

/**
 * The lifecycle of a control test.
 *
 * The column used to be a MySQL ENUM extended by a driver-guarded ALTER, so
 * the set of valid values existed only on MySQL and only in a migration. It is
 * a plain string column now, and this is the authority — one definition,
 * enforced identically on every driver, and visible to static analysis.
 */
enum ControlTestStatus: string
{
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case PendingReview = 'pending_review';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * A validation rule string for `in:` — keeps the request rules in step
     * with the enum instead of restating it.
     */
    public static function validationRule(): string
    {
        return 'in:'.implode(',', self::values());
    }

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::InProgress => 'In Progress',
            self::PendingReview => 'Pending Review',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether the test is finished, one way or another.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }
}

<?php

namespace App\Enums;

enum WorkflowTaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Delegated = 'delegated';
    case Escalated = 'escalated';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * Statuses that still owe somebody a decision.
     *
     * Delegated and Escalated are open: both reassign the SAME task row, so a
     * delegated task is still on somebody's list — it is just on a different
     * somebody's. This is the distinction the old engine got wrong, where
     * delegate wrote a log line and left the task exactly where it was.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::InProgress, self::Delegated, self::Escalated], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_values(array_map(
            fn (self $case) => $case->value,
            array_filter(self::cases(), fn (self $case) => $case->isOpen())
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Delegated => 'Delegated',
            self::Escalated => 'Escalated',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::InProgress => 'amber',
            self::Completed => 'green',
            self::Delegated => 'blue',
            self::Escalated => 'red',
            self::Cancelled, self::Expired => 'slate',
        };
    }
}

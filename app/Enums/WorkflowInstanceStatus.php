<?php

namespace App\Enums;

enum WorkflowInstanceStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Escalated = 'escalated';

    public function isOpen(): bool
    {
        return in_array($this, [self::Active, self::Escalated], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'blue',
            self::Completed => 'green',
            self::Rejected => 'red',
            self::Escalated => 'amber',
            self::Cancelled => 'slate',
        };
    }
}

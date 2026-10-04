<?php

namespace App\Enums\Bcms;

/**
 * `bcms_identity_sync_changes.decision`.
 *
 * `superseded` IS WHAT STOPS THE QUEUE GROWING EVERY FIFTEEN MINUTES (ADR
 * 0018 §2.4). A delta run that re-detects a still-pending change for the same
 * object and kind supersedes the earlier row rather than adding a second —
 * without it, a leaver nobody has reviewed appears ninety-six times a day.
 */
enum SyncChangeDecision: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case AutoApplied = 'auto_applied';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::AutoApplied => 'Auto-applied',
            self::Superseded => 'Superseded',
        };
    }

    /** Applied, one way or another — nothing further to do to it. */
    public function isSettled(): bool
    {
        return in_array($this, [self::Rejected, self::AutoApplied, self::Superseded], true);
    }

    public function isActionable(): bool
    {
        return $this === self::Pending;
    }
}

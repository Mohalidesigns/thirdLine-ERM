<?php

namespace App\Enums\Bcms;

/**
 * What started an identity sync run — `bcms_identity_sync_runs.trigger`.
 */
enum SyncTrigger: string
{
    case ScheduledFull = 'scheduled_full';
    case ScheduledDelta = 'scheduled_delta';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::ScheduledFull => 'Scheduled full reconciliation',
            self::ScheduledDelta => 'Scheduled delta',
            self::Manual => 'Manual sync now',
        };
    }

    public function isDelta(): bool
    {
        return $this === self::ScheduledDelta;
    }
}

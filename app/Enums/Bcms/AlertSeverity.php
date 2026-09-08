<?php

namespace App\Enums\Bcms;

/**
 * Alert severity (`bcms_alerts`, `bcms_alert_templates`).
 *
 * `LifeSafety` is not the top of a severity scale — it is a routing decision.
 * It puts traffic on the `bcms-lifesafety` queue, which is never throttled,
 * never subject to quiet hours and never behind routine reminders (standing
 * rule 6, ADR 0005). Treating it as "critical, but more so" is how a roll-call
 * ends up queued behind five thousand drill reminders.
 */
enum AlertSeverity: string
{
    case Informational = 'informational';
    case Advisory = 'advisory';
    case Urgent = 'urgent';
    case Critical = 'critical';
    case LifeSafety = 'life_safety';

    public function queue(): string
    {
        return $this === self::LifeSafety ? 'bcms-lifesafety' : 'bcms-alerts';
    }

    /** May quiet hours defer this? Never for life safety, and never for critical. */
    public function respectsQuietHours(): bool
    {
        return in_array($this, [self::Informational, self::Advisory], true);
    }
}

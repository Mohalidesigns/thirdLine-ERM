<?php

namespace App\Enums\Bcms;

/**
 * The state of one attempt to reach one person on one channel
 * (`bcms_notification_deliveries`).
 *
 * `Queued` is written BEFORE the provider is called — standing rule 8,
 * persist before you dispatch. A worker that crashes between the write and the
 * provider call leaves a `queued` row the watchdog can find; the reverse
 * leaves an alert nobody knows was lost.
 *
 * `Sent` and `Delivered` are different facts and the distinction is the audit
 * trail an examiner asks for. Sent means a provider accepted it. Delivered
 * means the provider says it reached the handset. Most Nigerian SMS gateways
 * report the first reliably and the second unevenly, so a screen that shows
 * only "delivered" understates reality and one that conflates them overstates
 * it. Both are stored; the screen shows both.
 */
enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Read, self::Failed, self::Expired], true);
    }

    /** Did the provider accept it? Not the same question as whether it arrived. */
    public function wasAccepted(): bool
    {
        return in_array($this, [self::Sent, self::Delivered, self::Read], true);
    }
}

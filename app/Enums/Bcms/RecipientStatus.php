<?php

namespace App\Enums\Bcms;

/**
 * Where one recipient of one alert has got to (`bcms_alert_recipients`).
 *
 * This is a different question from `DeliveryStatus`, which is per channel
 * attempt. A contact reached on WhatsApp and on SMS has two deliveries and one
 * recipient row, and `Acknowledged` is a statement about the person, not about
 * a wire.
 *
 * `Escalated` means the acknowledgement window closed unanswered and the
 * cascade moved to a deputy or supervisor. It is not a failure — it is the
 * escalation working — and the two are counted separately in the scorecard.
 */
enum RecipientStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Acknowledged = 'acknowledged';
    case Failed = 'failed';
    case Escalated = 'escalated';

    public function isAcknowledged(): bool
    {
        return $this === self::Acknowledged;
    }
}

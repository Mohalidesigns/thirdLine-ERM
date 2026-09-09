<?php

namespace App\Enums\Bcms;

/**
 * The CAPA lifecycle (`bcms_corrective_actions`, ISO 22301 10.1).
 *
 * `Completed` and `Verified` are two states on purpose, and the gap between
 * them is the whole point of clause 10.1: the owner says the action is done,
 * and somebody who is not the owner confirms it worked. A register that stops
 * at `Completed` records intentions.
 *
 * `AcceptedRisk` is an explicit, attributable decision not to fix — which an
 * examiner can accept — as opposed to an action that quietly ages past its due
 * date, which they cannot.
 */
enum CorrectiveActionStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Verified = 'verified';
    case Overdue = 'overdue';
    case AcceptedRisk = 'accepted_risk';

    /** Still expects work. Overdue is open — it is late, not closed. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Open, self::InProgress, self::Completed, self::Overdue], true);
    }

    /** Closed in a way clause 10.1 would accept. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Verified, self::AcceptedRisk], true);
    }
}

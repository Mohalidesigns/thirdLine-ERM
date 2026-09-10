<?php

namespace App\Enums\Bcms;

/**
 * What happened at one node in one cascade (`bcms_call_tree_test_nodes.outcome`).
 *
 * THE FAILURES ARE SPLIT BY CAUSE BECAUSE THE CAUSES HAVE DIFFERENT OWNERS.
 * "Forty people were not reached" is a number nobody can act on. A wrong
 * number is HR's, a dead line is telecoms', a timeout is the person's, a
 * withheld consent is the compliance officer's, and a blocked node is nobody's
 * fault at all — it is the consequence of somebody else's failure further up.
 * Blueprint §6.3 asks for a "data quality failures" metric, and it can only be
 * computed if the reason a message did not arrive was recorded when it did not
 * arrive.
 *
 * `blocked` IS THE ONE THAT MAKES THE BROKEN-BRANCH SCREEN POSSIBLE. A node
 * below a failure was never attempted, and recording it as `timeout` would
 * blame thirty-four people for not answering a call that was never placed.
 *
 * `wrong_action` is Blueprint §6.3's response-accuracy metric: somebody
 * acknowledged and then did the wrong thing — went to the wrong assembly point,
 * called the wrong person. It counts as reached, because they were, and it is
 * the only failure on this list that a phone book cannot fix.
 */
enum CascadeOutcome: string
{
    case Pending = 'pending';
    case Reached = 'reached';
    case Deputy = 'deputy';
    case WrongAction = 'wrong_action';
    case Timeout = 'timeout';
    case WrongNumber = 'wrong_number';
    case DeadLine = 'dead_line';
    case MailboxFull = 'mailbox_full';
    case Unrecognised = 'unrecognised';
    case NoChannel = 'no_channel';
    case ConsentBlocked = 'consent_blocked';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting response',
            self::Reached => 'Reached',
            self::Deputy => 'Reached via deputy',
            self::WrongAction => 'Acknowledged, wrong action',
            self::Timeout => 'No response in time',
            self::WrongNumber => 'Wrong number',
            self::DeadLine => 'Dead line',
            self::MailboxFull => 'Mailbox full',
            self::Unrecognised => 'Recipient not recognised',
            self::NoChannel => 'No reachable channel',
            self::ConsentBlocked => 'Excluded — consent withdrawn',
            self::Blocked => 'Never attempted — blocked upstream',
        };
    }

    /** Did a human on the other end actually respond? */
    public function isReached(): bool
    {
        return match ($this) {
            self::Reached, self::Deputy, self::WrongAction => true,
            default => false,
        };
    }

    /** Did the primary answer without the deputy being needed? */
    public function isFirstAttempt(): bool
    {
        return $this === self::Reached || $this === self::WrongAction;
    }

    /**
     * Is this a defect in the contact record rather than in the person?
     *
     * `consent_blocked` is NOT a data-quality failure. The record is correct and
     * the person exercised a right; counting it as bad data would put pressure
     * on somebody to "fix" a withdrawal, which is exactly the pressure the NDPA
     * exists to remove. It is reported on its own line instead.
     */
    public function isDataQualityFailure(): bool
    {
        return match ($this) {
            self::WrongNumber, self::DeadLine, self::MailboxFull,
            self::Unrecognised, self::NoChannel => true,
            default => false,
        };
    }

    /** Is this node finished, or is the cascade still waiting on it? */
    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }

    /** Does a failure here stop everybody below from being contacted? */
    public function blocksDownstream(): bool
    {
        return $this->isSettled() && ! $this->isReached();
    }

    /** The failures a gateway reports, mapped from a delivery's reason. */
    public static function fromFailureReason(?string $reason): self
    {
        $reason = strtolower((string) $reason);

        return match (true) {
            str_contains($reason, 'wrong number'), str_contains($reason, 'invalid number') => self::WrongNumber,
            str_contains($reason, 'unreachable'), str_contains($reason, 'dead') => self::DeadLine,
            str_contains($reason, 'mailbox') => self::MailboxFull,
            str_contains($reason, 'not recognised'), str_contains($reason, 'unknown recipient') => self::Unrecognised,
            default => self::NoChannel,
        };
    }
}

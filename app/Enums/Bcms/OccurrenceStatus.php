<?php

namespace App\Enums\Bcms;

/**
 * The lifecycle of one generated exercise occurrence (Blueprint §9.3).
 *
 * `NeedsScheduling` is the state that makes the calendar engine honest. When
 * frequency-per-year generation cannot place an occurrence — every candidate
 * date falls in a blackout period, or collides with another mandatory exercise
 * for the same team — it does NOT quietly place it anyway and it does NOT drop
 * it. It lands here, visible on the calendar as an unplaced obligation, which
 * is the difference between a plan with a gap and a plan that hides a gap.
 *
 * `Missed` is written by the scheduler, never by a user: an occurrence whose
 * date has passed with no start and no cancellation is missed, and that is a
 * nonconformity under clause 10.1 rather than an absence of data.
 */
enum OccurrenceStatus: string
{
    case Planned = 'planned';
    case NeedsScheduling = 'needs_scheduling';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Missed = 'missed';
    case Deferred = 'deferred';

    /** Statuses that still expect the occurrence to happen, so reminders keep running. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Planned, self::NeedsScheduling, self::Confirmed, self::InProgress], true);
    }

    /** Statuses that count against the programme's completion rate. */
    public function isShortfall(): bool
    {
        return in_array($this, [self::Missed, self::Cancelled], true);
    }
}

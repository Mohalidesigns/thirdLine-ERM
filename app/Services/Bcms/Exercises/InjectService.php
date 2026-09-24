<?php

namespace App\Services\Bcms\Exercises;

use App\Models\Bcms\ExerciseInject;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\User;
use InvalidArgumentException;

/**
 * Releasing scripted injects — Phase 9 delivers IN-APP only.
 *
 * EMNS-CHANNEL DELIVERY IS DELIBERATELY NOT HERE. The prompt marks it
 * `[verify at integration]` at the W11 window, behind the `NotificationChannel`
 * interface so the integration is a configuration change rather than a
 * rewrite. "In-app" needs no adapter (Phase 5 notes: "in-app is not a
 * `NotificationChannel`" — this product has no ninth channel for a database
 * insert) — release is the fact that matters, and it reaches every
 * participant who can see the occurrence through the workspace's own poll.
 *
 * RELEASE IS TIMESTAMPED AND LOGGED (scope §"Injects"). Every release writes
 * both `released_at`/`released_by` on the inject AND an `inject` timeline
 * entry, because the timeline is what the AAR reads and an inject that only
 * updated its own row would not appear in the report of key events.
 */
class InjectService
{
    public function __construct(private TimelineService $timeline) {}

    public function release(ExerciseOccurrence $occurrence, ExerciseInject $inject, User $by): ExerciseInject
    {
        if ((int) $inject->occurrence_id !== (int) $occurrence->getKey()) {
            throw new InvalidArgumentException('This inject does not belong to this occurrence.');
        }

        if ($inject->released_at !== null) {
            throw new InvalidArgumentException('This inject has already been released.');
        }

        $this->timeline->assertNotFrozen($occurrence);

        $inject->update([
            'released_at' => now(),
            'released_by' => $by->getKey(),
        ]);

        $this->timeline->log(
            $occurrence,
            'inject',
            $inject->title.($inject->content !== null ? ' — '.$inject->content : ''),
            $by,
            ['inject_id' => $inject->getKey(), 'ai_generated' => (bool) $inject->ai_generated],
        );

        return $inject->refresh();
    }
}

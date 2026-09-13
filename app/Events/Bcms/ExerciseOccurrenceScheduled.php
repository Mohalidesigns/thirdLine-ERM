<?php

namespace App\Events\Bcms;

use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseOccurrence;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An occurrence has been given a date.
 *
 * THE CROSS-TRACK SEAM OF ORCHESTRATION §5. Phase 5 arms the T-10 reminder
 * ladder off this and nothing else — not off a model observer, not by the
 * generator calling a reminder service, because Track B's engine must not know
 * that Track C exists. When Phase 5 lands, it registers a listener and the
 * generator does not change.
 *
 * IT CARRIES THE DEFINITION AS WELL AS THE OCCURRENCE. Everything the ladder
 * needs to materialise itself — `lead_time_days`, `daily_reminder_enabled`,
 * `reminder_mode`, `unannounced`, `default_audience_rule`, `default_channel_set`
 * — is on the definition, and a listener that had to re-load it would be one
 * lazy-load away from an N+1 across a whole year's generation.
 *
 * IT FIRES ONLY ON A DATE THAT CHANGED. Regenerating a definition whose
 * occurrences land on the same days must not re-arm anything, or Gate G1's
 * "a re-run sends nothing twice" fails at the source rather than at the
 * dispatcher.
 */
class ExerciseOccurrenceScheduled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ExerciseOccurrence $occurrence,
        public ExerciseDefinition $definition,
    ) {}
}

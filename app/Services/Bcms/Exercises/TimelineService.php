<?php

namespace App\Services\Bcms\Exercises;

use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\TimelineEntry;
use App\Models\User;
use InvalidArgumentException;

/**
 * The exercise timeline — the spine of the AAR (clause map §1.2 row 3).
 *
 * FROZEN ONCE THE AAR IS FINAL. Not by a trigger — refused here, in the
 * service that owns the write, exactly as ADR 0019 §2 specifies for every
 * artefact this phase freezes. A finalised occurrence's timeline is read-only
 * from every route in the product, this one included.
 */
class TimelineService
{
    /** @param array<string, mixed> $metadata */
    public function log(
        ExerciseOccurrence $occurrence,
        string $entryType,
        ?string $content,
        ?User $by = null,
        array $metadata = [],
        ?\Illuminate\Support\Carbon $loggedAt = null,
    ): TimelineEntry {
        $this->assertNotFrozen($occurrence);

        return TimelineEntry::query()->create([
            'organization_id' => $occurrence->organization_id,
            'occurrence_id' => $occurrence->getKey(),
            'logged_at' => $loggedAt ?? now(),
            'logged_by' => $by?->getKey(),
            'entry_type' => $entryType,
            'content' => $content,
            'metadata' => $metadata,
        ]);
    }

    public function assertNotFrozen(ExerciseOccurrence $occurrence): void
    {
        $aar = $occurrence->aar;

        if ($aar !== null && $aar->status === 'final') {
            throw new InvalidArgumentException(
                'This exercise\'s timeline is frozen — its after-action report is final. Reopen the report '
                .'to make a correction.'
            );
        }
    }
}

<?php

namespace App\Services\Bcms\Exercises;

use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\ExerciseInject;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

    /**
     * Author a scripted inject on an occurrence (gap 2). Refused once the
     * occurrence's AAR is final for the same reason the timeline itself
     * freezes then (`TimelineService::assertNotFrozen()`) — a script written
     * onto a report that already went out would let the record of what was
     * planned drift from what the report describes.
     *
     * ALWAYS APPENDED. `sequence` is `unique(occurrence_id, sequence)`, and a
     * caller-supplied position would mean shifting every later row (each
     * shift its own `BcmsAuditable` row) just to insert one. `reorder()`
     * places it afterwards. The `max()+1` read is made race-safe (A4) by
     * locking the occurrence row for the duration of the read-then-write,
     * inside a transaction — two facilitators scripting the same occurrence
     * at once previously could both read the same max and collide on the
     * unique index, one of them getting a 500 instead of the next number.
     *
     * NO `User $by` PARAMETER. `ExerciseInject` is `BcmsAuditable`, which
     * reads the actor from `auth()->user()` on the model event itself — a
     * `$by` argument here would go unused, the defect A4 flagged.
     *
     * @param  array{title: string, content?: ?string, release_offset_minutes?: ?int, delivery_channel?: ?string}  $data
     */
    public function create(ExerciseOccurrence $occurrence, array $data): ExerciseInject
    {
        $this->timeline->assertNotFrozen($occurrence);

        return DB::transaction(function () use ($occurrence, $data): ExerciseInject {
            ExerciseOccurrence::query()->whereKey($occurrence->getKey())->lockForUpdate()->first();

            $sequence = 1 + (int) ExerciseInject::query()
                ->where('occurrence_id', $occurrence->getKey())
                ->max('sequence');

            return ExerciseInject::query()->create([
                'organization_id' => $occurrence->organization_id,
                'occurrence_id' => $occurrence->getKey(),
                'sequence' => $sequence,
                'release_offset_minutes' => $data['release_offset_minutes'] ?? 0,
                'title' => $data['title'],
                'content' => $data['content'] ?? null,
                'delivery_channel' => $data['delivery_channel'] ?? null,
                'ai_generated' => false,
            ]);
        });
    }

    /**
     * Edit an inject that has not yet gone out. Once released, its content is
     * what the timeline says happened — editing it afterwards would let the
     * script disagree with the record without either one saying so.
     *
     * @param  array{title?: string, content?: ?string, release_offset_minutes?: ?int, delivery_channel?: ?string}  $data
     */
    public function update(ExerciseInject $inject, array $data): ExerciseInject
    {
        $occurrence = $inject->occurrence;

        if ($occurrence !== null) {
            $this->timeline->assertNotFrozen($occurrence);
        }

        if ($inject->released_at !== null) {
            throw new InvalidArgumentException(
                'This inject has already been released and is part of the exercise record — it cannot be edited.'
            );
        }

        $updates = array_intersect_key(
            $data,
            array_flip(['title', 'content', 'release_offset_minutes', 'delivery_channel']),
        );

        // D2: the column is NOT NULL. `create()` already treats a missing/
        // null offset as "immediate" (offset 0) — an explicit `null` here
        // means the same thing, not "leave it unset", so it is coerced the
        // same way rather than written straight through into a write that
        // MariaDB refuses with a 500 (`SQLSTATE[23000] 1048`).
        if (array_key_exists('release_offset_minutes', $updates) && $updates['release_offset_minutes'] === null) {
            $updates['release_offset_minutes'] = 0;
        }

        $inject->update($updates);

        return $inject->refresh();
    }

    /** Remove an inject that has not yet gone out — the same rule as `update()`. */
    public function delete(ExerciseInject $inject): void
    {
        $occurrence = $inject->occurrence;

        if ($occurrence !== null) {
            $this->timeline->assertNotFrozen($occurrence);
        }

        if ($inject->released_at !== null) {
            throw new InvalidArgumentException(
                'This inject has already been released and is part of the exercise record — it cannot be deleted.'
            );
        }

        $inject->delete();
    }

    /**
     * Reorder an occurrence's injects. The payload must name exactly the
     * occurrence's current set — not a subset, and nothing from elsewhere —
     * checked against the database rather than trusted from the caller, the
     * same discipline standard §4 asks of a bare `exists:`.
     *
     * A5: refused once the occurrence is `completed` or `cancelled` — there
     * is no facilitation happening any more for the order to serve. And
     * refused if it would move a RELEASED inject's RANK: a released
     * inject already happened at its place in the script, the timeline
     * entry it wrote references it by that position, and the AAR reads the
     * script as history rather than as a still-editable plan.
     *
     * Code review defect 1: RANK, never the stored `sequence` column.
     * `delete()` leaves gaps in `sequence` (author 1/2/3, release 3, delete
     * 1 — the survivors are stored as 2 and 3, not renumbered), and the
     * previous check compared a released inject's raw `sequence` against
     * its new 1-based position, which drifts apart the moment any gap
     * exists — and never recovers: every later reorder of that occurrence
     * refused, including the UNCHANGED order. Rank is the released inject's
     * 1-based position among the occurrence's CURRENT injects ordered by
     * `sequence`, recomputed fresh on every call — correct regardless of
     * what the stored numbers happen to be, which raw `sequence` can never
     * be once anything has ever been deleted. `delete()` is deliberately
     * NOT changed to close the gap instead: doing that safely needs the
     * same two-pass write this method already uses to stay clear of
     * MariaDB's unspecified per-row order inside one bulk `UPDATE` against
     * a unique index (below), which is a second locked, multi-row
     * renumbering path added for a purely cosmetic property — rank-based
     * checking is already correct with the gap left in place, so closing it
     * would buy no correctness and only add a second place for that same
     * class of bug to recur.
     *
     * A-v: the occurrence is locked for the whole read-then-write, inside
     * one transaction — the same pattern `create()` uses — so a concurrent
     * `create()`/`delete()` cannot land between the existence/rank check
     * and the write.
     *
     * @param  list<int>  $injectIds  in the wanted order
     */
    public function reorder(ExerciseOccurrence $occurrence, array $injectIds, User $by): void
    {
        $this->timeline->assertNotFrozen($occurrence);

        if (in_array($occurrence->status, [OccurrenceStatus::Completed, OccurrenceStatus::Cancelled], true)) {
            throw new InvalidArgumentException(
                "This occurrence is {$occurrence->status->value} — its injects can no longer be reordered."
            );
        }

        DB::transaction(function () use ($occurrence, $injectIds, $by): void {
            ExerciseOccurrence::query()->whereKey($occurrence->getKey())->lockForUpdate()->first();

            $existing = ExerciseInject::query()
                ->where('occurrence_id', $occurrence->getKey())
                ->orderBy('sequence')
                ->get(['id', 'sequence', 'released_at', 'title']);

            $current = $existing->pluck('id')->sort()->values()->all();
            $wanted = collect($injectIds)->sort()->values()->all();

            if ($current !== $wanted) {
                throw new InvalidArgumentException(
                    'The reorder list has to name exactly this occurrence\'s injects — no more, no fewer.'
                );
            }

            $rankById = [];

            foreach ($existing->values() as $index => $inj) {
                $rankById[$inj->getKey()] = $index + 1;
            }

            foreach ($injectIds as $i => $id) {
                $inject = $existing->firstWhere('id', $id);

                if ($inject?->released_at !== null && $rankById[$id] !== $i + 1) {
                    throw new InvalidArgumentException(
                        "\"{$inject->title}\" has already been released — its position in the sequence is part of "
                        .'the exercise record and cannot change.'
                    );
                }
            }

            // Two passes around the `unique(occurrence_id, sequence)` index:
            // push every row out of the 1..N range first, then place them in
            // the wanted order, so no intermediate write collides with a row
            // that has not moved yet.
            $offset = count($injectIds) + 1000;

            foreach ($injectIds as $i => $id) {
                ExerciseInject::query()
                    ->where('occurrence_id', $occurrence->getKey())
                    ->where('id', $id)
                    ->update(['sequence' => $offset + $i]);
            }

            foreach ($injectIds as $i => $id) {
                ExerciseInject::query()
                    ->where('occurrence_id', $occurrence->getKey())
                    ->where('id', $id)
                    ->update(['sequence' => $i + 1]);
            }

            // The query-builder updates above bypass Eloquent events by
            // design (§ above) — this is the one row that says the order
            // changed and who changed it.
            $occurrence->recordAudit('injects_reordered', ['by' => $by->name, 'order' => $injectIds]);
        });
    }

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

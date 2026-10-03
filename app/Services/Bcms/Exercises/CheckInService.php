<?php

namespace App\Services\Bcms\Exercises;

use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseParticipant;
use App\Models\User;

/**
 * QR / SMS / manual / geo attendance check-in at an assembly point.
 *
 * THE TOKEN IS PER-PARTICIPANT AND FOLLOWS ADR 0016's SHAPE EXACTLY
 * (`CascadeEngine::tokenFor()`/`tagFor()`): `chk-{participant id}-{16 hex
 * HMAC tag}`, matched case-insensitively, compared with `hash_equals` so it
 * cannot be recovered a character at a time. THE SHORT CODE IS THE SAME TAG,
 * TRUNCATED: no new column is added to hold one — the frozen schema has
 * nowhere to store it — so it is derived, not stored, exactly like the QR
 * token it is a shorter spelling of. A marshal's tablet or a participant's
 * own SMS reply both resolve to the SAME participant row this way.
 *
 * THIS PHASE'S CHECK-IN IS ALWAYS PARTICIPANT-SPECIFIC (an assumption
 * recorded in the phase notes). The schema has no free-text "name" column on
 * `bcms_exercise_participants` to hold an unregistered walk-in's identity, so
 * an attendee with no personal code uses the facilitator's own manual
 * check-in on the workspace screen rather than an anonymous QR path.
 *
 * 200 CONCURRENT CHECK-INS WITHOUT CONTENTION (criterion 3): each check-in is
 * a single indexed `UPDATE ... WHERE id = ?` on a DIFFERENT participant row.
 * There is no shared counter and no table lock to contend on — two hundred
 * people scanning at once are two hundred independent single-row writes.
 */
class CheckInService
{
    /**
     * Advisory 3 (Gate 2 review #3): this used to be unanchored and
     * case-insensitive (`\b...\b`, `/i`), so `CHK-1-<tag>`,
     * `x.chk-1-<tag>` and `chk-1-<tag>-junk` all resolved to the SAME
     * participant while counting against DIFFERENT `bcms-check-in-token`
     * limiter keys (the route's raw `{token}` path segment, verbatim) —
     * decorating a token was a free way to sidestep its own per-token
     * rate limit. Anchored (`^...$`) and case-sensitive: `tagFor()` only
     * ever emits lowercase hex, so a genuine token never needed the `/i`
     * flag, and nothing else in this class parses a token out of a
     * larger string (unlike `CascadeEngine`/`InboundResponseHandler`,
     * which read a token out of an inbound SMS BODY and need the `\b`
     * word-boundary shape for that reason — check-in has no inbound SMS
     * parser at all; the short-code path below is a distinct mechanism
     * with its own, already-anchored pattern).
     */
    private const TOKEN_PATTERN = '/^chk-(\d{1,12})-([0-9a-f]{16})\z/';

    private const SHORT_CODE_LENGTH = 8;

    /** A participant id that names no real row (ids are auto-increment from 1). */
    private const SENTINEL_ID = 0;

    /**
     * Gate 2 defect 4: a hard cap on how many candidates one short-code
     * lookup will ever scan. The short code cannot be indexed directly — it
     * is derived, not stored (class docblock) — so this bound, not an index,
     * is what stops the query's cost from growing without limit as the
     * number of tenants running a concurrent exercise grows. Hitting it means
     * the product needs a wider code space or a real index, not a slower
     * request; it is not expected to bind in ordinary use.
     */
    private const MAX_SHORT_CODE_CANDIDATES = 5000;

    public function __construct(private TimelineService $timeline) {}

    public function tokenFor(ExerciseParticipant $participant): string
    {
        $id = (int) $participant->getKey();

        return 'chk-'.$id.'-'.$this->tagFor($id);
    }

    public function shortCodeFor(ExerciseParticipant $participant): string
    {
        return strtoupper(substr($this->tagFor((int) $participant->getKey()), 0, self::SHORT_CODE_LENGTH));
    }

    private function tagFor(int $id): string
    {
        return substr(hash_hmac('sha256', 'bcms-checkin-'.$id, (string) config('app.key')), 0, 16);
    }

    /**
     * Resolve a long QR token — constant-cost on the not-found path (ADR
     * 0016 §3): the tag is always computed and compared, whether or not a
     * matching row exists.
     */
    public function participantForToken(string $token): ?ExerciseParticipant
    {
        if (preg_match(self::TOKEN_PATTERN, $token, $m) !== 1) {
            return null;
        }

        return $this->matchById((int) $m[1], strtolower($m[2]));
    }

    /**
     * Resolve a short, human-typed code by scanning participants on
     * currently in-progress occurrences.
     *
     * DELIBERATELY BOUNDED TO IN-PROGRESS OCCURRENCES ONLY: a short code is
     * eight hex characters, short enough that scanning every participant
     * that ever existed would be both slow and a guessing surface. Bounding
     * the search to occurrences that are actually running keeps the set
     * small — a handful of exercises company-wide at any one moment — and
     * makes an offline exercise's codes stop resolving the moment it ends,
     * which is the same "closed" behaviour the long-token path gives for
     * free from `nodeForToken()`'s pattern in the call-tree console.
     *
     * GATE 2 DEFECT 4 — BOUNDED, NOT UNBOUNDED. This used to run
     * `ExerciseOccurrence::pluck('id')` as a separate round trip (materialising
     * every in-progress occurrence id into a PHP array first) and then
     * `->get()` every column of every candidate participant, tenant boundary
     * included, on EVERY attempt against an unauthenticated route with no
     * rate limit of its own. It is now one query, an indexed `whereIn(...,
     * $subquery)` rather than a separately-fetched id list, selecting only
     * `id` — the one column the comparison needs — capped at
     * `MAX_SHORT_CODE_CANDIDATES`. The matching row itself is fetched once,
     * after the tag comparison has already identified it.
     */
    public function participantForShortCode(string $code): ?ExerciseParticipant
    {
        $code = strtoupper(trim($code));

        if ($code === '' || ! preg_match('/^[0-9A-F]{'.self::SHORT_CODE_LENGTH.'}$/', $code)) {
            return null;
        }

        $candidateIds = ExerciseParticipant::query()
            ->select('id')
            ->whereIn('occurrence_id', ExerciseOccurrence::query()
                ->where('status', OccurrenceStatus::InProgress->value)
                ->select('id'))
            ->limit(self::MAX_SHORT_CODE_CANDIDATES)
            ->pluck('id');

        foreach ($candidateIds as $id) {
            $candidateShortCode = strtoupper(substr($this->tagFor((int) $id), 0, self::SHORT_CODE_LENGTH));

            if (hash_equals($candidateShortCode, $code)) {
                return ExerciseParticipant::query()->find($id);
            }
        }

        return null;
    }

    private function matchById(int $id, string $presentedTag): ?ExerciseParticipant
    {
        $row = ExerciseParticipant::query()
            ->whereKey($id)
            ->whereHas('occurrence', fn ($q) => $q->where('status', OccurrenceStatus::InProgress->value))
            ->first();

        $expectedTag = $this->tagFor($row !== null ? (int) $row->getKey() : self::SENTINEL_ID);

        return $row !== null && hash_equals($expectedTag, $presentedTag) ? $row : null;
    }

    /**
     * Record the check-in. Idempotent: a second scan of an already-checked-in
     * participant is a no-op that still reports success, matching the
     * product-wide "a second tap is not an error" rule.
     *
     * FROZEN ONCE THE AAR IS FINAL, same rule and same mechanism as
     * `TimelineService::assertNotFrozen()` (Gate 2 defect 2) — reused rather
     * than duplicated, so a completed occurrence's attendance cannot be
     * altered by a facilitator's manual check-in, a stale QR link or a
     * short-code retry once the after-action report has signed off on it.
     *
     * EVERY CHECK-IN IS AUDITED, WITH ACTOR AND METHOD. `$recordedBy` used to
     * be accepted and silently discarded — `ExerciseParticipant` carried no
     * `BcmsAuditable` at all, so a facilitator's manual check-in on somebody
     * else's behalf left no record of who did it. `ExerciseParticipant` now
     * uses `BcmsAuditable` (the model change), which writes the automatic
     * before/after on `checked_in_at`/`check_in_method`/`attendance_status`;
     * this explicit `recordAudit()` call adds what that diff cannot carry —
     * who recorded it, distinct from the auth actor on an unauthenticated
     * QR/SMS check-in, where there is no signed-in user for the trait's own
     * `auth()->user()` to find.
     */
    public function checkIn(ExerciseParticipant $participant, string $method, ?User $recordedBy = null): ExerciseParticipant
    {
        $occurrence = $participant->occurrence;

        if ($occurrence !== null) {
            $this->timeline->assertNotFrozen($occurrence);
        }

        if ($participant->checked_in_at !== null) {
            return $participant;
        }

        $participant->update([
            'checked_in_at' => now(),
            'check_in_method' => $method,
            'attendance_status' => 'present',
        ]);

        $participant->recordAudit('participant_checked_in', [
            'method' => $method,
            'recorded_by' => $recordedBy !== null ? $recordedBy->name : 'self',
        ]);

        return $participant->refresh();
    }
}

<?php

namespace App\Services\Bcms\Incidents;

use App\Enums\Bcms\IncidentLogEntryType;
use App\Enums\Bcms\NotificationKind;
use App\Enums\Bcms\NotificationRegulator;
use App\Models\Bcms\Incident;
use App\Models\Bcms\IncidentNotification;
use App\Models\User;
use App\Support\Bcms\IncidentClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * `bcms_incident_notifications` — ADR 0020 §2 in full, Amendment 2 included.
 * This class is the one place that ADR is enforced: the row IS the
 * classification, `due_at` is stored at awareness and never recomputed, and
 * nothing here transmits anything to a regulator — `bcms.incident.notify`'s
 * own catalogue description says so, and it is repeated here as a rule this
 * class must not appear to contradict.
 *
 * TWO CLOCKS, NEVER MERGED (clause map §2.1's closing note). A CBN obligation
 * and an NDPC obligation on the same incident are two separate rows with two
 * separate `awareness_at`/`due_at` pairs — there is no shared "regulator
 * notified" state to collapse them into.
 *
 * BOTH CLOCKS DEFAULT TO `detected_at`, FALLING BACK TO `declared_at` —
 * NEVER `now()` (Amendment 2). `now()` starts the clock when somebody
 * clicked, hours after the fact on a busy night; `declared_at` is a moment
 * the bank controls and a default that let it buy time by delaying a
 * decision is the error to design out. If neither timestamp exists,
 * classification is refused outright — a clock with an invented start is
 * worse than a prompt.
 */
class NotificationService
{
    /**
     * Classify an obligation — creating the `initial` row for a regulator,
     * or returning the one that already exists. This is the act ADR 0020 §2
     * point 1 calls "the personal-data-breach flag": there is no boolean,
     * only this row.
     *
     * Amendment 2 rule 4: a WITHDRAWN initial row is never returned as "the
     * one that already exists" — re-classifying after a withdrawal opens a
     * new `initial` row at the next sequence, defaulting its awareness to
     * the withdrawn row's own awareness (the bank was aware of the facts
     * from the first classification; moving it later is rule 6's case, not
     * a default).
     *
     * Amendment 2 rule 6: setting awareness EARLIER than the default needs
     * no justification. Setting it LATER requires `$laterAwarenessReason` —
     * refused otherwise — and is written to the decision log, because that
     * is the direction that buys the bank time.
     */
    public function classify(
        Incident $incident,
        User $by,
        string $regulator,
        ?Carbon $awarenessAt = null,
        ?string $laterAwarenessReason = null,
    ): IncidentNotification {
        $reg = NotificationRegulator::from($regulator);

        $existing = IncidentNotification::query()
            ->where('incident_id', $incident->getKey())
            ->where('regulator', $reg->value)
            ->where('kind', NotificationKind::Initial->value)
            ->whereNull('withdrawn_at')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $priorWithdrawn = IncidentNotification::query()
            ->where('incident_id', $incident->getKey())
            ->where('regulator', $reg->value)
            ->where('kind', NotificationKind::Initial->value)
            ->whereNotNull('withdrawn_at')
            ->orderByDesc('sequence')
            ->first();

        $default = $priorWithdrawn->awareness_at ?? $this->defaultAwarenessAt($incident);
        // Gate 1 re-gate defect 1: normalised again here, at the write
        // boundary itself — every production caller already normalises via
        // `IncidentClock::utc()` before reaching this method, but a Carbon
        // instance built some other way (a service call, a seeder, a
        // tinker session) must not be able to reach `create()` below still
        // carrying a foreign offset, which Eloquent's cast would otherwise
        // store as local wall time in a column with no offset of its own.
        $awarenessAt = IncidentClock::utc($awarenessAt) ?? $default;

        if ($awarenessAt->gt($default) && blank($laterAwarenessReason)) {
            throw new InvalidArgumentException(
                'Setting the awareness time later than the incident\'s detection (or its earlier '
                .'withdrawn classification) requires a reason — record why this obligation was not '
                .'known until then.'
            );
        }

        $windowHours = $reg->windowHours();
        $sequence = $priorWithdrawn !== null ? $priorWithdrawn->sequence + 1 : 1;

        return DB::transaction(function () use ($incident, $by, $reg, $awarenessAt, $default, $windowHours, $sequence, $laterAwarenessReason) {
            $row = IncidentNotification::query()->create([
                'organization_id' => $incident->organization_id,
                'incident_id' => $incident->getKey(),
                'regulator' => $reg->value,
                'basis_clause_ref' => $reg->basisClauseRef()->value,
                'kind' => NotificationKind::Initial->value,
                'sequence' => $sequence,
                'awareness_at' => $awarenessAt,
                'due_at' => $windowHours === null ? null : $awarenessAt->copy()->addHours($windowHours),
                'created_by' => $by->getKey(),
            ]);

            $incident->forceFill(['is_reportable' => true])->save();

            if ($awarenessAt->gt($default)) {
                app(IncidentService::class)->log($incident, $by, [
                    'entry_type' => IncidentLogEntryType::Decision->value,
                    'content' => sprintf(
                        'Awareness for the %s obligation recorded later than the default — %s.',
                        $reg->label(),
                        $awarenessAt->toDayDateTimeString(),
                    ),
                    'options_considered' => 'Reviewed when the obligation became known against the incident\'s '
                        .'detected/declared timestamps (or its earlier withdrawn classification).',
                    'rationale' => $laterAwarenessReason,
                ]);
            }

            return $row;
        });
    }

    /**
     * Record a submission — a factual write, never a transmission
     * (`incident-notification-log.md` §4).
     *
     * Amendment 2: a second "initial" submission is refused once the row's
     * `submitted_at` is already set — re-recording would overwrite the
     * evidence of when the regulator was first told and what they were told.
     * A correction or an addition is a `supplementary` row instead.
     *
     * Gate 1 re-gate defect 2: a FOLLOW-UP (`intermediate`/`final`/
     * `supplementary`) NEVER classifies implicitly — only `kind: initial`
     * still does, which is how the "Classify a new obligation" control on
     * this screen lands on one row rather than two. Before this, recording a
     * follow-up against a WITHDRAWN obligation silently reopened a brand-new
     * `initial` row (the next sequence, often already overdue, with no
     * decision-log entry of its own — precisely the record `withdraw()`
     * exists to create), and a `final` could be recorded while the
     * `initial` itself was still unsubmitted, which is not a real
     * regulator interaction under any reading of "final".
     *
     * Every write here `lockForUpdate()`s the obligation row(s) it reads
     * inside its own transaction, closing the race between a concurrent
     * submit and a concurrent withdraw (or two concurrent follow-ups) both
     * passing their own in-memory check before either commits.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordSubmission(Incident $incident, User $by, array $data): IncidentNotification
    {
        $reg = NotificationRegulator::from($data['regulator']);
        $kind = NotificationKind::from($data['kind']);

        if ($kind === NotificationKind::Initial) {
            $initial = $this->classify(
                $incident,
                $by,
                $reg->value,
                isset($data['awareness_at']) ? IncidentClock::utc($data['awareness_at']) : null,
                $data['awareness_reason'] ?? null,
            );

            return DB::transaction(function () use ($initial, $by, $data) {
                /** @var IncidentNotification $initial */
                $initial = IncidentNotification::query()->whereKey($initial->getKey())->lockForUpdate()->firstOrFail();

                if ($initial->submitted_at !== null) {
                    throw new InvalidArgumentException(
                        'This obligation\'s initial notification has already been submitted on '
                        .$initial->submitted_at->toDayDateTimeString().' and cannot be overwritten — '
                        .'record a supplementary submission for a correction or an addition.'
                    );
                }

                if ($initial->withdrawn_at !== null) {
                    throw new InvalidArgumentException(
                        'This obligation has been withdrawn and cannot be submitted — reclassify it first.'
                    );
                }

                $initial->update([
                    'submitted_at' => now(),
                    'submitted_by' => $by->getKey(),
                    'reference' => $data['reference'] ?? null,
                    'content_snapshot' => $data['content_snapshot'] ?? null,
                    'updated_by' => $by->getKey(),
                ]);

                return $initial->refresh();
            });
        }

        return DB::transaction(function () use ($incident, $by, $reg, $kind, $data) {
            // Code review #3 probe 1: a withdraw-then-reclassify (Amendment 2
            // rule 4) leaves TWO `initial` rows for this regulator — the
            // withdrawn one and the live one the reclassify opened, always
            // at a strictly higher `sequence` (`classify()` sets it to
            // `priorWithdrawn->sequence + 1`). With no ordering at all this
            // picked whichever row the database happened to return first,
            // not necessarily the live one, and every follow-up was refused
            // telling the officer to do what they had already done.
            // `orderByDesc('sequence')` always lands on the live row when
            // one exists — a withdrawal never precedes its own reclassify's
            // sequence number.
            $initial = IncidentNotification::query()
                ->where('incident_id', $incident->getKey())
                ->where('regulator', $reg->value)
                ->where('kind', NotificationKind::Initial->value)
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();

            if ($initial === null) {
                throw new InvalidArgumentException(
                    "No initial {$reg->label()} notification has been classified for this incident yet — "
                    .'classify and submit the initial notification before recording a follow-up.'
                );
            }

            if ($initial->withdrawn_at !== null) {
                throw new InvalidArgumentException(
                    "The {$reg->label()} obligation was withdrawn — reclassify it before recording a follow-up."
                );
            }

            if ($initial->submitted_at === null) {
                throw new InvalidArgumentException(
                    "The initial {$reg->label()} notification has not been submitted yet — record it before "
                    .'a '.$kind->label().'.'
                );
            }

            $sequence = 1;

            if ($kind->repeats()) {
                $sequence = 1 + (int) IncidentNotification::query()
                    ->where('incident_id', $incident->getKey())
                    ->where('regulator', $reg->value)
                    ->where('kind', $kind->value)
                    ->lockForUpdate()
                    ->max('sequence');
            } elseif (IncidentNotification::query()
                ->where('incident_id', $incident->getKey())
                ->where('regulator', $reg->value)
                ->where('kind', $kind->value)
                ->lockForUpdate()
                ->exists()) {
                throw new InvalidArgumentException("A {$kind->label()} has already been recorded for this obligation.");
            }

            return IncidentNotification::query()->create([
                'organization_id' => $incident->organization_id,
                'incident_id' => $incident->getKey(),
                'regulator' => $reg->value,
                'basis_clause_ref' => $reg->basisClauseRef()->value,
                'kind' => $kind->value,
                'sequence' => $sequence,
                'awareness_at' => $initial->awareness_at,
                'due_at' => null,
                'submitted_at' => now(),
                'submitted_by' => $by->getKey(),
                'reference' => $data['reference'] ?? null,
                'content_snapshot' => $data['content_snapshot'] ?? null,
                'created_by' => $by->getKey(),
            ]);
        });
    }

    /**
     * Reassess an obligation as not, after all, reportable — the decision-log
     * entry the stand-down gate looks for (clause map's closure rule,
     * `incident-stand-down.md` condition 2).
     *
     * Amendment 2: if a live (unsubmitted, not already withdrawn) obligation
     * row exists for this regulator, "not reportable" WITHDRAWS it — a
     * decision-log entry alone left the row open for ever, so the stand-down
     * gate could never pass and the only way out was recording a submission
     * that never happened. If no row exists yet, this is the original
     * decision-log-only path: nothing was ever opened to withdraw.
     *
     * `$question` stays `cbn`/`personal_data` (the reportability question),
     * not the `NotificationRegulator` value — every existing caller
     * (`IncidentController`, `IncidentNotificationController`,
     * `IncidentService::standDownChecklist()`) already passes the question,
     * and `reportabilityStatus()`'s own decision-log lookup keys on it.
     */
    public function reassessNotReportable(Incident $incident, User $by, string $question, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reassessing an obligation as not reportable must give a reason.');
        }

        $open = $this->liveObligation($incident, $question);

        if ($open !== null) {
            $this->withdraw($open, $by, $reason, $question);

            return;
        }

        $regulator = $this->regulatorForQuestion($question);

        // Advisory A10 (Gate 1 re-gate): a SUBMITTED notification cannot be
        // walked back by a decision-log entry alone — the regulator has
        // already been told, and "not reportable after all" said now would
        // contradict the record of what they were told, not correct a row
        // that was never sent. `liveObligation()` above already excludes a
        // submitted row (it is not "live"), so without this check the code
        // would silently fall through to writing a contradictory entry.
        $hasSubmitted = IncidentNotification::query()
            ->where('incident_id', $incident->getKey())
            ->where('regulator', $regulator->value)
            ->whereNotNull('submitted_at')
            ->exists();

        if ($hasSubmitted) {
            throw new InvalidArgumentException(
                "A {$regulator->label()} notification has already been submitted for this incident — "
                .'reassessing it as not reportable cannot be recorded as a decision-log entry alone. '
                .'Record a supplementary or final submission if the position has genuinely changed.'
            );
        }

        app(IncidentService::class)->log($incident, $by, [
            'entry_type' => IncidentLogEntryType::Decision->value,
            'content' => sprintf('Reassessed: %s reportability — not reportable.', strtoupper($question)),
            'options_considered' => 'Reassessed against the facts now known against the reportability criteria.',
            'rationale' => $reason,
            'attachments' => ['reportability_question' => $question, 'answer' => 'no'],
        ]);
    }

    /**
     * Whether answering "no" to this reportability question would WITHDRAW a
     * live obligation, rather than only write a decision-log entry.
     *
     * ADR 0020 Amendment 2 rule 2: withdrawal requires `bcms.incident.notify`
     * — the same authority that records a submission, and a higher one than
     * `.manage` (`RiskPermissionCatalog` gives `.manage` to the base
     * risk-manager role and deliberately withholds `.notify` for the CRO
     * alone). The "no answer, nothing open yet" path stays on `.manage`
     * alone: it only records a decision, the same authority declaring and
     * regrading an incident already needs.
     */
    public function hasLiveObligation(Incident $incident, string $question): bool
    {
        return $this->liveObligation($incident, $question) !== null;
    }

    private function liveObligation(Incident $incident, string $question): ?IncidentNotification
    {
        $regulator = $this->regulatorForQuestion($question);

        return IncidentNotification::query()
            ->where('incident_id', $incident->getKey())
            ->where('regulator', $regulator->value)
            ->whereNull('submitted_at')
            ->whereNull('withdrawn_at')
            ->first();
    }

    /**
     * Withdraw an open obligation (Amendment 2). Only an UNSUBMITTED row can
     * be withdrawn — once the regulator has been told, it cannot be untold;
     * a later conclusion that the incident was not reportable after all is
     * itself a `supplementary`/`final` submission saying so, never a quiet
     * withdrawal. The withdrawal and its decision-log entry are written in
     * one transaction, and the entry is the withdrawal's one recorded reason
     * (no separate free-text column — ADR 0020 Amendment 2's own argument
     * against a second, possibly disagreeing, answer to "why").
     */
    public function withdraw(IncidentNotification $row, User $by, string $reason, ?string $question = null): IncidentNotification
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Withdrawing an obligation must give a reason.');
        }

        return DB::transaction(function () use ($row, $by, $reason, $question) {
            // Gate 1 re-gate defect 2's race: re-fetch under lock INSIDE the
            // transaction before checking state — the caller's `$row` may be
            // a stale in-memory copy a concurrent `recordSubmission()` (or a
            // second concurrent `withdraw()`) has since changed underneath.
            /** @var IncidentNotification $row */
            $row = IncidentNotification::query()->whereKey($row->getKey())->lockForUpdate()->firstOrFail();

            if ($row->submitted_at !== null) {
                throw new InvalidArgumentException(
                    'This obligation has already been submitted to the regulator and cannot be withdrawn — '
                    .'record a supplementary or final submission instead.'
                );
            }

            if ($row->withdrawn_at !== null) {
                throw new InvalidArgumentException('This obligation has already been withdrawn.');
            }

            // The FK is NOT NULL; the `?? findOrFail()` fallback exists only
            // so this stays `Incident`, not `?Incident`, without relying on
            // the caller having eager-loaded the relation.
            $incident = $row->incident ?? Incident::query()->findOrFail($row->incident_id);

            $entry = app(IncidentService::class)->log($incident, $by, [
                'entry_type' => IncidentLogEntryType::Decision->value,
                'content' => sprintf(
                    'Reassessed: %s reportability — not reportable; the open obligation is withdrawn.',
                    $row->regulator->label(),
                ),
                'options_considered' => 'Reassessed against the facts now known against the reportability criteria.',
                'rationale' => $reason,
                'attachments' => $question === null ? null : ['reportability_question' => $question, 'answer' => 'no'],
            ]);

            $row->update([
                'withdrawn_at' => now(),
                'withdrawn_by' => $by->getKey(),
                'withdrawal_entry_id' => $entry->getKey(),
                'updated_by' => $by->getKey(),
            ]);

            // Amendment 2 rule 5: the NAMED audit event, alongside
            // `IncidentNotification`'s own `BcmsAuditable` column diff — the
            // generic "updated" event that diff writes is not the event this
            // rule names, and an examiner searching the audit log for
            // exactly this event must find it under its own name.
            $row->recordAudit('incident.notification_withdrawn', [
                'regulator' => $row->regulator->value,
                'basis_clause_ref' => $row->basis_clause_ref,
                'withdrawal_entry_id' => $entry->getKey(),
            ]);

            return $row->refresh();
        });
    }

    /**
     * yes | no | unknown — never re-derived from a boolean, always from
     * stored rows (Amendment 2 rule 3): a live row → yes; only withdrawn
     * rows → no; else the existing decision-log check; else unknown.
     */
    public function reportabilityStatus(Incident $incident, string $question): string
    {
        $regulator = $this->regulatorForQuestion($question);

        $rows = IncidentNotification::query()
            ->where('incident_id', $incident->getKey())
            ->where('regulator', $regulator->value)
            ->get(['id', 'withdrawn_at']);

        if ($rows->isEmpty()) {
            $hasNoDecision = $incident->entries()
                ->whereJsonContains('attachments->reportability_question', $question)
                ->whereJsonContains('attachments->answer', 'no')
                ->exists();

            return $hasNoDecision ? 'no' : 'unknown';
        }

        return $rows->contains(fn (IncidentNotification $n) => $n->withdrawn_at === null) ? 'yes' : 'no';
    }

    /**
     * @return Builder<IncidentNotification>
     */
    public function overdueQuery(): Builder
    {
        return IncidentNotification::query()
            ->whereNull('submitted_at')
            ->whereNull('withdrawn_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            // Advisory A8 (Gate 1 re-gate): a drill's simulated obligation
            // must never wake anyone the way a real one does — the same
            // "is_exercise never pollutes a live aggregate" rule (clause map
            // §6.11) every other Phase 10 aggregate already follows.
            ->whereHas('incident', fn (Builder $q) => $q->where('is_exercise', false));
    }

    /**
     * @return Builder<IncidentNotification>
     */
    public function approachingDueQuery(): Builder
    {
        $hours = (int) config('bcms.incident.notification_approaching_hours', 6);

        return IncidentNotification::query()
            ->whereNull('submitted_at')
            ->whereNull('withdrawn_at')
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [now(), now()->addHours($hours)])
            ->whereHas('incident', fn (Builder $q) => $q->where('is_exercise', false));
    }

    /** Open obligations for one incident — the stand-down gate's own count. */
    public function overdueOrOpenCount(Incident $incident): int
    {
        return IncidentNotification::query()
            ->where('incident_id', $incident->getKey())
            ->whereNull('submitted_at')
            ->whereNull('withdrawn_at')
            ->count();
    }

    /**
     * Amendment 2's default: `detected_at`, falling back to `declared_at`,
     * never `now()`. Refused outright if neither exists — a clock with an
     * invented start is worse than a prompt asking to record detection.
     */
    private function defaultAwarenessAt(Incident $incident): Carbon
    {
        $default = $incident->detected_at ?? $incident->declared_at;

        if ($default === null) {
            throw new InvalidArgumentException(
                'This incident has no recorded detection or declaration time — record when it was '
                .'detected before classifying a regulatory obligation against it.'
            );
        }

        return $default;
    }

    private function regulatorForQuestion(string $question): NotificationRegulator
    {
        return $question === 'personal_data' ? NotificationRegulator::Ndpc : NotificationRegulator::Cbn;
    }
}

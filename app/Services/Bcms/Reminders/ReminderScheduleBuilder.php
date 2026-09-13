<?php

namespace App\Services\Bcms\Reminders;

use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ReminderSchedule;
use App\Services\Bcms\BcmsSettings;
use App\Support\Bcms\AudienceRule;
use App\Support\Bcms\ReminderLadder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Materialising the ladder: one row per intended send, written when the
 * occurrence gets a date.
 *
 * ADR 0005 IS THE ARGUMENT AND IT IS WORTH RE-READING. Computing due reminders
 * on each hourly tick is less storage and is wrong: nothing records that a send
 * was skipped, a rescheduled occurrence cannot be reconciled against what was
 * already sent, and "a re-run sends nothing twice" becomes a property of the
 * query rather than of the data.
 *
 * IT IS ALSO A PRODUCT FEATURE. A BC officer can open an exercise and see
 * *"here are the fourteen alerts this will send, to these forty-six people, on
 * these days, through these channels"* before a single one has gone out. That
 * inspectability is the thing that makes a customer trust the countdown, and it
 * is only possible because the rows exist in advance.
 *
 * RESCHEDULING VOIDS AND REWRITES; IT NEVER MUTATES. Moving an exercise from
 * twenty days out to five must produce a correct compressed ladder, not ten
 * sends that are suddenly in the past. Unsent rows become `voided` — kept, so
 * the audit shows what was planned as well as what happened — and a new ladder
 * is written. Rows already `sent` are left exactly alone: they happened.
 */
class ReminderScheduleBuilder
{
    public function __construct(private readonly BcmsSettings $settings) {}

    /**
     * Write (or rewrite) the ladder for one occurrence.
     *
     * @return array{created: int, voided: int, kept: int, skipped_reason: ?string}
     */
    public function build(ExerciseOccurrence $occurrence, ?ExerciseDefinition $definition = null): array
    {
        $definition ??= $occurrence->definition;

        if ($definition === null) {
            return ['created' => 0, 'voided' => 0, 'kept' => 0, 'skipped_reason' => 'The occurrence has no definition.'];
        }

        if ($occurrence->scheduled_date === null) {
            // An unplaced occurrence has nothing to count down to. Its ladder
            // is written when somebody gives it a date — `needs_scheduling` is
            // deliberately visible on the calendar, and a countdown to nothing
            // would be worse than none.
            $voided = $this->voidUnsent($occurrence, 'The exercise no longer has a date.');

            return ['created' => 0, 'voided' => $voided, 'kept' => 0,
                'skipped_reason' => 'The exercise has no date yet, so there is nothing to count down to.'];
        }

        $rungs = ReminderLadder::forDefinition(
            leadTimeDays: max(1, (int) $definition->lead_time_days),
            dailyReminders: (bool) $definition->daily_reminder_enabled,
            unannounced: (bool) $definition->unannounced,
        );

        return DB::transaction(function () use ($occurrence, $definition, $rungs) {
            $voided = $this->voidUnsent($occurrence, 'Superseded by a regenerated ladder.');

            $created = 0;
            $kept = 0;

            $now = Carbon::now();

            foreach ($rungs as $rung) {
                $sendAt = $this->sendAtFor($occurrence, $rung);

                $key = $this->idempotencyKey($occurrence, $definition, $rung);

                // A RUNG WHOSE DAY HAS PASSED IS DROPPED, NOT FIRED LATE.
                // This is what "a compressed ladder, not ten missed sends"
                // means: moving an exercise from twenty days out to five leaves
                // the T-10 rung five days in the past, and creating it as
                // pending would send "ten days to go" about something five days
                // away on the next tick — ten times over.
                //
                // THE LINE IS THE START OF TODAY, NOT THIS MOMENT. A ladder
                // built at 09:00 for an exercise ten days out has its 07:30
                // notice an hour and a half in the past, and that notice is
                // genuinely due — it should go out on the next tick rather than
                // never. A rung whose whole day has gone is the one that is
                // obsolete.
                //
                // Post-exercise rungs (the AAR chasers) are exempt: they are
                // meant to fire after the date, and a past `send_at` on one of
                // them means it is due.
                if ($rung['offset'] < 0 && $sendAt->lt($now->copy()->startOfDay())) {
                    if ($existingPast = ReminderSchedule::query()->where('idempotency_key', $key)->first()) {
                        if ($existingPast->status !== 'sent') {
                            $existingPast->update([
                                'status' => 'voided',
                                'send_at' => $sendAt,
                                'skip_reason' => 'The exercise moved inside this rung\'s lead time, so this alert '
                                    .'is no longer ahead of it.',
                            ]);
                        }
                    }

                    continue;
                }

                // The unique index is the guard, not a check-then-insert: two
                // workers racing on the same regeneration must lose one insert,
                // not both succeed (ADR 0005).
                $existing = ReminderSchedule::query()->where('idempotency_key', $key)->first();

                if ($existing !== null) {
                    if ($existing->status === 'sent') {
                        // It already went out. Leaving it alone is the whole
                        // point — a regeneration must not re-send history.
                        $kept++;

                        continue;
                    }

                    $existing->update([
                        'send_at' => $sendAt,
                        'status' => 'pending',
                        'skip_reason' => null,
                    ]);

                    $kept++;

                    continue;
                }

                ReminderSchedule::query()->create([
                    'organization_id' => $occurrence->organization_id,
                    'occurrence_id' => $occurrence->getKey(),
                    'send_at' => $sendAt,
                    'day_offset' => $rung['offset'],
                    'audience_rule' => $this->audienceFor($definition, $rung),
                    'channel_set' => $this->channelsFor($definition, $rung),
                    'template_key' => $rung['template_key'],
                    'mode' => $rung['mode'],
                    'status' => 'pending',
                    'idempotency_key' => $key,
                ]);

                $created++;
            }

            if ($created > 0 || $voided > 0) {
                $occurrence->recordAudit('reminder_ladder_rebuilt', [
                    'created' => $created,
                    'voided' => $voided,
                    'kept' => $kept,
                    'scheduled_date' => $occurrence->scheduled_date?->toDateString(),
                ]);
            }

            return ['created' => $created, 'voided' => $voided, 'kept' => $kept, 'skipped_reason' => null];
        });
    }

    /**
     * The inspectable plan: what this exercise will send, to how many people.
     *
     * @return array<string, mixed>
     */
    public function plan(ExerciseOccurrence $occurrence, ReminderAudienceResolver $audience): array
    {
        $rows = ReminderSchedule::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->orderBy('send_at')
            ->orderBy('day_offset')
            ->get();

        $entries = [];
        $totalRecipients = 0;

        foreach ($rows as $row) {
            $rung = ReminderLadder::findTemplate((string) $row->template_key);
            $recipients = $row->status === 'pending'
                ? $audience->countFor($occurrence, $row)
                : (int) ($row->recipient_count ?? 0);

            if ($row->status === 'pending') {
                $totalRecipients += $recipients;
            }

            $entries[] = [
                'id' => $row->getKey(),
                'day_offset' => (int) $row->day_offset,
                'label' => $rung['label'] ?? $row->template_key,
                'purpose' => $rung['purpose'] ?? null,
                'send_at' => $row->send_at?->toIso8601String(),
                'send_at_local' => $row->send_at
                    ?->copy()->setTimezone($this->settings->timezone($occurrence->organization_id))
                    ->format('D j M, H:i'),
                'audience' => $row->audience_rule['selector'] ?? null,
                'channels' => $row->channel_set ?? [],
                'mode' => $row->mode,
                'status' => $row->status,
                'recipient_count' => $recipients,
                'dispatched_at' => $row->dispatched_at?->toIso8601String(),
                'skip_reason' => $row->skip_reason,
            ];
        }

        return [
            'entries' => $entries,
            'total_sends' => count($entries),
            'pending' => $rows->where('status', 'pending')->count(),
            'sent' => $rows->where('status', 'sent')->count(),
            'voided' => $rows->where('status', 'voided')->count(),
            // The headline sentence: "14 alerts, 46 people". Null rather than
            // zero when there is no ladder at all, so an occurrence with no
            // date reads as "not planned yet" rather than "plans to send
            // nothing".
            'total_recipients' => $entries === [] ? null : $totalRecipients,
        ];
    }

    /* ------------------------------------------------------------------ */

    private function voidUnsent(ExerciseOccurrence $occurrence, string $reason): int
    {
        return ReminderSchedule::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->where('status', 'pending')
            ->update([
                // Voided, not deleted. The audit trail shows what was planned
                // as well as what happened (ADR 0005).
                'status' => 'voided',
                'skip_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    private function sendAtFor(ExerciseOccurrence $occurrence, array $rung): Carbon
    {
        $organizationId = (int) $occurrence->organization_id;

        // The go-live rung is measured from the exercise's START TIME rather
        // than from its date, because "sixty minutes before" is the point of it.
        if (isset($rung['minutes_before'])) {
            $start = $occurrence->scheduled_start;

            // A START TIME ON A DIFFERENT DAY FROM THE DATE IS STALE and is
            // ignored. A reschedule moves both, but anything that moved only
            // the date would otherwise leave the go-live alert pointing at the
            // old day — an alert that fires a fortnight after the exercise it
            // is announcing.
            if ($start === null || $start->toDateString() !== $occurrence->scheduled_date->toDateString()) {
                $start = $occurrence->scheduled_date->copy()->setTime(9, 0);
            }

            return Carbon::parse($start)->subMinutes((int) $rung['minutes_before']);
        }

        $day = $occurrence->scheduled_date->copy()->addDays((int) $rung['offset']);

        // The tenant's local send time, converted to UTC. Materialising a
        // ladder without this is how a 07:30 reminder arrives at 08:30.
        return Carbon::parse($this->settings->sendAtOn($day, $organizationId));
    }

    /**
     * @return array<string, mixed>
     */
    private function audienceFor(ExerciseDefinition $definition, array $rung): array
    {
        // The SELECTOR is what the dispatcher resolves — "participants with
        // open tasks" is a question about state, not a static list, and cannot
        // be an `AudienceRule` (ADR 0003's grammar targets org nodes, sites and
        // roles). The definition's own rule is carried alongside it as the
        // population that selector narrows.
        $base = AudienceRule::fromJson($definition->default_audience_rule);

        return array_filter([
            'selector' => $rung['audience'],
            'rule' => $base?->toArray(),
        ], fn ($v) => $v !== null);
    }

    /**
     * @return list<string>
     */
    private function channelsFor(ExerciseDefinition $definition, array $rung): array
    {
        $override = $definition->default_channel_set;

        if (! is_array($override) || $override === []) {
            return $rung['channels'];
        }

        // A definition's channel set NARROWS the rung's rather than replacing
        // it. A tenant that has not signed for WhatsApp should not start
        // receiving SMS on the in-app-only rungs, and a rung that says "email
        // and in-app" means both are wanted — the override says which of them
        // this tenant can actually use.
        $narrowed = array_values(array_intersect($rung['channels'], $override));

        // Except: never narrow to nothing. A rung with no channel is a send
        // that silently does not happen, which is failure mode (a).
        return $narrowed === [] ? $rung['channels'] : $narrowed;
    }

    /**
     * ADR 0005's formula, exactly.
     *
     * `sha1(occurrence|offset|audience|template|mode)`. It is per PLANNED SEND,
     * not per recipient — the per-person, per-channel uniqueness lives on the
     * delivery rows, because one planned send legitimately becomes forty-six
     * deliveries.
     */
    private function idempotencyKey(ExerciseOccurrence $occurrence, ExerciseDefinition $definition, array $rung): string
    {
        $audience = json_encode($this->audienceFor($definition, $rung));

        return sha1(implode('|', [
            $occurrence->getKey(),
            $rung['offset'],
            sha1((string) $audience),
            $rung['template_key'],
            $rung['mode'],
        ]));
    }
}

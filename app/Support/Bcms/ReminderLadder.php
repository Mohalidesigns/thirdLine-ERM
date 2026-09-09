<?php

namespace App\Support\Bcms;

use App\Enums\Bcms\ChannelKey;

/**
 * The T-10 countdown, declared once — Blueprint §5.4.
 *
 * THIS TABLE *IS* THE PRODUCT'S SIGNATURE FEATURE. No competitor benchmarked in
 * Blueprint §3.2 has a T-minus daily countdown, and it is the thing that changes
 * how a BC team works day to day. It lives in code rather than in a table
 * because the ladder's SHAPE is the product's opinion — which audiences, on
 * which days, through which channels — and a tenant that could rearrange it
 * would be buying a worse product. What a tenant configures is the parameters:
 * `lead_time_days`, `reminder_send_time`, `daily_reminder_enabled`, quiet hours,
 * the channel set. Not the rungs.
 *
 * TWO FAILURE MODES END THIS FEATURE, and every row below is shaped by one of
 * them:
 *
 *   (a) AN ALERT THAT SHOULD HAVE FIRED AND DID NOT. A compliance failure for
 *       the customer, discovered by an examiner. This is why every row is
 *       MATERIALISED (ADR 0005) rather than computed at dispatch: a row that
 *       was never sent is a row somebody can find.
 *
 *   (b) ALERT FATIGUE. Staff filter BCMS mail to junk, and the rhythm the
 *       module exists to create dies. This is why the T-9…T-4 band is
 *       `digest` — one message per person per DAY, consolidating every exercise
 *       they are in — and why only four rungs are `discrete`.
 *
 * `discrete` VERSUS `digest` IS THE WHOLE OF THE FATIGUE GUARD. A discrete rung
 * is important enough to arrive on its own: the formal notice, the attendance
 * request, the final brief, the go-live. Everything else is a line in one daily
 * digest. A person in five overlapping exercises gets one email, not five.
 */
class ReminderLadder
{
    /**
     * The in-app bell, which is NOT a `NotificationChannel`.
     *
     * `ChannelKey` is frozen at G0 (ADR 0004) as the eight channels that need a
     * gateway, and in-app needs none: this product already has
     * `notifications_log` and a bell in the shell, and the dispatcher writes to
     * it directly. Adding a ninth enum case would have meant a mock adapter, a
     * real adapter and a provider for something that is a database insert —
     * and would have changed a frozen contract to model the absence of a
     * gateway.
     */
    public const CHANNEL_IN_APP = 'in_app';

    /** Audience selectors the dispatcher knows how to resolve. */
    public const AUDIENCE_ALL = 'all_participants';

    public const AUDIENCE_OPEN_TASKS = 'participants_with_open_tasks';

    public const AUDIENCE_COMPLETE = 'participants_all_complete';

    public const AUDIENCE_OWNERS = 'owners_and_managers';

    public const AUDIENCE_FACILITATORS = 'facilitators_and_observers';

    public const AUDIENCE_CAPA_OWNERS = 'capa_owners';

    /**
     * The ladder, in the order it fires.
     *
     * `offset` is signed days from the exercise date: -10 is ten days before,
     * +14 is a fortnight after. `minutes_before` on the go-live rung is the one
     * rung measured from the exercise's START TIME rather than from its date,
     * because "sixty minutes before" is the point of it.
     *
     * @return list<array{
     *     offset: int, audience: string, template_key: string, mode: string,
     *     channels: list<string>, discrete: bool, label: string, purpose: string,
     *     minutes_before?: int, requires_daily_reminders?: bool
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'offset' => -10,
                'audience' => self::AUDIENCE_ALL,
                'template_key' => 'exercise.notice',
                'mode' => 'discrete',
                'channels' => [ChannelKey::Email->value, self::CHANNEL_IN_APP],
                'discrete' => true,
                'label' => 'Formal notice',
                'purpose' => 'The exercise, its objectives, its scope, the date and time, who is doing what, and '
                    .'the readiness checklist each person now owns.',
            ],
            // T-9 … T-4: the daily band. Two rows per day would be two
            // messages; instead each day has ONE digest row whose content
            // depends on whether the recipient still owes anything.
            [
                'offset' => -9,
                'audience' => self::AUDIENCE_OPEN_TASKS,
                'template_key' => 'exercise.countdown',
                'mode' => 'digest',
                'channels' => [self::CHANNEL_IN_APP, ChannelKey::Email->value],
                'discrete' => false,
                'requires_daily_reminders' => true,
                'label' => 'Daily countdown',
                'purpose' => '"3 of 7 readiness items outstanding — 6 days to the Fire Drill (Head Office)."',
            ],
            // The other half of the daily band. DISJOINT AUDIENCES: somebody
            // either owes something or does not, so nobody gets both — and the
            // digest consolidation merges them anyway when a person is behind
            // on one exercise and clear on another.
            [
                'offset' => -9,
                'audience' => self::AUDIENCE_COMPLETE,
                'template_key' => 'exercise.countdown',
                'mode' => 'digest',
                'channels' => [self::CHANNEL_IN_APP],
                'discrete' => false,
                'requires_daily_reminders' => true,
                'label' => 'Daily countdown (nothing outstanding)',
                'purpose' => 'In-app only, and one line. Somebody who owes nothing does not need an email every '
                    .'morning to be told so — that is the message that teaches them to filter the next one.',
            ],
            [
                'offset' => -3,
                'audience' => self::AUDIENCE_ALL,
                'template_key' => 'exercise.confirm_attendance',
                'mode' => 'discrete',
                'channels' => [ChannelKey::Email->value, ChannelKey::Sms->value, self::CHANNEL_IN_APP],
                'discrete' => true,
                'label' => 'Attendance confirmation',
                'purpose' => 'Confirm or decline. A decline has to nominate a deputy — an exercise nobody attends '
                    .'is not evidence of anything.',
            ],
            [
                'offset' => -2,
                'audience' => self::AUDIENCE_OWNERS,
                'template_key' => 'exercise.escalation',
                'mode' => 'discrete',
                'channels' => [ChannelKey::Email->value, ChannelKey::Sms->value],
                'discrete' => true,
                'label' => 'Escalation',
                'purpose' => 'Blocking readiness items are still open. Goes to the line manager and the programme '
                    .'owner, not to the person who is late — they have already had six reminders.',
            ],
            [
                'offset' => -1,
                'audience' => self::AUDIENCE_ALL,
                'template_key' => 'exercise.final_brief',
                'mode' => 'discrete',
                'channels' => [ChannelKey::Email->value, ChannelKey::Sms->value, ChannelKey::WhatsApp->value],
                'discrete' => true,
                'label' => 'Final brief',
                'purpose' => 'Logistics, assembly points, safety notes, what good looks like, and the '
                    .'"this is an exercise" disclaimer.',
            ],
            [
                'offset' => 0,
                'minutes_before' => 60,
                'audience' => self::AUDIENCE_ALL,
                'template_key' => 'exercise.go_live',
                'mode' => 'discrete',
                'channels' => [ChannelKey::Sms->value, ChannelKey::Push->value],
                'discrete' => true,
                'label' => 'Go live in one hour',
                'purpose' => 'Measured from the exercise start time rather than the date — sixty minutes before is '
                    .'the point of it.',
            ],
            [
                'offset' => 0,
                'audience' => self::AUDIENCE_FACILITATORS,
                'template_key' => 'exercise.workspace_open',
                'mode' => 'discrete',
                'channels' => [self::CHANNEL_IN_APP],
                'discrete' => true,
                'label' => 'Execution workspace open',
                'purpose' => 'Phase 9 opens the workspace from here.',
            ],
            [
                'offset' => 1,
                'audience' => self::AUDIENCE_FACILITATORS,
                'template_key' => 'exercise.aar_due',
                'mode' => 'discrete',
                'channels' => [ChannelKey::Email->value, self::CHANNEL_IN_APP],
                'discrete' => true,
                'label' => 'After-action report due',
                'purpose' => 'The day after. An AAR written a fortnight later is a memory, not a record.',
            ],
            [
                'offset' => 3,
                'audience' => self::AUDIENCE_FACILITATORS,
                'template_key' => 'exercise.aar_overdue',
                'mode' => 'digest',
                'channels' => [ChannelKey::Email->value, self::CHANNEL_IN_APP],
                'discrete' => false,
                'label' => 'AAR overdue',
                'purpose' => 'First chase, to the facilitator.',
            ],
            [
                'offset' => 7,
                'audience' => self::AUDIENCE_OWNERS,
                'template_key' => 'exercise.aar_escalation',
                'mode' => 'discrete',
                'channels' => [ChannelKey::Email->value],
                'discrete' => true,
                'label' => 'AAR overdue — escalated',
                'purpose' => 'A week late is the programme owner\'s problem, not the facilitator\'s.',
            ],
            [
                'offset' => 14,
                'audience' => self::AUDIENCE_CAPA_OWNERS,
                'template_key' => 'exercise.capa_due',
                'mode' => 'digest',
                'channels' => [self::CHANNEL_IN_APP, ChannelKey::Email->value],
                'discrete' => false,
                'label' => 'Corrective actions due',
                'purpose' => 'The exercise is worth what its corrective actions close.',
            ],
        ];
    }

    /**
     * The ladder for one definition, expanded across its lead time.
     *
     * THE DAILY BAND STRETCHES OR COMPRESSES WITH `lead_time_days`. A ten-day
     * lead gives T-9 through T-4; a five-day lead gives T-4 through T-4, and a
     * two-day lead gives none at all, which is correct — there is no room for a
     * countdown, and inventing one would send two reminders in a day.
     *
     * AN UNANNOUNCED EXERCISE KEEPS ITS FACILITATOR RUNGS AND LOSES EVERY
     * PARTICIPANT ONE. The point of a surprise call-tree test is that nobody
     * knows; the facilitator still needs their readiness ladder, and the AAR
     * chasers still have to happen afterwards.
     *
     * @return list<array<string, mixed>>
     */
    public static function forDefinition(int $leadTimeDays, bool $dailyReminders, bool $unannounced): array
    {
        $rungs = [];

        foreach (self::all() as $rung) {
            // The daily band is one declared row that becomes many.
            if (($rung['requires_daily_reminders'] ?? false)) {
                if (! $dailyReminders) {
                    continue;
                }

                // From the day after the formal notice down to T-4, bounded by
                // the tenant's own lead time.
                for ($offset = min(-4, -($leadTimeDays - 1)); $offset <= -4; $offset++) {
                    if ($offset <= -$leadTimeDays) {
                        continue;
                    }

                    $rungs[] = ['offset' => $offset] + $rung;
                }

                continue;
            }

            // The formal notice sits at the start of the lead time, wherever
            // the tenant has put it.
            if ($rung['offset'] === -10) {
                $rungs[] = ['offset' => -max(1, $leadTimeDays)] + $rung;

                continue;
            }

            $rungs[] = $rung;
        }

        if ($unannounced) {
            $rungs = array_values(array_filter(
                $rungs,
                fn (array $r) => in_array($r['audience'], [
                    self::AUDIENCE_FACILITATORS,
                    self::AUDIENCE_OWNERS,
                    self::AUDIENCE_CAPA_OWNERS,
                ], true),
            ));
        }

        // Rungs inside the lead time only. A T-3 rung on a two-day lead would
        // be materialised in the past and dispatched immediately, which is how
        // a "reminder" becomes a message about something that already happened.
        $rungs = array_values(array_filter(
            $rungs,
            fn (array $r) => $r['offset'] >= 0 || $r['offset'] >= -max(1, $leadTimeDays),
        ));

        usort($rungs, fn (array $a, array $b) => [$a['offset'], $b['discrete']] <=> [$b['offset'], $a['discrete']]);

        return $rungs;
    }

    /** @return array<string, mixed>|null */
    public static function findTemplate(string $key): ?array
    {
        foreach (self::all() as $rung) {
            if ($rung['template_key'] === $key) {
                return $rung;
            }
        }

        return null;
    }
}

<?php

namespace App\Services\Bcms\Emns;

use App\Enums\Bcms\DeliveryStatus;
use App\Enums\Bcms\RecipientStatus;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\NotificationDelivery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Is everybody out?" — the screen a crisis manager stands in front of.
 *
 * THE ONLY NUMBER THAT MATTERS IS THE UNACCOUNTED-FOR ONE. Delivery funnels are
 * for the after-action report; during an evacuation there is exactly one
 * question, which is who is still inside. Everything here is arranged so that
 * number is the largest thing on the screen and is never flattering.
 *
 * UNRESPONSIVE IS NOT THE SAME AS UNREACHABLE, and conflating them is the
 * failure this service exists to avoid. Somebody whose phone rang and who has
 * not answered may be carrying a colleague down a stairwell. Somebody whose
 * number was wrong was never called at all. Both are unaccounted for, they need
 * different people to act, and a single "no response" bucket sends the fire
 * warden to the wrong floor.
 *
 * THE DENOMINATOR IS FIXED AT DISPATCH. `bcms_alert_recipients` is materialised
 * before the first message goes out, so a headcount cannot drift as the contact
 * roster changes underneath it. A denominator that moved while people were
 * being counted is not a headcount.
 *
 * FIRST ANSWER WINS, EXCEPT THAT HELP ALWAYS WINS (owner ruling, 2026-10-16).
 * Somebody who replies "I'm safe" and then loses signal stays safe, and a later
 * automated status must not quietly un-account for them; so a non-help reply
 * after a status changes nothing. But the two possible errors are not
 * symmetric. A false "safe" stops somebody looking for a person who is trapped;
 * a false "needs help" costs a warden a walk. So:
 *
 *  - a plain acknowledgement ("received") carries no status and never closes
 *    the recipient: a later reply that names a status is still recorded;
 *  - a later NEEDS HELP supersedes safe, not-on-site or no status at all, and
 *    the change is audited with the value it replaced, so the evidence trail
 *    shows both answers;
 *  - a later safe / not-on-site NEVER clears needs-help when it arrives from the
 *    person (SMS, WhatsApp, USSD). Only an authorised operator on the board
 *    can, through the in-app respond route (`$operator = true`).
 */
class RollCallService
{
    /** The three answers Blueprint §7.3 asks for, plus the absence of one. */
    public const SAFE = 'safe';

    public const NEEDS_HELP = 'needs_help';

    public const NOT_ON_SITE = 'not_on_site';

    /**
     * @return array<string, mixed>
     */
    public function summary(Alert $alert): array
    {
        /** @var Collection<int, AlertRecipient> $recipients */
        $recipients = AlertRecipient::query()
            ->where('alert_id', $alert->getKey())
            ->with(['contact:id,full_name,business_unit_id,site_id,mobile_primary,manager_user_id',
                'contact.businessUnit:id,name', 'contact.site:id,name'])
            ->get();

        $total = $recipients->count();

        $safe = $recipients->filter(fn (AlertRecipient $r) => $r->response_value === self::SAFE);
        $needsHelp = $recipients->filter(fn (AlertRecipient $r) => $r->response_value === self::NEEDS_HELP);
        $offSite = $recipients->filter(fn (AlertRecipient $r) => $r->response_value === self::NOT_ON_SITE);
        $answered = $recipients->filter(fn (AlertRecipient $r) => $r->acknowledged_at !== null);

        $unreachable = $recipients->filter(
            fn (AlertRecipient $r) => $r->status === RecipientStatus::Failed
        );
        $silent = $recipients->filter(
            fn (AlertRecipient $r) => $r->acknowledged_at === null && $r->status !== RecipientStatus::Failed
        );

        return [
            'total' => $total,
            'safe' => $safe->count(),
            'needs_help' => $needsHelp->count(),
            'not_on_site' => $offSite->count(),
            'acknowledged' => $answered->count(),
            // The two halves of "we do not know", kept apart on purpose.
            'silent' => $silent->count(),
            'unreachable' => $unreachable->count(),
            'unaccounted_for' => $silent->count() + $unreachable->count(),
            'response_rate' => $total === 0 ? null : round($answered->count() / $total * 100, 1),
            'escalated' => $recipients->filter(fn (AlertRecipient $r) => $r->escalated_at !== null)->count(),

            // NEEDS HELP IS A LIST, NOT A COUNT. It is the only category where
            // somebody has to be sent to a named person right now.
            'help_needed' => $needsHelp->map(fn (AlertRecipient $r) => $this->person($r, 'needs_help'))->values()->all(),
            'unaccounted' => $silent->merge($unreachable)
                ->sortBy(fn (AlertRecipient $r) => $this->unitName($r))
                ->map(fn (AlertRecipient $r) => $this->person(
                    $r,
                    $r->status === RecipientStatus::Failed ? 'unreachable' : 'silent',
                ))->values()->all(),

            'by_department' => $this->group($recipients, fn (AlertRecipient $r) => $this->unitName($r)),
            'by_site' => $this->group($recipients, fn (AlertRecipient $r) => $this->siteName($r)),
            'funnel' => $this->funnel($alert, $recipients),
            'started_at' => $alert->dispatched_at?->toIso8601String(),
            // The number an evacuation drill is scored on, and the reason the
            // exercise engine integrates with this at all.
            'headcount_minutes' => $this->headcountMinutes($alert, $answered),
        ];
    }

    /**
     * Record somebody's answer.
     *
     * `$value` is one of the three above, or null for a plain acknowledgement —
     * a person who tapped "received" without saying whether they are safe is
     * accounted for as having answered and NOT as safe, because those are
     * different facts and only one of them means somebody can stop looking.
     */
    public function record(
        AlertRecipient $recipient,
        ?string $value = null,
        ?string $text = null,
        ?string $via = null,
        bool $operator = false,
    ): AlertRecipient {
        /*
         * DECIDED AND WRITTEN ON THE LOCKED ROW, NEVER ON THE CALLER'S COPY.
         * Two replies in flight (SAFE and HELP) each loaded the recipient
         * before either wrote; deciding from that stale copy let SAFE land
         * last and clear needs_help, and left an audit trail of two "first
         * answers". The row is re-read FOR UPDATE inside the transaction, so
         * the second writer sees what the first wrote and the D1 rules apply
         * to the truth. The audit is written from the locked row after commit.
         *
         * `withoutGlobalScopes()`: the key comes from a model the caller has
         * already resolved (token, route binding), and an ambient tenant that
         * differs from the row's must not make the row vanish mid-write.
         */
        $outcome = DB::transaction(function () use ($recipient, $value, $text, $operator): ?array {
            $locked = AlertRecipient::withoutGlobalScopes()
                ->whereKey($recipient->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            $previous = $locked->response_value;
            $answered = $locked->acknowledged_at !== null;

            if ($answered) {
                $supersedes = match (true) {
                    // An operator on the board is the only way out of needs_help.
                    $operator && $previous === self::NEEDS_HELP => $value !== null && $value !== self::NEEDS_HELP,
                    // Help always wins, but only once: help over help is a no-op.
                    $value === self::NEEDS_HELP => $previous !== self::NEEDS_HELP,
                    // A status-less acknowledgement does not close the recipient.
                    $previous === null => $value !== null,
                    // First answer wins.
                    default => false,
                };

                if (! $supersedes) {
                    return ['row' => $locked, 'changed' => false];
                }
            }

            $locked->forceFill([
                'acknowledged_at' => $answered ? $locked->acknowledged_at : now(),
                'status' => RecipientStatus::Acknowledged->value,
                'response_value' => $value,
                // An operator clearing a flag must not wipe the person's own
                // words ("HELP trapped 3rd floor"); an inbound help replaces
                // the text, because the new words are what matter.
                'response_text' => $operator ? ($text ?? $locked->response_text) : $text,
            ])->save();

            return ['row' => $locked, 'changed' => true, 'answered' => $answered, 'previous' => $previous];
        });

        if ($outcome === null) {
            return $recipient;
        }

        /** @var AlertRecipient $row */
        $row = $outcome['row'];

        if ($outcome['changed']) {
            $answered = $outcome['answered'];

            $row->alert?->recordAudit('alert.response', array_filter([
                'contact' => $row->contact_name_snapshot,
                'response' => $value ?? 'acknowledged',
                'via' => $via,
                'previous_response' => $answered ? ($outcome['previous'] ?? 'acknowledged') : null,
                'superseded' => $answered ? true : null,
            ], fn ($v) => $v !== null));
        }

        // The caller's instance is stale by construction; bring it up to date.
        $recipient->setRawAttributes($row->getAttributes(), true);

        return $recipient;
    }

    /**
     * A contact's department and site, as labels.
     *
     * Locals rather than a nullsafe chain: the relation is genuinely nullable
     * at runtime — a contact with no unit is ordinary — and typed non-null by
     * static analysis, which makes `?->x ?? y` read as redundant to the next
     * person.
     */
    private function unitName(AlertRecipient $recipient): string
    {
        $contact = $recipient->contact;
        $unit = $contact === null ? null : $contact->businessUnit;

        return $unit === null ? 'No department' : (string) $unit->name;
    }

    private function siteName(AlertRecipient $recipient): string
    {
        $contact = $recipient->contact;
        $site = $contact === null ? null : $contact->site;

        return $site === null ? 'No site' : (string) $site->name;
    }

    /**
     * @param  Collection<int, AlertRecipient>  $recipients
     * @return list<array<string, mixed>>
     */
    private function group(Collection $recipients, callable $key): array
    {
        $out = [];

        foreach ($recipients->groupBy($key) as $name => $group) {
            $safe = $group->filter(fn (AlertRecipient $r) => $r->response_value === self::SAFE)->count();
            $help = $group->filter(fn (AlertRecipient $r) => $r->response_value === self::NEEDS_HELP)->count();
            $answered = $group->filter(fn (AlertRecipient $r) => $r->acknowledged_at !== null)->count();

            $out[] = [
                'name' => (string) $name,
                'total' => $group->count(),
                'safe' => $safe,
                'needs_help' => $help,
                'acknowledged' => $answered,
                'unaccounted_for' => $group->count() - $answered,
                'response_rate' => $group->count() === 0 ? null : round($answered / $group->count() * 100, 1),
            ];
        }

        usort($out, fn (array $a, array $b) => $b['unaccounted_for'] <=> $a['unaccounted_for']);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function person(AlertRecipient $recipient, string $state): array
    {
        $contact = $recipient->contact;
        $unit = $contact === null ? null : $contact->businessUnit;
        $site = $contact === null ? null : $contact->site;

        return [
            'recipient_id' => (int) $recipient->getKey(),
            'name' => $recipient->contact_name_snapshot,
            'department' => $unit?->name,
            'site' => $site?->name,
            'mobile' => $contact?->mobile_primary,
            'state' => $state,
            'response_text' => $recipient->response_text,
            'escalated_at' => $recipient->escalated_at?->toIso8601String(),
            'reason' => $state === 'unreachable'
                ? 'No usable channel — this contact record needs fixing.'
                : ($state === 'needs_help' ? 'Asked for help.' : 'Alert delivered; no response yet.'),
        ];
    }

    /**
     * queued → sent → delivered → read → acknowledged, per Blueprint §7.
     *
     * DELIVERED AND READ COME FROM THE PROVIDER WEBHOOKS and are absent until
     * they arrive — which for Teams, Slack and email is never. The funnel says
     * so rather than showing a step that will always be zero, because a crisis
     * manager reading "0 delivered" would conclude the dispatch had failed.
     *
     * @param  Collection<int, AlertRecipient>  $recipients
     * @return array<string, mixed>
     */
    private function funnel(Alert $alert, Collection $recipients): array
    {
        $deliveries = NotificationDelivery::query()
            ->where('alert_id', $alert->getKey())
            ->get(['status', 'channel', 'delivered_at', 'read_at']);

        $count = fn (DeliveryStatus ...$statuses) => $deliveries
            ->filter(fn (NotificationDelivery $d) => in_array($d->status, $statuses, true))
            ->count();

        $tracked = $deliveries->filter(fn (NotificationDelivery $d) => $d->delivered_at !== null)->count();

        return [
            'messages' => $deliveries->count(),
            'queued' => $count(DeliveryStatus::Queued),
            'sent' => $count(DeliveryStatus::Sent, DeliveryStatus::Delivered, DeliveryStatus::Read),
            'delivered' => $count(DeliveryStatus::Delivered, DeliveryStatus::Read),
            'read' => $count(DeliveryStatus::Read),
            'failed' => $count(DeliveryStatus::Failed),
            'acknowledged' => $recipients->filter(fn (AlertRecipient $r) => $r->acknowledged_at !== null)->count(),
            // Honest about what the funnel can and cannot see.
            'delivery_receipts_available' => $tracked > 0,
            'note' => $tracked === 0
                ? 'No delivery receipts have arrived yet. Email, Teams and Slack never report one, so '
                    .'"sent" is the last step those channels can confirm.'
                : null,
        ];
    }

    /**
     * @param  Collection<int, AlertRecipient>  $answered
     */
    private function headcountMinutes(Alert $alert, Collection $answered): ?int
    {
        if ($alert->dispatched_at === null || $answered->isEmpty()) {
            return null;
        }

        $last = $answered->map(fn (AlertRecipient $r) => $r->acknowledged_at)->filter()->max();

        return $last === null
            ? null
            : max(0, (int) round($alert->dispatched_at->diffInMinutes($last, absolute: true)));
    }
}

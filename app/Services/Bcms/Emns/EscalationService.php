<?php

namespace App\Services\Bcms\Emns;

use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\RecipientStatus;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\Contact;
use App\Services\Bcms\ContactResolver;
use App\Services\Bcms\Notification\ChannelRegistry;
use Illuminate\Support\Collection;

/**
 * Chasing the people who did not answer — the per-recipient channel ladder of
 * Blueprint §7, and criterion 6's "unresponsive staff auto-escalate to their
 * managers".
 *
 * THE LADDER CLIMBS AWAY FROM THE PERSON, NOT TOWARDS THEM AGAIN. They have
 * already had the alert on every channel they have. A seventh copy is what
 * teaches somebody to ignore the sixth, and it is the same fatigue argument
 * Phase 5 made about the T-2 escalation going to the manager rather than to the
 * latecomer. What escalation buys is a DIFFERENT PERSON who can physically go
 * and look, which during an evacuation is the only thing that helps.
 *
 * THE MANAGER GETS A LIST, NOT A COPY OF THE ALERT. "Evacuate now" forwarded to
 * a line manager tells them nothing they do not know. "Four of your team have
 * not checked in: Amina, Chidi, Musa, Halima" is the message that produces an
 * action, and it is one message per manager rather than one per missing person
 * — a manager with nine unaccounted-for staff must not get nine texts while
 * trying to find them.
 *
 * IT NEVER ESCALATES A SIMULATION TO A REAL MANAGER. An exercise that had a
 * branch manager ringing round at 3am because a drill went unanswered would be
 * the last exercise that bank ran.
 */
class EscalationService
{
    public function __construct(
        private ContactResolver $contacts,
        private ChannelRegistry $channels,
    ) {}

    /**
     * Escalate everybody who has not answered inside the window.
     *
     * @return array{escalated: int, managers_notified: int, no_manager: int}
     */
    public function escalateUnacknowledged(Alert $alert): array
    {
        $window = max(1, (int) ($alert->ack_window_minutes ?: 30));
        $cutoff = ($alert->dispatched_at ?? now())->copy()->addMinutes($window);

        if ($cutoff->isFuture()) {
            return ['escalated' => 0, 'managers_notified' => 0, 'no_manager' => 0];
        }

        /** @var Collection<int, AlertRecipient> $silent */
        $silent = AlertRecipient::query()
            ->where('alert_id', $alert->getKey())
            ->whereNull('acknowledged_at')
            ->whereNull('escalated_at')
            ->whereNot('status', RecipientStatus::Failed->value)
            ->with(['contact.manager'])
            ->get();

        if ($silent->isEmpty()) {
            return ['escalated' => 0, 'managers_notified' => 0, 'no_manager' => 0];
        }

        $byManager = [];
        $noManager = 0;

        foreach ($silent as $recipient) {
            $manager = $this->managerContactFor($recipient);

            if ($manager === null) {
                // Recorded rather than escalated. Somebody with no manager on
                // the roster is a contact-data gap, and the crisis screen has
                // to show them as unaccounted-for-and-nobody-was-told rather
                // than quietly dropping them.
                $recipient->forceFill([
                    'status' => RecipientStatus::Escalated->value,
                    'escalated_at' => now(),
                ])->save();

                $noManager++;

                continue;
            }

            $byManager[(int) $manager->getKey()]['manager'] = $manager;
            $byManager[(int) $manager->getKey()]['people'][] = $recipient;
        }

        $notified = 0;

        foreach ($byManager as $entry) {
            $this->notifyManager($alert, $entry['manager'], $entry['people']);
            $notified++;

            foreach ($entry['people'] as $recipient) {
                $recipient->forceFill([
                    'status' => RecipientStatus::Escalated->value,
                    'escalated_at' => now(),
                    'escalated_to_contact_id' => $entry['manager']->getKey(),
                ])->save();
            }
        }

        $alert->recordAudit('alert.escalated', [
            'unanswered' => $silent->count(),
            'managers_notified' => $notified,
            'without_a_manager' => $noManager,
            'window_minutes' => $window,
        ]);

        return [
            'escalated' => $silent->count(),
            'managers_notified' => $notified,
            'no_manager' => $noManager,
        ];
    }

    /**
     * The contact record for somebody's line manager.
     *
     * `manager_user_id` points at a USER — that is the shape a directory export
     * gives — and the manager has to be resolved back to a CONTACT before they
     * can be reached, because a user has a login and a contact has a phone
     * number. Never query `users` for a channel (the G0 contract).
     */
    private function managerContactFor(AlertRecipient $recipient): ?Contact
    {
        $managerUserId = $recipient->contact?->manager_user_id;

        if ($managerUserId === null) {
            return null;
        }

        $manager = Contact::query()
            ->where('user_id', $managerUserId)
            ->where('is_active', true)
            ->first();

        // A manager who is themselves an unanswered recipient of this alert is
        // no use as an escalation target — they are already being looked for.
        if ($manager === null || (int) $manager->getKey() === (int) $recipient->contact_id) {
            return null;
        }

        return $manager;
    }

    /**
     * One message per manager, naming everybody they are missing.
     *
     * @param  list<AlertRecipient>  $people
     */
    private function notifyManager(Alert $alert, Contact $manager, array $people): void
    {
        $names = array_values(array_filter(array_map(
            fn (AlertRecipient $r) => $r->contact_name_snapshot,
            $people,
        )));

        $body = sprintf(
            '%d of your team have not checked in after "%s": %s. Please account for them.',
            count($names),
            (string) $alert->title,
            implode(', ', array_slice($names, 0, 12)).(count($names) > 12 ? ' and '.(count($names) - 12).' more' : ''),
        );

        $message = new \App\Contracts\Bcms\RenderedMessage(
            body: $body,
            subject: 'Unaccounted for: '.$alert->title,
            locale: $manager->preferred_language ?: 'en',
            severity: $alert->severity,
            // A simulation escalation stays a simulation, so the prefix is
            // applied and the adapters below never reach a real manager.
            isSimulation: (bool) $alert->is_simulation,
            responseRequired: false,
        );

        // A simulation stops here. The recipient rows are still marked
        // escalated, so the operator sees exactly what a real run would have
        // done — but no branch manager is woken at 3am by a drill, which is the
        // fastest way to lose a bank's willingness to exercise at all.
        if ($alert->is_simulation) {
            return;
        }

        $isLifeSafety = $alert->severity === AlertSeverity::LifeSafety;
        $usable = $this->contacts->channelsFor($manager, $this->escalationChannels(), $isLifeSafety);

        if ($usable === []) {
            return;
        }

        // The first channel that can reach them, not all of them: a manager who
        // is about to go looking for four people does not need the same list
        // three times.
        $this->channels->for($usable[0])->send($this->contacts->recipient($manager), $message);
    }

    /**
     * @return list<ChannelKey>
     */
    private function escalationChannels(): array
    {
        // Voice first: a manager being told four people are missing should have
        // their phone ring, not buzz.
        return [ChannelKey::Voice, ChannelKey::Sms, ChannelKey::WhatsApp, ChannelKey::Email];
    }
}

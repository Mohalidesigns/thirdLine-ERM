<?php

namespace App\Services\Bcms;

use App\Contracts\Bcms\Recipient;
use App\Enums\Bcms\ChannelKey;
use App\Models\Bcms\Contact;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Person → channel resolution. THE ONLY PLACE THIS HAPPENS
 * (Orchestration §5).
 *
 * "Never query `users` directly for a channel" is a contract three tracks share
 * and it needs somewhere to be true. A user has a name and a login; the phone
 * number, the WhatsApp handle, the language, the consent state and the
 * verification date are on a contact, and every one of those is personal data
 * under the NDPA with its own lawful basis.
 *
 * CONSENT REMOVES CHANNELS, NOT PEOPLE. A contact who has withdrawn consent for
 * personal-phone contact is still in every audience and still counted in a
 * roll-call — they are reachable on a corporate email, and dropping them from
 * the audience would silently understate a headcount, which in an evacuation
 * means somebody is not looked for. `channelsFor()` is where the withdrawal
 * takes effect.
 *
 * THE CORPORATE / PERSONAL SPLIT IS THE WHOLE POINT. An email account the bank
 * issues and a Teams identity the bank administers need no consent: they are
 * work channels for a work purpose. A personal mobile number does, and the
 * withdrawal must bite on it and only on it.
 */
class ContactResolver
{
    /**
     * Channels that carry personal contact details and therefore need consent.
     *
     * @var list<ChannelKey>
     */
    private const CONSENTED_CHANNELS = [
        ChannelKey::Sms, ChannelKey::Voice, ChannelKey::WhatsApp, ChannelKey::Ussd,
    ];

    public function forUser(User $user): ?Contact
    {
        return Contact::query()->where('user_id', $user->getKey())->first();
    }

    /**
     * @param  iterable<int, User>  $users
     * @return Collection<int, Contact> keyed by user id
     */
    public function forUsers(iterable $users): Collection
    {
        $ids = collect($users)->map(fn (User $u) => $u->getKey())->filter()->all();

        if ($ids === []) {
            return collect();
        }

        return Contact::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');
    }

    public function recipient(Contact $contact): Recipient
    {
        return Recipient::fromContact($contact);
    }

    /**
     * The channels this contact can actually be reached on, in the order the
     * dispatcher should try them.
     *
     * ORDER COMES FROM THREE PLACES, in this precedence: the contact's own
     * preference, then the requested set, then nothing. A preference that names
     * a channel the contact has no address for is skipped rather than
     * attempted — recording a failure against a channel that was never tried
     * makes the delivery audit a lie about what happened.
     *
     * @param  list<ChannelKey>  $requested
     * @return list<ChannelKey>
     */
    public function channelsFor(Contact $contact, array $requested, bool $isLifeSafety = false): array
    {
        $available = array_values(array_filter(
            $requested,
            fn (ChannelKey $c) => $this->canReach($contact, $c, $isLifeSafety)
        ));

        $preferences = array_values(array_filter(
            array_map(
                fn ($v) => is_string($v) ? ChannelKey::tryFrom($v) : null,
                (array) ($contact->channel_preferences ?? [])
            )
        ));

        if ($preferences === []) {
            return $available;
        }

        $preferred = array_values(array_filter($preferences, fn (ChannelKey $c) => in_array($c, $available, true)));
        $rest = array_values(array_filter($available, fn (ChannelKey $c) => ! in_array($c, $preferred, true)));

        return array_merge($preferred, $rest);
    }

    /**
     * Can this contact be reached on this channel at all?
     *
     * TWO REASONS FOR NO, AND THEY ARE DIFFERENT. No address means the channel
     * was never possible. Withdrawn consent means it was possible and the
     * person said not to — except for life-safety traffic, where the NDPA's own
     * vital-interests basis applies and the bank's duty of care does not pause
     * for a marketing preference. The exception is deliberately narrow: it is
     * `is_life_safety` traffic only, it is recorded on the delivery row, and it
     * is written down in `docs/compliance/ndpa-register.md` rather than left as
     * an engineer's judgement.
     */
    public function canReach(Contact $contact, ChannelKey $channel, bool $isLifeSafety = false): bool
    {
        if (! $contact->is_active) {
            return false;
        }

        if (Recipient::fromContact($contact)->addressFor($channel) === null) {
            return false;
        }

        if (! in_array($channel, self::CONSENTED_CHANNELS, true)) {
            return true;
        }

        if ($contact->consent_status === 'withdrawn') {
            return $isLifeSafety;
        }

        return true;
    }

    /**
     * Channels that reach this contact with no data network — the African
     * infrastructure case (Blueprint §7.2). A life-safety dispatch that offers
     * none of these has not been thought through.
     *
     * @return list<ChannelKey>
     */
    public function offlineChannelsFor(Contact $contact, bool $isLifeSafety = true): array
    {
        return array_values(array_filter(
            ChannelKey::offlineCapable(),
            fn (ChannelKey $c) => $this->canReach($contact, $c, $isLifeSafety)
        ));
    }
}

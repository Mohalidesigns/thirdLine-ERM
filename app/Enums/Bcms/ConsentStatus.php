<?php

namespace App\Enums\Bcms;

/**
 * NDPA consent for a contact's personal-phone channels (`bcms_contacts`).
 *
 * Covers SMS, Voice, WhatsApp and USSD only — a corporate email or a Teams
 * identity the bank administers needs no consent (`ContactResolver`). The
 * default on the column is `NotRequested`, not `Granted`: nobody has been
 * asked yet, and a mobile number an administrator typed in is not the same
 * fact as the person agreeing to be reached on it.
 *
 * THIS IS AN ALLOW-LIST, NOT A BLOCK-LIST. Only `Granted` (or a life-safety
 * dispatch, which carries its own narrow vital-interests basis) permits a
 * consented channel. `Pending` and `NotRequested` refuse exactly like
 * `Withdrawn` — a request still awaiting an answer is not an answer.
 */
enum ConsentStatus: string
{
    case NotRequested = 'not_requested';
    case Pending = 'pending';
    case Granted = 'granted';
    case Withdrawn = 'withdrawn';

    /**
     * May a consented channel (SMS, Voice, WhatsApp, USSD) be used?
     *
     * `$isLifeSafety` is the one, narrow exception — recorded on the delivery
     * row and in `docs/compliance/ndpa-register.md`, never assumed here.
     */
    public function permitsPersonalChannel(bool $isLifeSafety = false): bool
    {
        return $this === self::Granted || $isLifeSafety;
    }

    /**
     * The complement of `permitsPersonalChannel(false)` — true for every state
     * that is not an explicit grant: `Withdrawn`, yes, but `Pending` and
     * `NotRequested` too. A contact nobody has asked is not a contact who can
     * be reached, and every site that reports on why a contact was skipped
     * must use this one predicate rather than compare against `Withdrawn`
     * alone (Gate 1 retrospective, Phase 6 criterion 10).
     */
    public function blocksPersonalChannel(): bool
    {
        return ! $this->permitsPersonalChannel();
    }

    /**
     * The reason category a screen shows for why a personal channel is
     * blocked — `null` when it is not blocked at all. `'withdrawn'` reads
     * differently from `'not_requested'`/`'pending'`, because the operator's
     * next action differs: a withdrawal is respected, the other two are a
     * consent request nobody has sent yet. ONE SOURCE OF TRUTH: the
     * person-picker (`CallTreeController::candidates()`), the tree's own
     * node health (`CallTreeService::nodeHealth()`) and the cascade's settle
     * note (`CascadeEngine::consentBlockedNote()`) all read this case rather
     * than re-deriving the withdrawn/never-asked boundary each in their own
     * words.
     */
    public function blockedReason(): ?string
    {
        return $this->blocksPersonalChannel() ? $this->value : null;
    }
}

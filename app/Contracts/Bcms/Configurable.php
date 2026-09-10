<?php

namespace App\Contracts\Bcms;

/**
 * An adapter that needs credentials before it can do anything.
 *
 * SEPARATE FROM `NotificationChannel` ON PURPOSE. That interface is frozen at
 * G0 (ADR 0004) and adding a method to it would be a breaking change to every
 * consuming track — the exact thing the freeze exists to prevent. This is a
 * second, optional interface: the Phase 0 mocks do not implement it, because a
 * recording mock is always ready, and `ChannelRegistry` only asks the adapters
 * that can answer.
 *
 * The answer decides one thing: whether a channel an operator has ENABLED is
 * actually LIVE, or is still falling back to the mock while the Nigerian
 * paperwork clears. Both states are shown on the EMNS console, because a crisis
 * manager needs to know which is which before an emergency rather than during
 * one.
 */
interface Configurable
{
    public function isConfigured(): bool;
}

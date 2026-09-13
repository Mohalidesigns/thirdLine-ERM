<?php

namespace App\Contracts\Bcms;

use App\Enums\Bcms\ChannelKey;

/**
 * The one surface every notification channel implements. FROZEN AT G0 —
 * changing it after the gate is an ADR plus a broadcast to every consuming
 * track (Orchestration §5, ADR 0004).
 *
 * WHY IT IS FROZEN THIS EARLY. Orchestration §4 puts the reminder dispatcher
 * (Phase 5, weeks 5–8) nine weeks before the real adapters (Phase 7, weeks
 * 7–11), and the real adapters are blocked on paperwork engineering does not
 * control: Nigerian sender-ID registration, WhatsApp template approval and USSD
 * short-code allocation run four to ten weeks. Every channel therefore ships a
 * RECORDING MOCK in Phase 0, so Track B exercises the real persistence path —
 * write-ahead, idempotency, delivery audit — from Week 2 and Gate G1 is
 * reachable with no provider contract signed. Phase 7 swaps the binding in
 * `ChannelRegistry`; no Phase 5 code changes.
 *
 * THREE RULES ON EVERY IMPLEMENTATION, MOCK OR REAL.
 *
 *   1. `send()` is called by a job that has ALREADY written the delivery row as
 *      `queued`. Standing rule 8 — persist before you dispatch. A crash between
 *      the write and the provider call leaves a row the watchdog can find; the
 *      reverse leaves an alert nobody knows was lost.
 *
 *   2. `send()` NEVER THROWS FOR A PROVIDER FAILURE. It returns a receipt with
 *      `status = failed` and a reason. An exception means the adapter itself is
 *      broken. Failover between gateways is decided by the caller reading
 *      receipts, not by an adapter catching its own errors — an adapter that
 *      retries internally makes the delivery audit a lie about how many
 *      attempts were made.
 *
 *   3. NO ADAPTER THROTTLES, BATCHES OR DEFERS. Quiet hours and rate limits are
 *      the dispatcher's decisions, made against `AlertSeverity`, and life-safety
 *      traffic is exempt from all of them (standing rule 6). An adapter that
 *      quietly holds a message back is a roll-call that arrives after the fire.
 */
interface NotificationChannel
{
    public function key(): ChannelKey;

    /** The provider this adapter talks to, as recorded on the delivery row. */
    public function provider(): string;

    /**
     * Does this recipient have an address this channel can use?
     *
     * False is not a failure. It is the dispatcher's signal to fall through to
     * the next channel in the set rather than record an attempt that was never
     * made.
     */
    public function supports(Recipient $to): bool;

    public function send(Recipient $to, RenderedMessage $message): DeliveryReceipt;

    /**
     * What one send would cost, in minor units, or NULL if this channel cannot
     * price it. Never zero as a stand-in for "don't know".
     */
    public function estimateCostMinor(Recipient $to, RenderedMessage $message): ?int;
}

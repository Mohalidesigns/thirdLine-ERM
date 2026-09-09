# ADR 0004 — The `NotificationChannel` interface and the Phase 0 mock adapters

**Status:** Accepted · **Date:** 2026-09-07 · **Phase:** BCMS Phase 0 · **Author:** architect
**Consumers:** Track B (P5 dispatcher), Track C (P7 real adapters — sole owner), Track D (P10), Track E (P12 USSD)

## Context

Orchestration §4 puts the reminder dispatcher (Phase 5, Track B, weeks 5–8) nine
weeks before the real channel adapters (Phase 7, Track C, weeks 7–11), and the
real adapters are blocked on paperwork that is outside engineering: Nigerian
sender-ID registration, WhatsApp Business template approval and USSD short-code
allocation run four to ten weeks (Orchestration §9). If Track B builds against
anything real it does not ship until Week 11, and Gate G1 — the T-10 countdown
demo, one of the two moments that close deals — moves with it.

## Decision

**One interface, frozen at G0, with a recording mock for every channel.**

```php
interface NotificationChannel
{
    public function key(): ChannelKey;                 // sms|whatsapp|voice|email|teams|slack|push|ussd
    public function supports(Recipient $to): bool;     // does this contact have an address for me?
    public function send(Recipient $to, RenderedMessage $message): DeliveryReceipt;
    public function estimateCostMinor(Recipient $to, RenderedMessage $message): ?int;
}
```

`Recipient`, `RenderedMessage`, `DeliveryReceipt` and `ChannelKey` are the
frozen value objects. `DeliveryReceipt` carries `status`, `provider`,
`provider_message_id`, `failed_reason`, `cost_minor` and the raw provider
response — the exact column set of `bcms_notification_deliveries`, so that
persisting a receipt is a field copy and not a translation.

**Every channel ships a mock in Phase 0** (`Mock{Sms,WhatsApp,Voice,Email,
Teams,Slack,Push,Ussd}Channel`). A mock writes a real
`bcms_notification_deliveries` row and dispatches nothing externally. Tracks
B, C and D therefore exercise the real persistence path — write-ahead,
idempotency, delivery audit — from Week 2. Phase 7 swaps the binding in
`ChannelRegistry`; **no Phase 5 code changes**, which is what makes Track B
independent of the paperwork.

Three constraints on every implementation, mock or real:

1. **Persist before you dispatch** (standing rule 8). `send()` is called by a
   job that has *already* written the delivery row in `queued`. A worker crash
   between write and provider call leaves a `queued` row the watchdog can find;
   the reverse leaves an alert nobody knows was lost.
2. **`send()` never throws for a provider failure.** It returns a receipt with
   `status = failed` and a reason. An exception means the adapter itself is
   broken. Failover between gateways (Phase 7) is decided by the caller reading
   receipts, not by an adapter catching its own errors.
3. **Life-safety traffic is a queue, not a flag.** `bcms-lifesafety` (ADR 0005)
   is chosen by the *dispatcher*, and no adapter is permitted to throttle,
   batch or quiet-hours-defer anything (standing rule 6).

`estimateCostMinor` returns `null`, not `0`, when a channel cannot price a
send. Zero is a claim that it is free.

## Consequences

- Gate G1 is reachable in Week 8 with no provider contract signed.
- A mock that silently succeeded would let a broken audit path pass QA, so
  `MockChannel` honours an injected failure rate and the test suite uses it to
  exercise the failed branch.
- The interface is a *cross-track contract*: changing it after G0 is an ADR
  plus a broadcast, per Orchestration §5.

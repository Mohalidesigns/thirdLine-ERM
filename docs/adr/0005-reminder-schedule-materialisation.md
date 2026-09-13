# ADR 0005 — Materialising the reminder schedule, and the four queues

**Status:** Accepted · **Date:** 2026-09-07 · **Phase:** BCMS Phase 0 · **Author:** reliability-engineer
**Consumers:** Track B (P4, P5), Track C (P7), Track D (P10)

## Context

Blueprint §5.4 is the module's headline requirement: for the ten days before an
exercise, one alert per participant per day. Gate G1 states it as a test —
"10 consecutive daily alerts, one per participant per day; T-2 escalation
fires; **dispatcher re-run sends nothing twice**".

There are two ways to build that. Compute the due reminders on each hourly tick
from the occurrence date, or materialise a row per intended send when the
occurrence is generated. The first is less storage and is wrong: nothing
records that a send was skipped, a rescheduled occurrence cannot be reconciled
against what was already sent, and "re-run sends nothing twice" becomes a
property of the query rather than of the data.

## Decision

**Materialise.** `bcms_reminder_schedules` holds one row per intended send,
written when the occurrence is generated (Phase 4), dispatched by an hourly
command (Phase 5).

- `idempotency_key` is `UNIQUE` and is
  `sha1(occurrence_id|day_offset|audience_hash|template_key|mode)`. The
  uniqueness is enforced **by the database**, not by a check-then-insert: two
  workers racing on the same tick must lose one insert, not both succeed.
- `status` moves `pending → sent | skipped | voided`. Rescheduling an
  occurrence **voids** unsent rows and writes a new ladder; it never mutates
  them, so the audit trail shows what was planned as well as what happened.
- A row whose `send_at` has passed and is still `pending` is a **defect the
  watchdog reports**, not a row to quietly drop.
- One digest per user per day (standing rule 7) is a property of dispatch, not
  of materialisation: rows are per occurrence, and the dispatcher coalesces per
  contact per day when `mode = digest`.

**Four Redis queues, four Horizon supervisors**, sized separately because the
jobs have genuinely different shapes and one pool would let the slowest starve
the rest — the same reasoning `config/horizon.php` already records for WP-07:

| Queue | What is on it | Why it is separate |
|---|---|---|
| `bcms-lifesafety` | "Are you safe?" roll-calls, evacuation, crisis activation | Never throttled, never quiet-hours-deferred, never behind routine traffic. Workers stay warm. Standing rule 6. |
| `bcms-alerts` | EMNS dispatch, acknowledgement chasing, escalation | Bursty: a 5,000-recipient dispatch must not delay a reminder tick for hours. |
| `bcms-reminders` | The T-10 ladder, readiness nags, review-due notices | High volume, low urgency, retried generously. |
| `bcms-sync` | AD/Entra/SCIM sync, contact hygiene, cost reconciliation | Long, single-attempt, nobody watching. |

Gate G0 criterion 7 is the test of the separation: a job on `bcms-lifesafety`
is picked up while `bcms-sync` is backed up with 10,000 jobs.

## Consequences

- Phase 4 must write the ladder at generation time; an occurrence with no
  reminder rows is an incomplete generation, and the schema-verify command
  says so.
- Storage: 10 rows per participant-occurrence. A 200-person, 40-exercise year
  is 80,000 rows. Immaterial against what it buys.
- `bcms_notification_deliveries` is polymorphic over alert *and* reminder,
  which is why it carries both `alert_id` and `reminder_schedule_id` nullable
  rather than a morph: two nullable FKs are checkable by the database, a morph
  pair is not.

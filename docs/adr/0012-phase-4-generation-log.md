# ADR 0012 — One column for the generation log, and the two conflict sources this product does not have

**Status:** Accepted · **Date:** 2026-09-08 · **Phase:** BCMS Phase 4 · **Author:** architect
**Requested by:** architect (lead, Phase 4) · **Broadcast to:** Tracks A, C, D, E

## Context

The fourth ADR against the schema freeze and the smallest yet: **one column, on
one table.** Phase 0 specified the exercise engine unusually well — the ladder,
`needs_scheduling`, `reschedule_count`, `originally_scheduled_date`,
`blackout_overrides`, `preferred_window`, `mandatory`, `unannounced`,
`min_notice_days` and the recurrence grammar on `bcms_blackout_periods` are all
there, and the generator needs none of them added.

What is missing is somewhere for the generator to say **why** it did what it did.

## Decision

### `bcms_exercise_definitions`

| Column | Why |
|---|---|
| `generation_log` (json, nullable) | Acceptance criterion 3 requires that when an occurrence is shifted for a participant conflict, "the shift is **visible in the generation log**", and criterion 4 that a blacked-out segment produces a visible `needs_scheduling` occurrence rather than a silent drop. Both are claims about the *reasoning*, not the result, and the result cannot carry them: a date on a calendar does not say which date it wanted or what pushed it. Without this the log exists only in the HTTP response of the run that produced it, which nobody has when the question is asked three weeks later. |

The log holds the last run only, and a regeneration replaces it. That is the
correct lifetime: it explains the occurrences that currently exist, and the
previous run's decisions were superseded by definition.

## What this ADR deliberately does not add

**A per-occurrence scheduling note.** The definition-level log already names
every occurrence it placed, shifted or could not place; a second copy on each
row would be the same sentence stored twice and free to disagree.

**A reschedule audit table.** `BcmsAuditable` already writes a diff of every
change to `bcms_exercise_occurrences` — including `scheduled_date` — and
`reschedule_count` and `originally_scheduled_date` are columns Phase 0 provided
for exactly this. The *justification* and the *approval* ride on the platform's
existing `ApprovalRequest`, which is what "routed to the programme owner" means
in a product that already has an approvals engine.

**An ICS token column.** `URL::signedRoute()` gives a per-user, tamper-evident
feed URL with no stored secret to leak, rotate or forget. A token column would be
a credential in a database whose only advantage is that it can be revoked
individually — and a signed route can be revoked by rotating the app key or by
adding an expiry, neither of which needs a column today.

**Anything for public holidays.** The Nigerian fixed holidays are rows in
`bcms_blackout_periods`, seeded in Phase 0. Adding a holidays table would be a
second calendar for the generator to consult and for a customer to maintain.

## The two conflict sources this product does not have, stated rather than faked

The phase prompt lists five conflict sources. Three are real here: overlapping
participants, the same site, and organisation-wide blackouts. Two are not:

**Audit engagements from thirdLine.** This product *is* thirdLine, and it has no
audit engagement register — `issues` carries `issue_source = 'audit'` for
findings that came out of an audit, which is the audit's *output*, not its
diary. There is nothing to collide with. `ConflictDetector` names the seam and
returns nothing for it; when an engagement register exists, that is the one
method to fill in. Inventing a table so the criterion could be ticked would put
a register in the product that nobody writes to, and an empty conflict source
that looks implemented is worse than one that says it is not.

**System change-freeze windows.** These are already expressible, and the right
answer is that they are blackout periods — `bcms_blackout_periods` with
`category = 'custom'` and a fixed window, which the generator honours like any
other. A separate change-freeze table would be the blackout calendar with a
different name.

## Consequences

- The manifest is regenerated in the same commit, as ADR 0008 established.
- Four ADRs in five phases: fifteen changes, then eight, then five, now one. The
  freeze is holding and the trend is the right way round.
- No column another track reads has changed. Nothing needs rebasing.
- Phase 5 arms its reminder ladder from the `ExerciseOccurrenceScheduled` event,
  not from this log. The log is for humans.
